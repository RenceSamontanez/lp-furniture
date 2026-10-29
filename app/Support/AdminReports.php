<?php

namespace App\Support;

use App\Models\CustomDesignRequest;
use App\Models\Order;
use App\Models\ServiceInquiry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything on Reports and Analytics (admin), computed on the server from the real tables. No sample numbers.
 *
 * Definitions (kept from the old Reports page so existing figures do not change):
 *   - Revenue  = total_amount of orders that are not cancelled, by the day the order was placed
 *   - Money collected = amount_paid (the payment ledger total kept on the order) of those same orders
 * New in this page, each stated where it is computed:
 *   - Periods: "m" = this calendar month so far against the whole of last month; "q" = the 3 calendar months up to now against the 3 before them
 *   - A "design" = a Customize request that has at least one generated picture; it "became a request" once it was sent to the team,
 *     and "became a paid order" when an order points at it (orders.design_request_id) with money received and not cancelled
 *   - Luma: every row in chatbot_logs is one message; "not answered" = intent_detected is "unmatched"
 *   - Most used wood: catalog orders count by their product's material, customized orders by the wood chosen in their request
 */
final class AdminReports
{
    public const PERIODS = ['m' => 'This Month vs Last Month', 'q' => 'Last 3 Months vs Before'];
    public const MONTHS_SHOWN = 6;
    public const LOW_STOCK_AT = 5;
    public const TOP = 5;
    public const FOLLOW_UP_ROWS = 8;

    /** @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon, 4: string} current start, current end, previous start, previous end, label */
    public static function range(string $period): array
    {
        $now = now();
        if ($period === 'q') {
            $cs = $now->copy()->startOfMonth()->subMonths(2);

            return [$cs, $now->copy()->endOfDay(), $cs->copy()->subMonths(3), $cs->copy(), 'the 3 months before'];
        }
        $cs = $now->copy()->startOfMonth();

        return [$cs, $now->copy()->endOfDay(), $cs->copy()->subMonth(), $cs->copy(), 'last month'];
    }

    public static function build(string $period): array
    {
        $period = array_key_exists($period, self::PERIODS) ? $period : 'm';
        [$cs, $ce, $ps, $pe, $label] = self::range($period);

        $cur = self::headline($cs, $ce);
        $prev = self::headline($ps, $pe);

        $kpis = [
            self::card('Revenue', 'money', $cur['revenue'], $prev['revenue'], 'pct'),
            self::card('Orders', 'num', $cur['orders'], $prev['orders'], 'pct'),
            self::card('Money Collected', 'money', $cur['collected'], $prev['collected'], 'pct'),
            self::card('New Customers', 'num', $cur['customers'], $prev['customers'], 'abs'),
            self::card('Cancelled Orders', 'rate', $cur['cancelRate'], $prev['cancelRate'], 'pts', true),
            self::card('Luma Answered', 'rate', $cur['lumaRate'], $prev['lumaRate'], 'pts'),
        ];

        return [
            'period' => $period, 'periods' => self::PERIODS, 'prevLabel' => $label,
            'kpis' => $kpis,
            'sales' => self::sales(),
            'products' => self::products(),
            'services' => self::services($cs, $ce, $ps, $pe),
            'luma' => self::luma($cs, $ce, $ps, $pe),
            'customers' => self::customers($cs, $ce),
        ];
    }

    /* ───────────────── number cards ───────────────── */

    public static function peso(?float $v): string
    {
        if ($v === null) {
            return '—';
        }

        return '₱' . number_format($v, abs($v - round($v)) < 0.005 ? 0 : 2);
    }

    /**
     * One number card: the value, "Was <previous>", and a change chip.
     * mode pct = percent of the previous value ("New" when it was 0), abs = plus or minus a count, pts = points of a rate.
     * Green = good for the shop, red = needs attention; for $lowerIsBetter a drop is green.
     */
    public static function card(string $label, string $type, ?float $cur, ?float $prev, string $mode, bool $lowerIsBetter = false): array
    {
        $fmt = fn (?float $v) => $v === null ? '—' : ($type === 'money' ? self::peso($v) : ($type === 'rate' ? round($v) . '%' : number_format($v)));

        return ['label' => $label, 'value' => $fmt($cur), 'was' => $fmt($prev)] + self::chip($cur, $prev, $mode, $lowerIsBetter);
    }

