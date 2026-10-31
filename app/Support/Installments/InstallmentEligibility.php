<?php

namespace App\Support\Installments;

use App\Models\User;

/**
 * Who may pay in installments, and for how long, decided only from the
 * customer's verified-ID flag, their Installment Standing and the order total.
 * The checkout page and the server use this one class.
 */
class InstallmentEligibility
{
    /**
     * @return array{allowed:bool, reason:?string, level:string, score:int, months:int, cap:?int, id_verified:bool}
     */
    public static function check(User $user, float $total): array
    {
        $score = InstallmentStanding::score($user);
        $level = InstallmentStanding::levelFor($score);
        $verified = (bool) $user->is_validated;

        $base = ['level' => $level['name'], 'score' => $score, 'months' => $level['months'], 'cap' => $level['cap'], 'id_verified' => $verified];

        if (!$verified) {
            return $base + ['allowed' => false, 'reason' => 'Verify your ID in your account to use installments.'];
        }
        if ($level['months'] <= 0) {
            return $base + ['allowed' => false, 'reason' => 'Installments are not available right now because of late payments. You can pay in full.'];
        }
        if ($level['cap'] !== null && $total > $level['cap']) {
            return $base + ['allowed' => false, 'reason' => "Your Installment Standing ({$level['name']}) allows installments on orders up to ₱" . number_format($level['cap'], 2) . '. You can pay in full.'];
        }

        return $base + ['allowed' => true, 'reason' => null];
    }

    /** Is this plan shape allowed for the customer on this order? Re-checked on the server at checkout. */
    public static function allows(User $user, float $total, int $months, int $frequencyDays): bool
    {
        $r = self::check($user, $total);

        return $r['allowed']
            && $months <= $r['months']
            && in_array($months, config('installments.durations'), true)
            && in_array($frequencyDays, config('installments.frequencies'), true);
    }
}
