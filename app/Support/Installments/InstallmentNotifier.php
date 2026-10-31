<?php

namespace App\Support\Installments;

use App\Models\InstallmentSchedule;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\User;
use App\Support\NotificationTemplates;
use Illuminate\Support\Facades\Log;

/**
 * Customer and admin notices for installments, built from the existing
 * NotificationTemplates. A failure to notify is logged and never stops a charge.
 */
class InstallmentNotifier
{
    private function fmt($n): string
    {
        return '₱' . number_format((float) $n, 2);
    }

    private function base(InstallmentSchedule $i): array
    {
        $i->loadMissing('order', 'payment');

        return [
            'order_number' => $i->order->order_number,
            'no' => $i->installment_no,
            'of' => InstallmentSchedule::where('payment_id', $i->payment_id)->count(),
            'amount_text' => $this->fmt($i->amount_due),
            'due_date' => $i->due_date->format('M d, Y'),
        ];
    }

    private function customer(InstallmentSchedule $i, string $key, array $data, string $type = 'installment_due'): void
    {
        try {
            $t = NotificationTemplates::render($key, $data);
            Notification::create([
                'user_id' => $i->user_id, 'title' => $t['title'], 'message' => $t['message'],
                'type' => $type, 'url' => route('orders.show', $i->order_id), 'is_read' => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Installment notice {$key} could not be saved: " . $e->getMessage());
        }
    }

    private function admins(InstallmentSchedule $i, string $key, array $data): void
    {
        try {
            $t = NotificationTemplates::render($key, $data + ['customer' => trim(optional($i->user)->first_name . ' ' . optional($i->user)->last_name)]);
            foreach (User::where('role', 'admin')->pluck('id') as $adminId) {
                Notification::create([
                    'user_id' => $adminId, 'title' => $t['title'], 'message' => $t['message'],
                    'type' => 'general', 'url' => route('admin.installments.index'), 'is_read' => false,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("Installment admin notice {$key} could not be saved: " . $e->getMessage());
        }
    }

    private function methodLine(Payment $plan): string
    {
        if (PlanMode::autoCharges($plan)) {
            return ', charged automatically to your ' . trim(($plan->card_brand ?: 'card') . ($plan->card_last4 ? ' ending ' . $plan->card_last4 : ''));
        }

        return '. We will send you a Pay Now button on the due date';
    }

    public function planStarted(Payment $plan, int $count, float $each, float $down, int $everyDays, string $firstDue): void
    {
        $first = InstallmentSchedule::where('payment_id', $plan->id)->orderBy('installment_no')->first();
        if (!$first) {
            return;
        }
        $this->customer($first, 'installment_plan_started', [
            'order_number' => $first->order->order_number ?? optional($plan->order)->order_number, 'count' => $count, 'each_text' => $this->fmt($each),
            'every' => $everyDays, 'down_text' => $this->fmt($down), 'first_due' => $firstDue,
            'method_line' => $this->methodLine($plan), 'reminder_days' => config('installments.reminder_days_before'),
        ]);
    }

    public function reminder(InstallmentSchedule $i): void
    {
        $this->customer($i, 'installment_reminder', $this->base($i) + ['method_line' => $this->methodLine($i->payment)]);
    }

    public function receipt(InstallmentSchedule $i, float $balance, ?float $amountOverride = null): void
    {
        $data = $this->base($i) + ['balance_text' => $this->fmt($balance)];
        if ($amountOverride !== null) {
            $data['amount_text'] = $this->fmt($amountOverride);
        }
        $this->customer($i, 'installment_receipt', $data, 'payment_confirmed');
    }

    public function chargeFailed(InstallmentSchedule $i): void
    {
        $this->customer($i, 'installment_charge_failed', $this->base($i));
    }

    public function payLink(InstallmentSchedule $i): void
    {
        $this->customer($i, 'installment_pay_link', $this->base($i));
    }

    public function gcashDue(InstallmentSchedule $i): void
    {
        $this->customer($i, 'installment_gcash_due', $this->base($i));
    }

    public function overdue(InstallmentSchedule $i): void
    {
        $this->customer($i, 'installment_overdue', $this->base($i));
        $this->admins($i, 'admin_installment_overdue', $this->base($i));
    }

    public function needsAttention(InstallmentSchedule $i): void
    {
        $this->admins($i, 'admin_installment_attention', $this->base($i));
    }
}