    /** @return array{chip: string, tone: string, dir: string, sign: float} */
    public static function chip(?float $cur, ?float $prev, string $mode, bool $lowerIsBetter = false): array
    {
        if ($cur === null || $prev === null || abs($cur - $prev) < 0.00001) {
            return ['chip' => 'No change', 'tone' => 'flat', 'dir' => 'none', 'sign' => 0.0];
        }
        $diff = $cur - $prev;
        $tone = (($diff > 0) xor $lowerIsBetter) ? 'good' : 'bad';
        $text = match ($mode) {
            'pct' => $prev > 0 ? number_format(round(abs($diff) / $prev * 100)) . '%' : 'New',
            'pts' => round(abs($diff)) . ' pts',
            default => ($diff > 0 ? '+' : '−') . number_format(abs($diff)),
        };
        if ($mode === 'pts' && round(abs($diff)) == 0) {
            return ['chip' => 'No change', 'tone' => 'flat', 'dir' => 'none', 'sign' => 0.0];
        }

        return ['chip' => $text, 'tone' => $tone, 'dir' => $diff > 0 ? 'up' : 'dn', 'sign' => $diff];
    }

    /** The small chip on a monthly bar: "First" when the month before was empty, plus or minus percent otherwise. */
    public static function barChip(float $cur, float $prev, string $unit = 'pct'): array
    {
        if ($cur <= 0 && $prev <= 0) {
            return ['text' => 'None', 'tone' => 'flat'];
        }
        if ($cur > 0 && $prev <= 0) {
            return ['text' => 'First', 'tone' => 'good'];
        }
        if ($unit === 'pts') {
            $d = (int) round($cur - $prev);

            return ['text' => ($d > 0 ? '+' : ($d < 0 ? '−' : '')) . abs($d) . ' pts', 'tone' => $d > 0 ? 'good' : ($d < 0 ? 'bad' : 'flat')];
        }
        $d = (int) round(($cur - $prev) / $prev * 100);

        return ['text' => ($d > 0 ? '+' : ($d < 0 ? '−' : '')) . number_format(abs($d)) . '%', 'tone' => $d > 0 ? 'good' : ($d < 0 ? 'bad' : 'flat')];
    }

    /* ───────────────── headline figures for one range ───────────────── */

    private static function headline(Carbon $a, Carbon $b): array
    {
        $live = Order::query()->where('order_status', '!=', 'cancelled')->where('created_at', '>=', $a)->where('created_at', '<', $b);
        $money = (clone $live)->selectRaw('count(*) as n, coalesce(sum(total_amount),0) as revenue, coalesce(sum(amount_paid),0) as collected')->first();
        $all = Order::query()->where('created_at', '>=', $a)->where('created_at', '<', $b);
        $placed = (clone $all)->count();
        $cancelled = (clone $all)->where('order_status', 'cancelled')->count();

        return [
            'revenue'    => (float) $money->revenue,
            'orders'     => (float) $money->n,
            'collected'  => (float) $money->collected,
            'customers'  => (float) User::where('role', 'customer')->where('created_at', '>=', $a)->where('created_at', '<', $b)->count(),
            'cancelRate' => $placed > 0 ? $cancelled / $placed * 100 : null,
            'lumaRate'   => self::lumaCounts($a, $b)['rate'],
        ];
    }

    /** The last N calendar months, oldest first: [['key' => '2026-10', 'label' => 'Oct'], ...]. */
    private static function months(): array
    {
        $out = [];
        for ($i = self::MONTHS_SHOWN - 1; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonths($i);
            $out[] = ['key' => $m->format('Y-m'), 'label' => $m->format('M')];
        }

        return $out;
    }

