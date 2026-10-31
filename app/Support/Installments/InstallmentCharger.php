<?php

namespace App\Support\Installments;

use App\Models\InstallmentSchedule;
use App\Support\ActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The daily job (php artisan installments:run, 08:00 Asia/Manila):
 *   3 days before due   a reminder, once
 *   due date            card plans: charge the saved card; others: a Pay Now notification
 *   charge failed       retry the next day; failed again: Pay Now link + admin notice
 *   3 days after due    Overdue (set here, never by viewing a page)
 * Each installment is handled on its own, so one failure never stops the rest.
 * Simulate Due Date on the admin page calls forceDue()/forceRetry()/forceOverdue(),
 * which run these very methods.
 */
class InstallmentCharger
{
    public function __construct(
        private InstallmentGateway $gateway,
        private InstallmentPayments $payments,
        private InstallmentStanding $standing,
        private InstallmentNotifier $notify,
    ) {
    }

    private function tz(): string
    {
        return config('installments.timezone', 'Asia/Manila');
    }

    /** The start of the day, in Manila, grace_days after the due date. */
    private function graceEnd(InstallmentSchedule $i): Carbon
    {
        return Carbon::parse($i->due_date->toDateString(), $this->tz())->addDays((int) config('installments.grace_days'))->startOfDay();
    }

    private function eligible()
    {
        return InstallmentSchedule::query()
            ->whereHas('payment', fn ($q) => $q->whereNotNull('installments_started_at'))
            ->whereHas('order', fn ($q) => $q->where('order_status', '!=', 'cancelled')->whereNull('design_agreement_id'));
    }

    /** @return array<string,int> */
    public function runDaily(?Carbon $now = null, ?int $onlyId = null, bool $simulated = false): array
    {
        $now = ($now ?? now())->copy()->setTimezone($this->tz());
        $today = $now->toDateString();
        $stats = ['reminders' => 0, 'charged' => 0, 'links' => 0, 'retried' => 0, 'overdue' => 0, 'reconciled' => 0];
        $only = fn ($q) => $onlyId ? $q->where('id', $onlyId) : $q;

        // 1. Reminders: due within the next N days, not yet reminded.
        $until = $now->copy()->addDays((int) config('installments.reminder_days_before'))->toDateString();
        $only($this->eligible()->where('status', 'pending')->whereNull('reminder_sent_at')->whereBetween('due_date', [$today, $until]))
            ->get()->each(function ($i) use (&$stats, $now, $simulated) {
                $this->guard($i, function () use ($i, &$stats, $now, $simulated) {
                    $i->forceFill(['reminder_sent_at' => $now])->save();
                    $this->notify->reminder($i);
                    InstallmentPayments::attempt($i, 'reminder', 'info', 'Reminder sent to the customer', null, $simulated);
                    $stats['reminders']++;
                });
            });

        // 2. Due today (or earlier, if the job missed a day): charge, or send the Pay Now link.
        $only($this->eligible()->where('status', 'pending')->where('due_date', '<=', $today))
            ->orderBy('due_date')->get()->each(function ($i) use (&$stats, $now, $simulated) {
                $this->guard($i, function () use ($i, &$stats, $now, $simulated) {
                    if (PlanMode::autoCharges($i->payment)) {
                        $this->charge($i, 'charge', $now, $simulated) && $stats['charged']++;
                    } else {
                        $this->sendLink($i, $now, $simulated, true) && $stats['links']++;
                    }
                });
            });

        // 3. The retry the next day.
        $only($this->eligible()->where('status', 'failed')->where('next_attempt_at', '<=', $now))
            ->get()->each(function ($i) use (&$stats, $now, $simulated) {
                $this->guard($i, function () use ($i, &$stats, $now, $simulated) {
                    $this->charge($i, 'retry', $now, $simulated);
                    $stats['retried']++;
                });
            });

        // 4. A charge that never reported back for a day: ask the gateway.
        $only($this->eligible()->where('status', 'processing')->where('last_attempt_at', '<=', $now->copy()->subDay()))
            ->get()->each(function ($i) use (&$stats, $now, $simulated) {
                $this->guard($i, function () use ($i, &$stats, $now, $simulated) {
                    $this->reconcile($i, $now, $simulated);
                    $stats['reconciled']++;
                });
            });

        // 5. Grace over: Overdue.
        $only($this->eligible()->whereIn('status', ['failed', 'link_sent'])->where('grace_ends_at', '<=', $now))
            ->get()->each(function ($i) use (&$stats, $now, $simulated) {
                $this->guard($i, function () use ($i, &$stats, $now, $simulated) {
                    $this->markOverdue($i, $now, $simulated) && $stats['overdue']++;
                });
            });

        return $stats;
    }

