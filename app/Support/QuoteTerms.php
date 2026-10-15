<?php

namespace App\Support;

use App\Models\CustomQuote;
use Illuminate\Support\Facades\Validator;

/**
 * Server-side validation and arithmetic for a quote's terms.
 *
 * Nothing posted by the browser is trusted for money: the delivery fee
 * is forced to 0 for pickup, the total is always price + delivery fee
 * computed here, and the deposit is checked against that total using
 * integer centavos (no float edge cases at exactly 50%).
 */
final class QuoteTerms
{
    /**
     * Validate raw form input and return the attributes to store on a
     * CustomQuote. Throws Illuminate\Validation\ValidationException
     * (field-keyed errors) on any problem.
     */
    public static function validate(array $input): array
    {
        $delivery = ($input['delivery_option'] ?? null) === 'delivery';
        $deposit  = ($input['payment_terms'] ?? null) === 'deposit';

        // A blank delivery fee on a delivery quote means the default.
        if ($delivery && ($input['delivery_fee'] ?? '') === '') {
            $input['delivery_fee'] = CustomQuote::DEFAULT_DELIVERY_FEE;
        }

        $money = ['regex:/^\d+(\.\d{1,2})?$/'];

        $validator = Validator::make($input, [
            'final_description' => ['required', 'string', 'max:3000'],
            'width_cm'          => ['required', 'numeric', 'min:0.1', 'max:9999'],
            'depth_cm'          => ['required', 'numeric', 'min:0.1', 'max:9999'],
            'height_cm'         => ['required', 'numeric', 'min:0.1', 'max:9999'],
            'material'          => ['required', 'string', 'max:100'],
            'finish'            => ['nullable', 'string', 'max:100'],
            'modifications'     => ['nullable', 'string', 'max:3000'],
            'price'             => array_merge(['required', 'numeric', 'gt:0', 'max:' . CustomQuote::MAX_PRICE], $money),
            'delivery_option'   => ['required', 'in:pickup,delivery'],
            'delivery_fee'      => $delivery ? array_merge(['required', 'numeric', 'min:0', 'max:99999.99'], $money) : ['nullable'],
            'delivery_address'  => $delivery ? ['required', 'string', 'max:500'] : ['nullable', 'string', 'max:500'],
            'production_days'   => ['required', 'integer', 'min:1', 'max:365'],
            'payment_terms'     => ['required', 'in:full,deposit'],
            'deposit_amount'    => $deposit ? array_merge(['required', 'numeric', 'gt:0'], $money) : ['nullable'],
            'installment_duration_months' => $deposit ? ['required', 'in:' . implode(',', InstallmentScheduler::ALLOWED_DURATION_MONTHS)] : ['nullable'],
            'installment_frequency_days'  => $deposit ? ['required', 'in:' . implode(',', InstallmentScheduler::ALLOWED_FREQUENCY_DAYS)] : ['nullable'],
            'call_notes'        => ['nullable', 'string', 'max:2000'],
        ], [
            'price.gt'                => 'The price must be more than 0.',
            'price.max'               => 'The price cannot be more than 999,999.00.',
            'price.regex'             => 'The price can have at most 2 decimal places.',
            'delivery_address.required' => 'A delivery address is required for delivery.',
            'deposit_amount.required' => 'Enter the deposit amount.',
            'installment_duration_months.required' => 'Choose how many months the balance is spread over.',
            'installment_frequency_days.required'  => 'Choose how often the balance is paid.',
        ]);

        $validator->after(function ($v) use ($input, $deposit, $delivery) {
            if (!$deposit || $v->errors()->has('price') || $v->errors()->has('deposit_amount')
                || $v->errors()->has('delivery_fee')) {
                return;
            }

            $total   = self::cents($input['price']) + ($delivery ? self::cents($input['delivery_fee']) : 0);
            $problem = self::depositProblem($total, self::cents($input['deposit_amount']));

            if ($problem) {
                $v->errors()->add('deposit_amount', $problem);
            }
        });

        $data = $validator->validate();

        return [
            'final_description' => trim($data['final_description']),
            'width_cm'          => round((float) $data['width_cm'], 2),
            'depth_cm'          => round((float) $data['depth_cm'], 2),
            'height_cm'         => round((float) $data['height_cm'], 2),
            'material'          => trim($data['material']),
            'finish'            => self::blank($data['finish'] ?? null),
            'modifications'     => self::blank($data['modifications'] ?? null),
            'price'             => round((float) $data['price'], 2),
            'delivery_option'   => $data['delivery_option'],
            'delivery_fee'      => $delivery ? round((float) $data['delivery_fee'], 2) : 0,
            'delivery_address'  => $delivery ? trim($data['delivery_address']) : null,
            'production_days'   => (int) $data['production_days'],
            'payment_terms'     => $data['payment_terms'],
            'deposit_amount'    => $deposit ? round((float) $data['deposit_amount'], 2) : null,
            'installment_duration_months' => $deposit ? (int) $data['installment_duration_months'] : null,
            'installment_frequency_days'  => $deposit ? (int) $data['installment_frequency_days'] : null,
            'call_notes'        => self::blank($data['call_notes'] ?? null),
        ];
    }

    /**
     * Money summary for a set of validated terms (array or CustomQuote):
     * the total, what's due up front, and the balance schedule built by
     * the same InstallmentScheduler code that creates the real rows.
     *
     * @return array{total:float, upfront:float, balance:float, minimum_deposit:float, schedule:array}
     */
    public static function summary(array|CustomQuote $terms): array
    {
        $t = $terms instanceof CustomQuote ? $terms->getAttributes() : $terms;

        $price    = (float) ($t['price'] ?? 0);
        $fee      = ($t['delivery_option'] ?? 'pickup') === 'delivery' ? (float) ($t['delivery_fee'] ?? 0) : 0.0;
        $total    = round($price + $fee, 2);
        $deposit  = ($t['payment_terms'] ?? 'full') === 'deposit';
        $upfront  = $deposit ? round((float) ($t['deposit_amount'] ?? 0), 2) : $total;
        $balance  = round($total - $upfront, 2);

        $schedule = [];
        if ($deposit && $balance > 0
            && in_array((int) ($t['installment_duration_months'] ?? 0), InstallmentScheduler::ALLOWED_DURATION_MONTHS, true)
            && in_array((int) ($t['installment_frequency_days'] ?? 0), InstallmentScheduler::ALLOWED_FREQUENCY_DAYS, true)) {
            $schedule = InstallmentScheduler::splitAmounts(
                $balance, (int) $t['installment_duration_months'], (int) $t['installment_frequency_days']
            );
        }

        return [
            'total'           => $total,
            'upfront'         => $upfront,
            'balance'         => $balance,
            'minimum_deposit' => InstallmentScheduler::minimumDeposit($total),
            'schedule'        => $schedule,
        ];
    }

    /** Why a deposit (in centavos) is not allowed for a total (in centavos), or null if it is. */
    public static function depositProblem(int $totalCents, int $depositCents): ?string
    {
        $minimum = (int) round(InstallmentScheduler::minimumDeposit($totalCents / 100) * 100);

        if ($depositCents >= $totalCents) {
            return 'The deposit must be less than the total. Choose "pay in full" instead.';
        }
        if ($depositCents < $minimum) {
            return sprintf(
                'The deposit must be at least %d%% of the total (₱%s).',
                InstallmentScheduler::MIN_DEPOSIT_PERCENT,
                number_format($minimum / 100, 2)
            );
        }

        return null;
    }

    private static function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private static function blank(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