    private static function series(array $byMonth, string $unit = 'pct'): array
    {
        $months = self::months();
        $max = 0.0;
        foreach ($months as $m) {
            $max = max($max, (float) ($byMonth[$m['key']] ?? 0));
        }
        $bars = [];
        foreach ($months as $i => $m) {
            $v = (float) ($byMonth[$m['key']] ?? 0);
            $p = $i ? (float) ($byMonth[$months[$i - 1]['key']] ?? 0) : 0.0;
            $bars[] = ['label' => $m['label'], 'value' => $v, 'height' => $max > 0 ? round($v / $max * 100, 1) : 0, 'chip' => self::barChip($v, $p, $unit)];
        }

        return $bars;
    }

    private static function startOfSeries(): Carbon
    {
        return now()->startOfMonth()->subMonths(self::MONTHS_SHOWN - 1);
    }

    /* ───────────────── Sales ───────────────── */

    private static function sales(): array
    {
        $byMonth = Order::query()->where('order_status', '!=', 'cancelled')->where('created_at', '>=', self::startOfSeries())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, sum(total_amount) as total")->groupBy('ym')->pluck('total', 'ym')->all();

        $statusCounts = Order::query()->selectRaw('order_status, count(*) as n')->groupBy('order_status')->pluck('n', 'order_status')->all();
        $statuses = [];
        foreach (['pending' => 'Pending', 'confirmed' => 'Confirmed', 'in_production' => 'In Production', 'ready' => 'Ready', 'out_for_delivery' => 'Out For Delivery', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $key => $label) {
            $statuses[] = ['key' => $key, 'label' => $label, 'count' => (int) ($statusCounts[$key] ?? 0)];
        }

        $split = [];
        foreach (['Catalog' => false, 'Customized' => true] as $label => $custom) {
            $q = Order::query()->where('order_status', '!=', 'cancelled')
                ->when($custom, fn ($q) => $q->whereNotNull('design_agreement_id'), fn ($q) => $q->whereNull('design_agreement_id'))
                ->selectRaw('count(*) as n, coalesce(sum(total_amount),0) as total, coalesce(sum(amount_paid),0) as paid')->first();
            $split[] = ['label' => $label, 'orders' => (int) $q->n, 'total' => (float) $q->total, 'paid' => (float) $q->paid];
        }

        $totalValue = array_sum(array_column($split, 'total'));
        $totalPaid = array_sum(array_column($split, 'paid'));

        return [
            'months' => self::series($byMonth),
            'statuses' => $statuses, 'ordersTotal' => array_sum(array_column($statuses, 'count')),
            'split' => $split,
            'collected' => ['paid' => $totalPaid, 'total' => $totalValue, 'left' => max(0, $totalValue - $totalPaid), 'pct' => $totalValue > 0 ? (int) round($totalPaid / $totalValue * 100) : 0],
        ];
    }

    /* ───────────────── Products ───────────────── */

    private static function products(): array
    {
        $best = DB::table('order_items')->join('products', 'order_items.product_id', '=', 'products.id')
            ->selectRaw('products.id, products.product_name, sum(order_items.quantity) as sold')
            ->groupBy('products.id', 'products.product_name')->orderByDesc('sold')->limit(self::TOP)->get();

        $category = DB::table('order_items')->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereNotNull('products.category')->selectRaw('products.category as name, count(*) as n')->groupBy('products.category')->orderByDesc('n')->limit(self::TOP)->get();

        // Orders per wood. A catalog order counts once per distinct material of its products; a customized order by its request's wood.
        $catalog = DB::table('order_items')->join('products', 'order_items.product_id', '=', 'products.id')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.order_status', '!=', 'cancelled')->whereNotNull('products.material')->where('products.material', '!=', '')
            ->selectRaw('TRIM(products.material) as wood, count(distinct orders.id) as n')->groupBy('wood')->pluck('n', 'wood')->all();
        $custom = DB::table('orders')->join('custom_design_requests as r', 'r.id', '=', 'orders.design_request_id')
            ->where('orders.order_status', '!=', 'cancelled')->whereNotNull('r.preferred_material')
            ->selectRaw('r.preferred_material as wood, count(*) as n')->groupBy('wood')->pluck('n', 'wood')->all();
        $wood = [];
        foreach ([$catalog, $custom] as $set) {
            foreach ($set as $name => $n) {
                $name = $name === WoodChoices::SPECIAL ? WoodChoices::SPECIAL_LABEL : $name;
                $wood[$name] = ($wood[$name] ?? 0) + (int) $n;
            }
        }
        arsort($wood);

        $low = DB::table('products')->where('stock_quantity', '<=', self::LOW_STOCK_AT)->orderBy('stock_quantity')->orderBy('product_name')->limit(self::TOP)->get(['id', 'product_name', 'stock_quantity']);

        return [
            'best' => $best,
            'category' => self::shares($category->pluck('n', 'name')->all()),
            'wood' => self::shares(array_slice($wood, 0, self::TOP, true)),
            'low' => $low,
        ];
    }

    /** [name => n] -> list of name, n, percent of the total shown, width relative to the largest. */
    private static function shares(array $counts): array
    {
        $sum = array_sum($counts);
        $max = $counts ? max($counts) : 0;

        return array_values(array_map(fn ($name, $n) => [
            'name' => (string) $name, 'n' => (int) $n,
            'pct' => $sum > 0 ? (int) round($n / $sum * 100) : 0, 'width' => $max > 0 ? round($n / $max * 100, 1) : 0,
        ], array_keys($counts), $counts));
    }

    /* ───────────────── Services & AI ───────────────── */

    private static function designs(Carbon $a, Carbon $b): array
    {
        $designs = CustomDesignRequest::query()->where('created_at', '>=', $a)->where('created_at', '<', $b)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('ai_design_suggestions')->whereColumn('ai_design_suggestions.design_id', 'custom_design_requests.id'));
        $made = (clone $designs)->count();
        $ids = (clone $designs)->pluck('id');
        $requests = $ids->isEmpty() ? 0 : CustomDesignRequest::whereIn('id', $ids)->where(fn ($q) => $q->whereNotNull('pipeline_status')->orWhereIn('status', ['approved', 'converted_to_order']))->count();
        $paid = $ids->isEmpty() ? null : Order::whereIn('design_request_id', $ids)->where('order_status', '!=', 'cancelled')->where('amount_paid', '>', 0)
            ->selectRaw('count(distinct design_request_id) as n, coalesce(sum(amount_paid),0) as money')->first();

        return ['made' => $made, 'requests' => $requests, 'paid' => (int) ($paid->n ?? 0), 'money' => (float) ($paid->money ?? 0)];
    }