    /* ───── Simulate Due Date (test keys only; the caller checks) — each runs the REAL job for one installment ───── */

    public function forceDue(InstallmentSchedule $i): array
    {
        $i->forceFill(['due_date' => now($this->tz())->toDateString()])->save();

        return $this->runDaily(null, $i->id, true);
    }

    public function forceRetry(InstallmentSchedule $i): array
    {
        $i->forceFill(['next_attempt_at' => now()->subMinute()])->save();

        return $this->runDaily(null, $i->id, true);
    }

    public function forceOverdue(InstallmentSchedule $i): array
    {
        $i->forceFill(['grace_ends_at' => now()->subMinute()])->save();

        return $this->runDaily(null, $i->id, true);
    }

    /* ───── steps ───── */

    private function guard(InstallmentSchedule $i, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error("Installment {$i->id} could not be processed: " . $e->getMessage());
        }
    }

    /** Claim the row atomically (pending/failed -> processing) so two runs can never charge twice. */
    private function claim(InstallmentSchedule $i, array $from, Carbon $now): bool
    {
        $n = InstallmentSchedule::where('id', $i->id)->whereIn('status', $from)
            ->update(['status' => 'processing', 'last_attempt_at' => $now]);
        $i->refresh();

        return $n === 1;
    }

    private function charge(InstallmentSchedule $i, string $kind, Carbon $now, bool $simulated): bool
    {
        if (!$this->claim($i, [$kind === 'retry' ? 'failed' : 'pending'], $now)) {
            return false;
        }
        $plan = $i->payment;
        $simulated = $simulated || $this->gateway->isSimulated(); // a sandbox charge is always flagged

        $result = $this->gateway->chargeSavedCard(
            (string) $plan->paymongo_customer_id, (string) $plan->installment_card_method_id,
            (int) round((float) $i->amount_due * 100),
            "LP Furniture order {$i->order->order_number} installment {$i->installment_no}",
            ['kind' => 'installment', 'installment_schedule_id' => $i->id, 'order_id' => $i->order_id],
        );

        if ($result->ok()) {
            $this->payments->recordGateway([$i->id], PlanMode::cardLedgerMethod(), (string) $result->reference, 'auto', $simulated);

            return true;
        }
        if ($result->status === ChargeResult::PENDING) {
            $i->forceFill(['status' => 'processing', 'attempts' => $i->attempts + 1])->save();
            InstallmentPayments::attempt($i, $kind, 'info', 'Waiting for the card network to confirm', $result->reference, $simulated);

            return true;
        }

        $this->failed($i, $kind, (string) $result->message, $result->reference, $now, $simulated);

        return false;
    }

    private function failed(InstallmentSchedule $i, string $kind, string $message, ?string $ref, Carbon $now, bool $simulated): void
    {
        $attempts = $i->attempts + 1;
        $first = $attempts < 2 && $kind !== 'retry';
        $grace = $this->graceEnd($i);

        if ($first) {
            $next = $now->copy()->addDays((int) config('installments.retry_after_days'))
                ->setTimeFromTimeString((string) config('installments.schedule_time'));
            $i->forceFill(['status' => 'failed', 'attempts' => $attempts, 'charge_failed' => true, 'next_attempt_at' => $next, 'grace_ends_at' => $grace])->save();
            InstallmentPayments::attempt($i, $kind, 'failed', 'Card declined (' . $message . '). Will retry tomorrow', $ref, $simulated);
            $this->notify->chargeFailed($i);
            ActivityLog::record('installment_charge_failed', "Installment {$i->installment_no} charge failed for order {$i->order->order_number}", 'orders', $i->order, 'Order ' . $i->order->order_number, null);

            return;
        }

        $i->forceFill(['status' => 'link_sent', 'attempts' => $attempts, 'charge_failed' => true, 'next_attempt_at' => null, 'pay_link_sent_at' => $now, 'grace_ends_at' => $grace])->save();
        InstallmentPayments::attempt($i, $kind, 'failed', 'Retry declined (' . $message . '). Pay Now link sent to the customer', $ref, $simulated);
        $this->notify->payLink($i);
        $this->notify->needsAttention($i);
        ActivityLog::record('installment_charge_failed', "Installment {$i->installment_no} charge failed twice for order {$i->order->order_number}", 'orders', $i->order, 'Order ' . $i->order->order_number, null);
    }

    /** GCash and other no-card plans: the Pay Now notification on the due date. */
    private function sendLink(InstallmentSchedule $i, Carbon $now, bool $simulated, bool $dueDay): bool
    {
        $n = InstallmentSchedule::where('id', $i->id)->where('status', 'pending')->update([
            'status' => 'link_sent', 'pay_link_sent_at' => $now,
            'grace_ends_at' => $this->graceEnd($i),
        ]);
        if ($n !== 1) {
            return false;
        }
        $i->refresh();
        $this->notify->gcashDue($i);
        InstallmentPayments::attempt($i, 'link', 'info', 'Pay Now notification sent to the customer', null, $simulated);

        return true;
    }

    private function reconcile(InstallmentSchedule $i, Carbon $now, bool $simulated): void
    {
        $ref = $i->attemptsLog()->whereNotNull('gateway_reference')->latest('id')->value('gateway_reference');
        $info = $ref ? $this->gateway->lookupIntent($ref) : null;

        if ($info && $info['status'] === 'succeeded') {
            $this->payments->recordGateway([$i->id], str_starts_with($ref, SandboxInstallmentGateway::PREFIX) ? 'card_sandbox' : 'card_paymongo', $ref, 'auto', $simulated);

            return;
        }
        if ($info === null && $ref) {
            return; // gateway unreachable: look again tomorrow
        }
        $i->forceFill(['status' => $i->attempts >= 1 && $i->charge_failed ? 'failed' : 'pending'])->save();
        $this->failed($i->refresh(), $i->attempts >= 2 ? 'retry' : 'charge', 'no answer from the card network', $ref, $now, $simulated);
    }

    public function markOverdue(InstallmentSchedule $i, ?Carbon $now = null, bool $simulated = false): bool
    {
        $now ??= now($this->tz());
        $n = InstallmentSchedule::where('id', $i->id)->whereIn('status', ['failed', 'link_sent', 'pending', 'processing'])
            ->update(['status' => 'overdue', 'overdue_at' => $now, 'next_attempt_at' => null]);
        if ($n !== 1) {
            return false;
        }
        $i->refresh();
        InstallmentPayments::attempt($i, 'overdue', 'failed', 'Grace period ended, marked Overdue', null, $simulated);
        try {
            $this->standing->onOverdue($i);
            $this->notify->overdue($i);
            ActivityLog::record('installment_overdue', "Installment {$i->installment_no} is Overdue on order {$i->order->order_number}", 'orders', $i->order, 'Order ' . $i->order->order_number, null);
        } catch (\Throwable $e) {
            Log::warning('Overdue follow-ups failed: ' . $e->getMessage());
        }

        return true;
    }
}
