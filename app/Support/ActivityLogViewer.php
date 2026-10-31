<?php

namespace App\Support;

use App\Models\ActivityLog as Row;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Everything the Activity Log page and its CSV export need: the filters read from the query string
 * (all server side, so the URL is bookmarkable), the query, the tab counts, the summary cards and the
 * "Open Item" links. Reads only the activity_logs table.
 */
final class ActivityLogViewer
{
    public const PER_PAGE = 12;
    public const EXPORT_CAP = 5000;

    public const TABS = ['all' => 'All'] + ActivityLog::CATEGORIES;
    public const WHAT = ['' => 'Anything', 'signin' => 'Signed In', 'signout' => 'Signed Out', 'action' => 'Admin Actions'];
    public const WHO = ['' => 'Everyone', 'admin' => 'Admin Team', 'customer' => 'Customers'];
    public const TOD = [
        ''          => 'Any Time',
        'night'     => 'Late Night (12 AM to 5 AM)',
        'morning'   => 'Morning (5 AM to 12 PM)',
        'afternoon' => 'Afternoon (12 PM to 6 PM)',
        'evening'   => 'Evening (6 PM to 12 AM)',
    ];
    public const SORTS = ['new' => 'Newest First', 'old' => 'Oldest First', 'az' => 'Person A To Z', 'za' => 'Person Z To A', 'type' => 'Type'];

    /** Hours (inclusive start, exclusive end) of each time-of-day choice. */
    private const HOURS = ['night' => [0, 5], 'morning' => [5, 12], 'afternoon' => [12, 18], 'evening' => [18, 24]];

    /** Alphabetical by the label shown, which is how the "Type" sort reads. */
    private const TYPE_ORDER = ['catalog', 'settings', 'orders', 'people', 'signin'];

    private const KEYS = ['tab', 'q', 'person', 'what', 'who', 'from', 'to', 'tod', 'sort', 'page'];

    public static function today(): Carbon
    {
        return now()->startOfDay();
    }

    public static function minDate(): Carbon
    {
        return self::today()->subDays(ActivityLog::RETENTION_DAYS);
    }