    private static function services(Carbon $cs, Carbon $ce, Carbon $ps, Carbon $pe): array
    {
        $cur = self::designs($cs, $ce);
        $prev = self::designs($ps, $pe);

        $cards = [
            self::card('Designs Made', 'num', $cur['made'], $prev['made'], 'pct'),
            self::card('Became Requests', 'num', $cur['requests'], $prev['requests'], 'pct'),
            self::card('Became Paid Orders', 'num', $cur['paid'], $prev['paid'], 'abs'),
            self::card('Money From Designs', 'money', $cur['money'], $prev['money'], 'pct'),
        ];
        $funnel = [
            'made' => $cur['made'], 'requests' => $cur['requests'], 'paid' => $cur['paid'],
            'requestsPct' => $cur['made'] > 0 ? (int) round($cur['requests'] / $cur['made'] * 100) : null,
            'paidPct' => $cur['requests'] > 0 ? (int) round($cur['paid'] / $cur['requests'] * 100) : null,
            'average' => $cur['paid'] > 0 ? $cur['money'] / $cur['paid'] : null,
            'salePct' => $cur['made'] > 0 ? round($cur['paid'] / $cur['made'] * 100, 1) : null,
        ];

        $byMonth = CustomDesignRequest::query()->where('created_at', '>=', self::startOfSeries())
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('ai_design_suggestions')->whereColumn('ai_design_suggestions.design_id', 'custom_design_requests.id'))
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, count(*) as n")->groupBy('ym')->pluck('n', 'ym')->all();

