<?php

namespace App\Support\Installments;

use App\Models\InstallmentSchedule;
use App\Models\Payment;
use App\Models\StandingEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Installment Standing: a 0-100 score with levels, like a credit score. Every
 * number is in config/installments.php. Every change is a row in
 * installment_standing_history; a scoring_key makes each automatic event
 * happen at most once. No decay, no late fees.
 */
class InstallmentStanding
{
    /** @return array{min:int,name:string,months:int,cap:?int} */
    public static function levelFor(int $score): array
    {
        $levels = config('installments.levels');
        foreach ($levels as $level) {
            if ($score >= $level['min']) {
                return $level;
            }
        }

        return end($levels);
    }

    public static function score(User $user): int
    {
        $score = DB::table('users')->where('id', $user->id)->value('installment_standing_score');

        return (int) ($score ?? config('installments.start_score'));
    }

    private static function clamp(int $n): int
    {
        return max((int) config('installments.min_score'), min((int) config('installments.max_score'), $n));
    }

    /**
     * Apply points. Returns null when this scoring_key was already applied.
     * The change recorded is the real one after clamping to 0-100.
     */
    public function apply(User $user, int $points, string $reason, ?string $key = null, ?int $scheduleId = null, ?int $paymentId = null, ?int $adminId = null): ?StandingEvent
    {
        try {
            return DB::transaction(function () use ($user, $points, $reason, $key, $scheduleId, $paymentId, $adminId) {
                $before = (int) DB::table('users')->where('id', $user->id)->lockForUpdate()->value('installment_standing_score');
                $after = self::clamp($before + $points);

                $event = StandingEvent::create([
                    'user_id' => $user->id, 'installment_schedule_id' => $scheduleId, 'payment_id' => $paymentId,
                    'scoring_key' => $key, 'points' => $after - $before, 'score_after' => $after,
                    'reason' => mb_substr($reason, 0, 255), 'admin_id' => $adminId, 'created_at' => now(),
                ]);
                DB::table('users')->where('id', $user->id)->update(['installment_standing_score' => $after]);

                return $event;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'scoring_key')) {
                return null; // already applied
            }
            throw $e;
        }
    }

    /**
     * Admin adjustment. A reason is required. $mode: add | sub | set.
     *
     * @throws \InvalidArgumentException
     */
    public function adjust(User $user, User $admin, string $mode, int $value, string $reason): StandingEvent
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Please write a reason. It is saved with the change.');
        }
        if (!in_array($mode, ['add', 'sub', 'set'], true) || $value < 0 || $value > 100) {
            throw new \InvalidArgumentException('Enter points between 0 and 100.');
        }

        return DB::transaction(function () use ($user, $admin, $mode, $value, $reason) {
            $before = (int) DB::table('users')->where('id', $user->id)->lockForUpdate()->value('installment_standing_score');
            $target = match ($mode) {'add' => $before + $value, 'sub' => $before - $value, 'set' => $value};

            return $this->apply($user, self::clamp($target) - $before, 'Adjusted by admin: ' . $reason, null, null, null, $admin->id);
        });
    }

    /** Points for a paid installment, from how it was paid. Null = no event (admin Mark Paid, Pay Remaining Balance). */
    public function onPaid(InstallmentSchedule $i, string $wasStatus, bool $hadFailedCharge, string $via, string $today): ?StandingEvent
    {
        $p = config('installments.points');
        $no = $i->installment_no;
        $of = InstallmentSchedule::where('payment_id', $i->payment_id)->count();
        $label = "Installment {$no} of {$of}";

        if (in_array($via, ['admin', 'pay_full'], true)) {
            return null;
        }

        [$points, $text] = match (true) {
            $wasStatus === 'overdue'   => [$p['paid_when_overdue'], "{$label} paid after it was Overdue"],
            $hadFailedCharge           => [$p['paid_after_failure'], "{$label} paid after a failed charge, within the grace period"],
            $via === 'auto'            => [$p['auto_debit_on_time'], "{$label} paid on its due date by auto-debit"],
            $today < $i->due_date->toDateString() => [$p['paid_early'], "{$label} paid early with Pay Now"],
            default                    => [$p['pay_now_on_due_date'], "{$label} paid on its due date with Pay Now"],
        };

        return $this->apply($i->user ?? User::find($i->user_id), (int) $points, $text, "inst:{$i->id}:paid", $i->id, $i->payment_id);
    }

    public function onOverdue(InstallmentSchedule $i): ?StandingEvent
    {
        $of = InstallmentSchedule::where('payment_id', $i->payment_id)->count();

        return $this->apply(User::findOrFail($i->user_id), (int) config('installments.points.became_overdue'),
            "Installment {$i->installment_no} of {$of} became Overdue", "inst:{$i->id}:overdue", $i->id, $i->payment_id);
    }

    /** +points when every installment of the plan is paid and none of them was ever Overdue. */
    public function onPlanProgress(int $paymentId): ?StandingEvent
    {
        $rows = InstallmentSchedule::where('payment_id', $paymentId)->get();
        if ($rows->isEmpty() || $rows->contains(fn ($r) => $r->status !== 'paid') || $rows->contains(fn ($r) => $r->overdue_at !== null)) {
            return null;
        }
        $payment = Payment::find($paymentId);
        if (!$payment) {
            return null;
        }

        return $this->apply(User::findOrFail($payment->user_id), (int) config('installments.points.plan_finished_clean'),
            'Plan for order ' . optional($payment->order)->order_number . ' finished with no Overdue installment', "plan:{$paymentId}:finished", null, $paymentId);
    }
}
