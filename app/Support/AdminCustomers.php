<?php

namespace App\Support;

use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\User;
use App\Models\UserValidation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Everything the admin Customers list and Customer profile show (docs/admin/admin-menu-v1.html).
 *
 * Definitions, shared with Reports and Analytics (App\Support\AdminReports):
 *  - Orders      = every order the customer placed, cancelled ones included (it is a count of what they did).
 *  - Total spent = amount_paid (the payment ledger total on the order) of the customer's orders that are NOT cancelled.
 * The list is sorted and filtered in SQL through one grouped sub-query (no query per row); the URL keeps the state.
 */
class AdminCustomers
{
    public const PER_PAGE = 15;
    public const SORTS = [
        'joined-desc' => 'Newest Joined', 'joined-asc' => 'Oldest Joined',
        'name-asc' => 'Name A to Z', 'name-desc' => 'Name Z to A',
        'orders-desc' => 'Most Orders', 'orders-asc' => 'Fewest Orders',
        'spent-desc' => 'Highest Spent', 'spent-asc' => 'Lowest Spent',
    ];
    public const DEFAULT_SORT = 'joined-desc';
    public const ORDER_CHOICES = ['any' => 'Any', 'none' => 'No Orders', '1' => '1 or More', '10' => '10 or More'];
    public const JOINED_CHOICES = ['any' => 'Any Time', '7' => 'Last 7 Days', '30' => 'Last 30 Days', 'year' => 'This Year'];
    public const STATUS_CHOICES = ['all' => 'All', 'verified' => 'Verified', 'unverified' => 'Unverified'];
    public const SPENT_MAX = 100000000;