        return ['cards' => $cards, 'funnel' => $funnel, 'months' => self::series($byMonth), 'demand' => self::demand($cs, $ce, $ps, $pe)];
    }

    /** Other Services: one row per service type, by the day the request came in. Done = closed tickets. Revenue = money received on their invoices. */
    private static function demand(Carbon $cs, Carbon $ce, Carbon $ps, Carbon $pe): array
    {
        $count = fn (Carbon $a, Carbon $b) => ServiceInquiry::query()->where('created_at', '>=', $a)->where('created_at', '<', $b)
            ->selectRaw('service_type, count(*) as n, sum(case when status = ? then 1 else 0 end) as done', ['closed'])->groupBy('service_type')->get()->keyBy('service_type');
        $now = $count($cs, $ce);
        $before = $count($ps, $pe);
        $revenue = DB::table('service_invoices as i')->join('service_inquiries as s', 's.id', '=', 'i.service_inquiry_id')
            ->where('s.created_at', '>=', $cs)->where('s.created_at', '<', $ce)->selectRaw('s.service_type, coalesce(sum(i.amount_paid),0) as money')->groupBy('s.service_type')->pluck('money', 'service_type')->all();

        $max = 0;
        $rows = [];
        foreach (ServiceInquiry::SERVICE_TYPES as $key => $label) {
            $n = (int) ($now[$key]->n ?? 0);
            $max = max($max, $n);
            $rows[] = ['label' => $label, 'requests' => $n, 'done' => (int) ($now[$key]->done ?? 0), 'revenue' => (float) ($revenue[$key] ?? 0), 'change' => $n - (int) ($before[$key]->n ?? 0)];
        }
        foreach ($rows as &$r) {
            $r['width'] = $max > 0 ? round($r['requests'] / $max * 100, 1) : 0;
        }

        return $rows;
    }

    /* ───────────────── Luma ───────────────── */

    private static function lumaCounts(Carbon $a, Carbon $b): array
    {
        if (!Schema::hasTable('chatbot_logs')) {
            return ['asked' => null, 'answered' => null, 'missed' => null, 'rate' => null];
        }
        $row = DB::table('chatbot_logs')->where('created_at', '>=', $a)->where('created_at', '<', $b)
            ->selectRaw("count(*) as n, coalesce(sum(case when intent_detected = 'unmatched' then 1 else 0 end),0) as missed")->first();
        $asked = (int) $row->n;
        $missed = (int) $row->missed;

        return ['asked' => $asked, 'answered' => $asked - $missed, 'missed' => $missed, 'rate' => $asked > 0 ? ($asked - $missed) / $asked * 100 : null];
    }

    private static function luma(Carbon $cs, Carbon $ce, Carbon $ps, Carbon $pe): array
    {
        if (!Schema::hasTable('chatbot_logs')) {
            return ['tracked' => false];
        }
        $cur = self::lumaCounts($cs, $ce);
        $prev = self::lumaCounts($ps, $pe);
        $cards = [
            self::card('Questions Asked', 'num', $cur['asked'], $prev['asked'], 'pct'),
            self::card('Answered', 'num', $cur['answered'], $prev['answered'], 'pct'),
            self::card('Not Answered', 'num', $cur['missed'], $prev['missed'], 'pct', true),
            self::card('Answer Rate', 'rate', $cur['rate'], $prev['rate'], 'pts'),
        ];

        $rates = DB::table('chatbot_logs')->where('created_at', '>=', self::startOfSeries())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, count(*) as n, sum(case when intent_detected = 'unmatched' then 1 else 0 end) as missed")->groupBy('ym')->get();
        $byMonth = [];
        foreach ($rates as $r) {
            $byMonth[$r->ym] = $r->n > 0 ? round(($r->n - $r->missed) / $r->n * 100) : 0;
        }

        $top = DB::table('chatbot_logs')->where('created_at', '>=', $cs)->where('created_at', '<', $ce)->whereRaw('char_length(message) <= 200')
            ->selectRaw('LOWER(TRIM(message)) as q, count(*) as n')->groupBy('q')->orderByDesc('n')->limit(self::TOP)->get();
        $sum = max(1, (int) $cur['asked']);

        $couldNot = Schema::hasTable('luma_unanswered_questions')
            ? DB::table('luma_unanswered_questions')->whereNull('dismissed_at')->whereNull('taught_at')->orderByDesc('times_asked')->orderByDesc('last_asked_at')->limit(self::TOP)->get(['question', 'times_asked'])
            : collect();

        return [
            'tracked' => true, 'cards' => $cards, 'months' => self::series($byMonth, 'pts'), 'monthsAreRates' => true,
            'top' => $top->map(fn ($r) => ['q' => $r->q, 'n' => (int) $r->n, 'pct' => (int) round($r->n / $sum * 100), 'width' => $top->first() ? round($r->n / $top->first()->n * 100, 1) : 0])->all(),
            'couldNot' => $couldNot,
        ];
    }

    /* ───────────────── Customers ───────────────── */

    private static function customers(Carbon $cs, Carbon $ce): array
    {
        $monthStart = now()->startOfMonth();
        $people = User::where('role', 'customer');
        $total = (clone $people)->count();
        $newThis = (clone $people)->where('created_at', '>=', $monthStart)->count();
        $newLast = (clone $people)->where('created_at', '>=', $monthStart->copy()->subMonth())->where('created_at', '<', $monthStart)->count();
        $verified = (clone $people)->where('is_validated', true)->count();

        $cards = [
            ['label' => 'Customers', 'value' => number_format($total)] + self::chip($total, $total - $newThis, 'abs'),
            ['label' => 'New This Month', 'value' => number_format($newThis)] + self::chip($newThis, $newLast, 'abs'),
            ['label' => 'Verified', 'value' => number_format($verified), 'chip' => null, 'tone' => 'flat', 'dir' => 'none'],
            ['label' => 'Not Verified', 'value' => number_format($total - $verified), 'chip' => null, 'tone' => 'flat', 'dir' => 'none'],
        ];

        // Orders still waiting for any money: pending, nothing received yet, oldest first.
        $follow = Order::query()->with('user:id,first_name,last_name')->where('order_status', 'pending')->where(fn ($q) => $q->whereNull('amount_paid')->orWhere('amount_paid', '<=', 0))
            ->orderBy('created_at')->limit(self::FOLLOW_UP_ROWS)->get(['id', 'user_id', 'order_number', 'total_amount', 'created_at'])
            ->map(fn ($o) => [
                'customer' => $o->user ? trim($o->user->first_name . ' ' . $o->user->last_name) : 'A customer',
                'order' => $o->order_number, 'amount' => (float) $o->total_amount,
                'days' => (int) $o->created_at->startOfDay()->diffInDays(now()->startOfDay()), 'id' => $o->id,
            ])->all();

        $top = Order::query()->join('users', 'users.id', '=', 'orders.user_id')->where('orders.order_status', '!=', 'cancelled')
            ->selectRaw('users.id, users.first_name, users.last_name, count(*) as orders, coalesce(sum(orders.amount_paid),0) as spent, max(orders.created_at) as last_order')
            ->groupBy('users.id', 'users.first_name', 'users.last_name')->orderByDesc('orders')->orderByDesc('spent')->limit(self::TOP)->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => trim($r->first_name . ' ' . $r->last_name), 'orders' => (int) $r->orders, 'spent' => (float) $r->spent, 'last' => Carbon::parse($r->last_order)->format('M j, Y')])->all();

        return ['cards' => $cards, 'follow' => $follow, 'top' => $top, 'why' => self::cancelReasons($cs, $ce)];
    }

    /** Why orders were cancelled in the period: the customer's chosen reason, or who cancelled it (the shop, or the unpaid-order sweep). */
    private static function cancelReasons(Carbon $a, Carbon $b): array
    {
        $events = DB::table('order_status_events')->where('type', 'cancel')->where('created_at', '>=', $a)->where('created_at', '<', $b)->get(['admin_user_id', 'note']);
        $counts = [];
        foreach ($events as $e) {
            $name = 'Cancelled By The Shop';
            if (preg_match('/^Cancelled by the customer\. Reason: (.+?)( - .*)?$/su', (string) $e->note, $m) && in_array($m[1], OrderTracking::CUSTOMER_CANCEL_REASONS, true)) {
                $name = $m[1];
            } elseif ($e->admin_user_id === null) {
                $name = 'Not Paid In Time';
            }
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        arsort($counts);

        return self::shares($counts);
    }
}
