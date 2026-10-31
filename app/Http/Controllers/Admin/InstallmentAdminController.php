<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InstallmentSchedule;
use App\Models\StandingEvent;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\Installments\InstallmentCharger;
use App\Support\Installments\InstallmentStanding;
use App\Support\Installments\PayMongoMode;
use App\Support\Installments\SandboxInstallmentGateway;
use App\Support\Installments\PlanMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InstallmentAdminController extends Controller
{
    /** Tab key => statuses. The old ?status= values still work. */
    private const TABS = [
        'up'  => ['Upcoming', ['pending']],
        'at'  => ['Needs Attention', ['failed', 'link_sent', 'processing', 'overdue']],
        'pd'  => ['Paid', ['paid']],
        'all' => ['All', null],
    ];

    private const OLD_STATUS = ['pending' => 'up', 'overdue' => 'at', 'paid' => 'pd', 'all' => 'all'];

    public function index(Request $request)
    {
        $tab = $request->query('tab', self::OLD_STATUS[$request->query('status')] ?? 'up');
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'up';

        $counts = [];
        foreach (self::TABS as $k => [, $statuses]) {
            $counts[$k] = InstallmentSchedule::when($statuses, fn ($q) => $q->whereIn('status', $statuses))->count();
        }
        $overdue = InstallmentSchedule::where('status', 'overdue')->count();

        $rows = InstallmentSchedule::with(['order.user', 'payment', 'attemptsLog'])
            ->when(self::TABS[$tab][1], fn ($q, $s) => $q->whereIn('status', $s))
            ->orderBy('due_date')->orderBy('id')
            ->paginate(15)->withQueryString();

        $totals = InstallmentSchedule::whereIn('payment_id', $rows->pluck('payment_id')->unique())
            ->selectRaw('payment_id, count(*) c')->groupBy('payment_id')->pluck('c', 'payment_id');

        return view('admin.installments.index', [
            'rows' => $rows, 'tab' => $tab, 'tabs' => self::TABS, 'counts' => $counts, 'overdue' => $overdue, 'totals' => $totals,
            'testMode' => PayMongoMode::isTestKey(),
            'sandbox' => PlanMode::sandboxActive(),
        ]);
    }

    /** Simulate Due Date: runs the REAL daily job for this one installment. Test keys only. */
    public function simulate(Request $request, int $id, InstallmentCharger $charger)
    {
        if (!PayMongoMode::isTestKey()) {
            return back()->withErrors(['simulate' => 'Simulate Due Date is only available while PayMongo test keys are active.']);
        }
        $i = InstallmentSchedule::with('order', 'payment')->findOrFail($id);

        if (!$i->payment?->installments_started_at) {
            return back()->withErrors(['simulate' => 'This plan has not started yet: its down payment is not confirmed.']);
        }

        // Sandbox only: how the simulated card charge ends. The real PayMongo driver ignores the choice.
        $outcome = $request->input('outcome', 'success');
        if (!array_key_exists($outcome, SandboxInstallmentGateway::OUTCOMES)) {
            return back()->withErrors(['simulate' => 'Pick how the charge should end.']);
        }
        if (in_array($i->status, ['pending', 'failed'], true) && $i->payment && PlanMode::autoCharges($i->payment) && PlanMode::sandboxActive()) {
            SandboxInstallmentGateway::setOutcome($i->id, $outcome);
        }

        $stats = match ($i->status) {
            'pending'   => $charger->forceDue($i),
            'failed'    => $charger->forceRetry($i),
            'link_sent' => $charger->forceOverdue($i),
            default     => null,
        };
        if ($stats === null) {
            return back()->withErrors(['simulate' => 'Nothing to simulate for an installment that is ' . strtolower($i->statusLabel()) . '.']);
        }

        ActivityLog::record('installment_simulated', "Simulated the due date for installment {$i->installment_no} of order {$i->order->order_number} (test mode)", 'orders', $i->order, 'Order ' . $i->order->order_number);
        $now = $i->fresh();

        return back()->with('success', 'Ran the daily job for this installment. It is now: ' . $now->statusLabel() . '.');
    }

    public function customer(Request $request, int $id)
    {
        $customer = User::where('role', '!=', 'admin')->findOrFail($id);
        $score = InstallmentStanding::score($customer);
        $level = InstallmentStanding::levelFor($score);

        $installments = InstallmentSchedule::with('order', 'payment')->where('user_id', $customer->id)->orderBy('order_id')->orderBy('installment_no')->get();
        $totals = $installments->groupBy('payment_id')->map->count();
        $history = StandingEvent::where('user_id', $customer->id)->latest('id')->limit(200)->get();

        return view('admin.installments.customer', [
            'customer' => $customer, 'score' => $score, 'level' => $level, 'levels' => config('installments.levels'),
            'installments' => $installments, 'totals' => $totals, 'history' => $history,
            'tab' => $request->query('tab') === 'standing' ? 'standing' : 'installments',
            'paidCount' => $installments->where('status', 'paid')->count(), 'overdueNow' => $installments->where('status', 'overdue')->count(),
        ]);
    }

    public function adjust(Request $request, int $id, InstallmentStanding $standing)
    {
        $customer = User::where('role', '!=', 'admin')->findOrFail($id);
        $data = $request->validate([
            'mode'   => 'required|in:add,sub,set',
            'points' => 'required|integer|min:0|max:100',
            'reason' => 'required|string|max:200',
        ], ['reason.required' => 'Please write a reason. It is saved with the change.']);

        try {
            $before = InstallmentStanding::score($customer);
            $event = $standing->adjust($customer, Auth::guard('admin')->user() ?? Auth::user(), $data['mode'], (int) $data['points'], $data['reason']);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['reason' => $e->getMessage()]);
        }

        ActivityLog::record('installment_standing_adjusted',
            'Adjusted ' . trim($customer->first_name . ' ' . $customer->last_name) . "'s Installment Standing from {$before} to {$event->score_after}", 'people', $customer, trim($customer->first_name . ' ' . $customer->last_name));

        return redirect()->route('admin.installments.customer', ['id' => $customer->id, 'tab' => 'standing'])->with('success', 'Standing updated and recorded.');
    }

    /** Label for the Method column. */
    public static function methodLabel($plan): string
    {
        return match ($plan?->payment_method) {
            'paymongo_card' => 'Card' . ($plan->card_last4 ? ' (' . PlanMode::cardLabel($plan) . ')' : ''),
            'gcash' => 'GCash',
            'stripe' => 'Card',
            default => 'On-Site',
        };
    }
}