    /** The list's state, read from the query string; anything unknown falls back to its default. */
    public static function filtersFrom(array $q): array
    {
        $status = (string) ($q['status'] ?? $q['filter'] ?? 'all');
        $orders = (string) ($q['orders'] ?? 'any');
        $joined = (string) ($q['joined'] ?? 'any');
        $sort   = (string) ($q['sort'] ?? self::DEFAULT_SORT);
        $min = self::amount($q['min'] ?? null);
        $max = self::amount($q['max'] ?? null);
        $rangeError = $min !== null && $max !== null && $min > $max;

        return [
            'search' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($q['search'] ?? ''))), 0, 100),
            'status' => isset(self::STATUS_CHOICES[$status]) ? $status : 'all',
            'orders' => isset(self::ORDER_CHOICES[$orders]) ? $orders : 'any',
            'joined' => isset(self::JOINED_CHOICES[$joined]) ? $joined : 'any',
            'min'    => $rangeError ? null : $min,
            'max'    => $rangeError ? null : $max,
            'sort'   => isset(self::SORTS[$sort]) ? $sort : self::DEFAULT_SORT,
            // the typed bounds contradict each other: nothing is applied, and the Filters window opens with the message
            'rangeError' => $rangeError,
            'rawMin' => (string) ($q['min'] ?? ''),
            'rawMax' => (string) ($q['max'] ?? ''),
        ];
    }

    /** "10,000" / "₱10000.50" -> 10000.5; empty or no digits -> null. */
    public static function amount($v): ?float
    {
        $s = preg_replace('/[^\d.]/', '', (string) $v);
        if ($s === '' || !is_numeric($s)) {
            return null;
        }

        return min((float) $s, self::SPENT_MAX);
    }

    /** Only the parts that differ from the defaults, so the URL stays short and a shared link opens the same view. */
    public static function queryFor(array $f, array $override = []): array
    {
        $f = array_replace($f, $override);
        $q = [];
        if ($f['search'] !== '') { $q['search'] = $f['search']; }
        if ($f['status'] !== 'all') { $q['status'] = $f['status']; }
        if ($f['orders'] !== 'any') { $q['orders'] = $f['orders']; }
        if ($f['joined'] !== 'any') { $q['joined'] = $f['joined']; }
        if ($f['min'] !== null) { $q['min'] = self::plain($f['min']); }
        if ($f['max'] !== null) { $q['max'] = self::plain($f['max']); }
        if ($f['sort'] !== self::DEFAULT_SORT) { $q['sort'] = $f['sort']; }

        return $q;
    }

    private static function plain(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /** Removable chips for the active filters (search is not a chip: it has its own box). */
    public static function chips(array $f): array
    {
        $chips = [];
        if ($f['status'] !== 'all') {
            $chips[] = ['key' => 'status', 'label' => self::STATUS_CHOICES[$f['status']], 'drop' => ['status' => 'all']];
        }
        if ($f['orders'] !== 'any') {
            $chips[] = ['key' => 'orders', 'label' => $f['orders'] === 'none' ? 'No Orders' : $f['orders'] . ' or More Orders', 'drop' => ['orders' => 'any']];
        }
        if ($f['joined'] !== 'any') {
            $chips[] = ['key' => 'joined', 'label' => $f['joined'] === 'year' ? 'Joined This Year' : 'Joined in Last ' . $f['joined'] . ' Days', 'drop' => ['joined' => 'any']];
        }
        if ($f['min'] !== null || $f['max'] !== null) {
            $p = fn (float $n) => '₱' . number_format($n, $n == floor($n) ? 0 : 2);
            $chips[] = ['key' => 'spent', 'label' => 'Spent ' . ($f['min'] !== null && $f['max'] !== null ? $p($f['min']) . ' to ' . $p($f['max'])
                : ($f['min'] !== null ? $p($f['min']) . ' or more' : 'up to ' . $p($f['max']))), 'drop' => ['min' => null, 'max' => null]];
        }

        return $chips;
    }

    /** The next sort when a column header is clicked: the same column flips direction, a new column starts at its natural direction. */
    public static function headerSort(string $current, string $column): string
    {
        if (str_starts_with($current, $column . '-')) {
            return $column . '-' . (str_ends_with($current, '-asc') ? 'desc' : 'asc');
        }

        return $column === 'name' ? 'name-asc' : $column . '-desc';
    }

    /** @return Builder users (customers) + orders_count + total_spent, filtered and sorted */
    public static function listQuery(array $f): Builder
    {
        $agg = Order::query()->selectRaw("user_id, count(*) as orders_count, coalesce(sum(case when order_status <> 'cancelled' then amount_paid else 0 end), 0) as total_spent")
            ->groupBy('user_id');

        $q = User::query()->where('users.role', 'customer')
            ->leftJoinSub($agg, 'o', 'o.user_id', '=', 'users.id')
            ->select('users.*')
            ->selectRaw('coalesce(o.orders_count, 0) as orders_count, coalesce(o.total_spent, 0) as total_spent');

        if ($f['search'] !== '') {
            $like = '%' . addcslashes($f['search'], '\\%_') . '%';
            $digits = preg_replace('/\D+/', '', $f['search']);
            $q->where(function ($w) use ($like, $digits) {
                $w->where('users.first_name', 'like', $like)
                  ->orWhere('users.last_name', 'like', $like)
                  ->orWhereRaw("concat(users.first_name, ' ', users.last_name) like ?", [$like])
                  ->orWhere('users.email', 'like', $like)
                  ->orWhere('users.phone_number', 'like', $like)
                  ->orWhere('users.phone_e164', 'like', $like);
                if (strlen($digits) >= 3) {
                    // "0917 123 4567" finds 09171234567; "+63 917" finds +63917...
                    $d = '%' . $digits . '%';
                    $w->orWhere('users.phone_number', 'like', $d)->orWhere('users.phone_e164', 'like', $d);
                }
            });
        }
        if ($f['status'] === 'verified') { $q->where('users.is_validated', true); }
        if ($f['status'] === 'unverified') { $q->where('users.is_validated', false); }

        $n = 'coalesce(o.orders_count, 0)';
        if ($f['orders'] === 'none') { $q->whereRaw("$n = 0"); }
        elseif ($f['orders'] === '1' || $f['orders'] === '10') { $q->whereRaw("$n >= ?", [(int) $f['orders']]); }

        if ($f['joined'] === 'year') { $q->whereYear('users.created_at', now()->year); }
        elseif ($f['joined'] === '7' || $f['joined'] === '30') { $q->where('users.created_at', '>=', now()->subDays((int) $f['joined'])); }

        if ($f['min'] !== null) { $q->whereRaw('coalesce(o.total_spent, 0) >= ?', [$f['min']]); }
        if ($f['max'] !== null) { $q->whereRaw('coalesce(o.total_spent, 0) <= ?', [$f['max']]); }

        [$col, $dir] = explode('-', $f['sort']);
        match ($col) {
            'name'   => $q->orderBy('users.first_name', $dir)->orderBy('users.last_name', $dir),
            'orders' => $q->orderByRaw("$n $dir"),
            'spent'  => $q->orderByRaw("coalesce(o.total_spent, 0) $dir"),
            default  => $q->orderBy('users.created_at', $dir),
        };

        return $q->orderBy('users.id', $dir === 'asc' ? 'asc' : 'desc');
    }

    public static function stats(): array
    {
        $r = User::where('role', 'customer')->selectRaw('count(*) as total, coalesce(sum(is_validated = 1), 0) as verified')->first();

        return ['total' => (int) $r->total, 'verified' => (int) $r->verified, 'unverified' => (int) $r->total - (int) $r->verified];
    }

    // ───────────────────────────── profile ─────────────────────────────

    public static function profile(User $c): array
    {
        $orders = Order::where('user_id', $c->id)->with('items:id,order_id,product_name,quantity')->latest()->get();
        $live = $orders->where('order_status', '!=', 'cancelled');
        $spent = round((float) $live->sum('amount_paid'), 2);

        $requests = CustomDesignRequest::where('user_id', $c->id)->latest()->get();
        $pics = $requests->isEmpty() ? collect() : AiDesignSuggestion::whereIn('design_id', $requests->pluck('id'))->orderBy('id')->get()->groupBy('design_id');

        $validation = $c->validation;
        $activity = self::activity($c, $orders, $requests, $validation);

        return [
            'stats' => [
                'orders'  => $orders->count(),
                'spent'   => $spent,
                'designs' => $requests->count(),
                'average' => $live->count() > 0 ? round($spent / $live->count(), 2) : 0.0,
            ],
            'orders'   => $orders->take(25)->map(fn (Order $o) => self::orderRow($o))->all(),
            'ordersMore' => max(0, $orders->count() - 25),
            'designs'  => $requests->take(30)->map(fn (CustomDesignRequest $r) => self::designRow($r, $pics->get($r->id, collect())))->all(),
            'designsMore' => max(0, $requests->count() - 30),
            'activity' => $activity,
            'lastLogin' => $c->last_login_at,
            'lastActivity' => $c->last_login_at ?? $activity[0]['at'] ?? $c->created_at,
            'nameChanges' => $c->nameChanges()->get(),
            'location' => self::location($c),
            'address'  => self::address($c),
            'identity' => self::identity($c, $validation),
            'pendingValidationId' => $validation && $validation->status === 'pending' ? $validation->id : null,
        ];
    }

    private static function orderRow(Order $o): array
    {
        $paid = round((float) $o->amount_paid, 2);
        $total = round((float) $o->total_amount, 2);
        $first = $o->items->first();
        $names = $first ? ($first->product_name ?: 'Item') . ($o->items->count() > 1 ? ' + ' . ($o->items->count() - 1) . ' more' : '') : 'No items';

        return [
            'id' => $o->id, 'number' => $o->order_number, 'date' => $o->created_at, 'item' => $names, 'total' => $total, 'paid' => $paid,
            'status' => $o->order_status,
            'statusLabel' => ucwords(str_replace('_', ' ', (string) $o->order_status)),
            'pay' => $paid <= 0 ? 'unpaid' : ($paid + 0.005 >= $total ? 'paid' : 'partial'),
        ];
    }

    private static function designRow(CustomDesignRequest $r, $pics): array
    {
        $pic = $pics->firstWhere('is_selected', true) ?? $pics->first(fn ($p) => $p->isLoadable());
        $url = $pic && $pic->isLoadable() ? $pic->displayUrl() : null;
        $pipeline = $r->pipeline_status;
        if ($pipeline) {
            $label = CustomOrderPipeline::LABELS[$pipeline] ?? ucwords(str_replace('_', ' ', $pipeline));
            $link = route('admin.custom-orders.show', $r->id);
        } else {
            $label = ucwords(str_replace('_', ' ', (string) $r->status));
            $link = in_array($r->status, ['approved', 'declined'], true) ? route('admin.design-requests.show', $r->id) : null;
        }
        $type = $r->furniture_type === 'Other' && $r->furniture_type_other ? $r->furniture_type_other : $r->furniture_type;

        return [
            'id' => $r->id, 'title' => $r->title ?: ($type ?: 'Custom Design'), 'type' => $type, 'date' => $r->created_at,
            'status' => $label, 'tone' => $pipeline ? ($pipeline === 'ordered' ? 'done' : (in_array($pipeline, ['declined_by_admin', 'declined_by_customer', 'cancelled', 'expired'], true) ? 'bad' : 'wait')) : ($r->status === 'declined' || $r->status === 'rejected' ? 'bad' : 'wait'),
            'pictures' => $pics->count(), 'image' => $url, 'link' => $link,
        ];
    }

    /**
     * "San Juan, Batangas": the city of the saved delivery address (and its province). No geolocation, no IP: only what the customer typed in My Account.
     */
    private static function location(User $c): ?string
    {
        if (!$c->address_city_id) {
            return null;
        }
        $row = DB::table('cities_municipalities as c')->leftJoin('provinces as p', 'p.id', '=', 'c.province_id')->where('c.id', $c->address_city_id)->first(['c.name as city', 'p.name as province']);

        return $row ? trim($row->city . ($row->province ? ', ' . $row->province : '')) : null;
    }

    private static function address(User $c): ?string
    {
        $saved = DeliveryAddress::fromUser($c);
        if ($saved) {
            return DeliveryAddress::line($saved);
        }

        return $c->address ? trim((string) $c->address) : null;
    }

    private static function identity(User $c, ?UserValidation $v): array
    {
        if ($c->is_validated) {
            $approved = UserValidation::where('user_id', $c->id)->where('status', 'approved')->orderByDesc('attempt_number')->first();

            return ['label' => 'Verified', 'date' => $approved?->reviewed_at ?? $approved?->submitted_at, 'tone' => 'v'];
        }
        if (!$v) {
            return ['label' => 'Not Submitted', 'date' => null, 'tone' => 'u'];
        }

        return match ($v->status) {
            'pending'  => ['label' => 'Pending Review', 'date' => $v->submitted_at, 'tone' => 'u'],
            'rejected' => ['label' => 'Rejected', 'date' => $v->reviewed_at ?? $v->submitted_at, 'tone' => 'u'],
            default    => ['label' => ucfirst((string) $v->status), 'date' => $v->reviewed_at ?? $v->submitted_at, 'tone' => 'u'],
        };
    }

    /**
     * A timeline built only from rows that already exist (there is no activity log, and logins are not recorded):
     * account created, orders placed, payments received, designs made, Luma chats (the count per day, never the words),
     * identity submissions and decisions, name changes. Newest first, at most 40.
     *
     * @return list<array{at: Carbon, text: string, url: ?string}>
     */
    private static function activity(User $c, $orders, $requests, ?UserValidation $latest): array
    {
        $ev = [['at' => $c->created_at, 'text' => 'Created an account', 'url' => null]];

        foreach ($orders as $o) {
            $ev[] = ['at' => $o->created_at, 'text' => 'Placed order ' . $o->order_number, 'url' => route('admin.orders.show', $o->id)];
        }
        if ($orders->isNotEmpty()) {
            $nums = $orders->pluck('order_number', 'id');
            foreach (PaymentRecord::whereIn('order_id', $orders->pluck('id'))->latest('id')->limit(30)->get(['order_id', 'amount', 'paid_on', 'created_at']) as $p) {
                $ev[] = ['at' => Carbon::parse($p->paid_on ?? $p->created_at), 'text' => 'Paid ₱' . number_format((float) $p->amount, 2) . ' on order ' . ($nums[$p->order_id] ?? ''), 'url' => route('admin.orders.show', $p->order_id)];
            }
        }
        foreach ($requests as $r) {
            $ev[] = ['at' => $r->created_at, 'text' => 'Made a design: ' . ($r->title ?: ($r->furniture_type ?: 'Custom design')), 'url' => $r->pipeline_status ? route('admin.custom-orders.show', $r->id) : null];
        }
        foreach (DB::table('chatbot_logs')->where('user_id', $c->id)->selectRaw('date(created_at) as d, count(*) as n, max(created_at) as at')->groupBy('d')->orderByDesc('d')->limit(15)->get() as $g) {
            $ev[] = ['at' => Carbon::parse($g->at), 'text' => $g->n == 1 ? 'Asked Luma a question' : 'Asked Luma ' . $g->n . ' questions', 'url' => null];
        }
        foreach (UserValidation::where('user_id', $c->id)->orderBy('attempt_number')->get() as $v) {
            if ($v->submitted_at) {
                $ev[] = ['at' => $v->submitted_at, 'text' => 'Sent an ID for identity validation', 'url' => null];
            }
            if ($v->reviewed_at && in_array($v->status, ['approved', 'rejected'], true)) {
                $ev[] = ['at' => $v->reviewed_at, 'text' => $v->status === 'approved' ? 'Identity was approved' : 'Identity was rejected', 'url' => null];
            }
        }
        foreach ($c->nameChanges()->get() as $n) {
            $ev[] = ['at' => $n->created_at, 'text' => 'Changed name to ' . $n->newName(), 'url' => null];
        }

        usort($ev, fn ($a, $b) => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return array_slice($ev, 0, 40);
    }

    /** "Today, 3:42 AM" / "Oct 28, 2026, 6:20 PM". */
    public static function when(?Carbon $t): string
    {
        if (!$t) {
            return '';
        }

        return $t->isToday() ? 'Today, ' . $t->format('g:i A') : $t->format('M j, Y, g:i A');
    }

    public static function initials(User $c): string
    {
        $a = mb_substr(trim((string) $c->first_name), 0, 1);
        $b = mb_substr(trim((string) $c->last_name), 0, 1);

        return mb_strtoupper($a . $b) ?: '?';
    }
}