    /** @return array{tab:string,q:string,person:int,what:string,who:string,from:?string,to:?string,tod:string,sort:string} */
    public static function filters(array $in): array
    {
        $str = fn ($k) => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $pick = fn ($k, array $allowed, string $default) => in_array($str($k), array_map('strval', array_keys($allowed)), true) ? $str($k) : $default;

        $from = self::date($str('from'));
        $to = self::date($str('to'));
        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'tab'    => $pick('tab', self::TABS, 'all'),
            'q'      => mb_substr($str('q'), 0, 100),
            'person' => ctype_digit($str('person')) ? (int) $str('person') : 0,
            'what'   => $pick('what', self::WHAT, ''),
            'who'    => $pick('who', self::WHO, ''),
            'from'   => $from?->format('Y-m-d'),
            'to'     => $to?->format('Y-m-d'),
            'tod'    => $pick('tod', self::TOD, ''),
            'sort'   => $pick('sort', self::SORTS, 'new'),
        ];
    }

    /** A real Y-m-d date inside the 180-day window (earlier is lifted to the first day, later to today). */
    private static function date(string $v): ?Carbon
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        try {
            $d = Carbon::createFromFormat('!Y-m-d', $v);
        } catch (\Throwable) {
            return null;
        }
        if (!$d || $d->format('Y-m-d') !== $v) {
            return null;
        }

        return $d->lt(self::minDate()) ? self::minDate() : ($d->gt(self::today()) ? self::today() : $d);
    }

    /** The query string for a view: defaults are left out; $over replaces or (with null) drops a key. */
    public static function queryFor(array $f, array $over = []): array
    {
        $q = array_merge($f, $over);
        $defaults = ['tab' => 'all', 'q' => '', 'person' => 0, 'what' => '', 'who' => '', 'from' => null, 'to' => null, 'tod' => '', 'sort' => 'new'];
        $out = [];
        foreach ($defaults as $k => $d) {
            $v = $q[$k] ?? $d;
            if ($v === null || $v === '' || $v === $d) {
                continue;
            }
            $out[$k] = $v;
        }

        return $out;
    }

    public static function query(array $f, bool $withTab = true): Builder
    {
        $q = Row::query();

        if ($withTab && $f['tab'] !== 'all') {
            $q->where('category', $f['tab']);
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '\\%_') . '%';
            $q->where(fn ($w) => $w->where('actor_name', 'like', $like)->orWhere('description', 'like', $like)->orWhere('subject_label', 'like', $like));
        }
        if ($f['person'] > 0) {
            $q->where('user_id', $f['person']);
        }
        if ($f['what'] === 'action') {
            $q->where('kind', 'action');
        } elseif ($f['what'] !== '') {
            $q->where('kind', $f['what']);
        }
        if ($f['who'] !== '') {
            $q->where('role', $f['who']);
        }
        if ($f['from']) {
            $q->where('created_at', '>=', Carbon::createFromFormat('!Y-m-d', $f['from'])->startOfDay());
        }
        if ($f['to']) {
            $q->where('created_at', '<=', Carbon::createFromFormat('!Y-m-d', $f['to'])->endOfDay());
        }
        if ($f['tod'] !== '') {
            [$a, $b] = self::HOURS[$f['tod']];
            $q->whereRaw('HOUR(created_at) >= ? and HOUR(created_at) < ?', [$a, $b]);
        }

        return $q;
    }

    public static function sorted(Builder $q, string $sort): Builder
    {
        match ($sort) {
            'old'  => $q->orderBy('created_at')->orderBy('id'),
            'az'   => $q->orderBy('actor_name')->orderByDesc('created_at')->orderByDesc('id'),
            'za'   => $q->orderByDesc('actor_name')->orderByDesc('created_at')->orderByDesc('id'),
            'type' => $q->orderByRaw("FIELD(category, '" . implode("','", self::TYPE_ORDER) . "')")->orderByDesc('created_at')->orderByDesc('id'),
            default => $q->orderByDesc('created_at')->orderByDesc('id'),
        };

        return $q;
    }

    /** Header link target: When toggles new/old, Who toggles az/za, Type is one order. */
    public static function headerSort(string $current, string $column): string
    {
        return match ($column) {
            'when' => $current === 'new' ? 'old' : 'new',
            'who'  => $current === 'az' ? 'za' : 'az',
            default => 'type',
        };
    }

    public static function sortColumn(string $sort): string
    {
        return ['new' => 'when', 'old' => 'when', 'az' => 'who', 'za' => 'who', 'type' => 'type'][$sort] ?? 'when';
    }

    /** @return array<string,int> tab key => count; every other active filter applies */
    public static function tabCounts(array $f): array
    {
        $by = self::query($f, false)->selectRaw('category, count(*) as n')->groupBy('category')->pluck('n', 'category');
        $out = ['all' => (int) $by->sum()];
        foreach (ActivityLog::CATEGORIES as $k => $_) {
            $out[$k] = (int) ($by[$k] ?? 0);
        }

        return $out;
    }

    /** @return list<array{label:string,value:int,tone:string}> */
    public static function summary(): array
    {
        $today = self::today();
        $week = self::today()->subDays(6);

        $signInsToday = Row::where('kind', 'signin')->where('created_at', '>=', $today)->count();
        $adminsToday = Row::where('role', 'admin')->where('created_at', '>=', $today)->whereNotNull('user_id')->distinct()->count('user_id');
        $actionsToday = Row::where('role', 'admin')->where('kind', 'action')->where('created_at', '>=', $today)->count();
        $customersWeek = Row::where('role', 'customer')->where('kind', 'signin')->where('created_at', '>=', $week)->whereNotNull('user_id')->distinct()->count('user_id');

        return [
            ['label' => 'Sign-Ins Today', 'value' => $signInsToday, 'tone' => 'ok'],
            ['label' => 'Admins Active Today', 'value' => $adminsToday, 'tone' => 'bl'],
            ['label' => 'Admin Actions Today', 'value' => $actionsToday, 'tone' => 'lo'],
            ['label' => 'Customers Signed In This Week', 'value' => $customersWeek, 'tone' => ''],
        ];
    }

    /** People who appear in the log: user id => latest name. */
    public static function people(): Collection
    {
        return Row::whereNotNull('user_id')->orderBy('id')->get(['user_id', 'actor_name'])
            ->mapWithKeys(fn ($r) => [$r->user_id => $r->actor_name])
            ->sort(fn ($a, $b) => strcasecmp($a, $b));
    }

    /** "Today", "Yesterday" or "Oct 29, 2026". */
    public static function dayLabel(Carbon $t): string
    {
        if ($t->isToday()) {
            return 'Today';
        }
        if ($t->isYesterday()) {
            return 'Yesterday';
        }

        return $t->format('M j, Y');
    }

    /** 12:00 AM to 4:59 AM. */
    public static function isLate(Carbon $t): bool
    {
        return $t->hour < 5;
    }

    public static function categoryLabel(string $category): string
    {
        return ActivityLog::CATEGORIES[$category] ?? ucfirst($category);
    }

    /** Chip class per category, as in the prototype. */
    public static function typeClass(string $category): string
    {
        return ['signin' => 'e-in', 'orders' => 'e-ord', 'catalog' => 'e-cat', 'people' => 'e-ppl', 'settings' => 'e-set'][$category] ?? 'e-set';
    }

    /**
     * Where "Open Item" goes for each row id (only when the page exists and, for a record, the record still exists).
     *
     * @param  Collection<int,Row> $rows
     * @return array<int,string> row id => relative url
     */
    public static function links(Collection $rows): array
    {
        $models = [
            'order'              => [\App\Models\Order::class, fn ($id) => route('admin.orders.show', $id, false)],
            'product'            => [\App\Models\Product::class, fn ($id) => route('admin.products.index', ['edit' => $id], false)],
            'inventory'          => [\App\Models\Inventory::class, fn ($id) => route('admin.inventory.index', ['edit' => $id], false)],
            'materialestimate'   => [\App\Models\MaterialEstimate::class, fn ($id) => route('admin.material-estimates.index', ['edit' => $id], false)],
            'employee'           => [\App\Models\Employee::class, fn ($id) => route('admin.employees.index', [], false)],
            'user'               => [\App\Models\User::class, fn ($id) => route('admin.customers.show', $id, false)],
            'serviceinquiry'     => [\App\Models\ServiceInquiry::class, fn ($id) => route('admin.service-inquiries.show', $id, false)],
            'servicerefundrequest' => [\App\Models\ServiceRefundRequest::class, fn ($id) => route('admin.refunds.show', $id, false)],
            'unansweredidea'     => [\App\Models\UnansweredIdea::class, fn ($id) => route('admin.ideas.index', [], false)],
        ];
        $pages = [
            'settings' => fn () => route('admin.settings.contact.edit', [], false),
            'luma'     => fn () => route('admin.chatbot.index', ['tab' => 'unanswered'], false),
        ];

        $out = [];
        foreach ($rows->groupBy('subject_type') as $type => $group) {
            if (isset($pages[$type])) {
                foreach ($group as $r) {
                    $out[$r->id] = ($pages[$type])();
                }
                continue;
            }
            if (!isset($models[$type])) {
                continue;
            }
            [$class, $url] = $models[$type];
            $ids = $group->pluck('subject_id')->filter()->unique()->all();
            if (!$ids) {
                continue;
            }
            $alive = $class::whereIn((new $class)->getKeyName(), $ids)->pluck((new $class)->getKeyName())->all();
            foreach ($group as $r) {
                if ($r->subject_id && in_array($r->subject_id, $alive)) {
                    $out[$r->id] = $url($r->subject_id);
                }
            }
        }

        return $out;
    }

    /** One CSV cell: a leading = + - or @ gets a single quote in front so a spreadsheet never runs it as a formula. */
    public static function csvCell(?string $v): string
    {
        $v = (string) $v;

        return $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v;
    }
}
