<?php

namespace App\Support\Luma;

use App\Models\BusinessSetting;
use App\Support\InstallmentScheduler;

/**
 * The shop facts an answer can mention, as {{tokens}}. Answers never carry a number or a place of
 * their own: the owner-confirmed values live in config/luma.php, the rules in InstallmentScheduler.
 */
final class Facts
{
    /** @return array<string, string> token => text, for the language */
    public static function tokens(string $lang): array
    {
        $tl   = $lang === Language::TL;
        $days = config('luma.production_days');
        $area = config('luma.free_delivery');

        $site = $tl ? 'aming website' : 'our website';

        return [
            '{{phone}}'       => Contact::phone() ?? $site,
            '{{email}}'       => Contact::email() ?? $site,
            '{{facebook}}'    => Contact::facebook() ?? $site,
            '{{contact}}'     => Contact::sentence($lang),
            '{{days}}'        => $tl ? "{$days['min']} hanggang {$days['max']}" : "{$days['min']} to {$days['max']}",
            '{{hours}}'       => Hours::text($lang),
            '{{open_status}}' => Hours::status($lang),
            '{{free_area}}'   => $tl
                ? "humigit-kumulang {$area['min_km']} hanggang {$area['max_km']} km ang layo o mga {$area['min_minutes']} hanggang {$area['max_minutes']} minutong biyahe"
                : "roughly {$area['min_km']} to {$area['max_km']} km away or about a {$area['min_minutes']} to {$area['max_minutes']} minute drive",
            '{{fee_note}}'    => self::feeNote($tl),
            '{{catalog_down}}' => (string) InstallmentScheduler::CATALOG_DOWNPAYMENT_PERCENT,
            '{{deposit_min}}' => (string) InstallmentScheduler::MIN_DEPOSIT_PERCENT,
            '{{months}}'      => min(InstallmentScheduler::ALLOWED_DURATION_MONTHS) . ($tl ? ' hanggang ' : ' to ') . max(InstallmentScheduler::ALLOWED_DURATION_MONTHS),
            '{{frequency}}'   => implode($tl ? ' o ' : ' or ', InstallmentScheduler::ALLOWED_FREQUENCY_DAYS),
        ];
    }

    /** The default delivery fee from Business Settings only if the settings have one; otherwise the shop confirms the fee. */
    private static function feeNote(bool $tl): string
    {
        $fee = BusinessSetting::current()->getAttribute('default_delivery_fee');

        if (is_numeric($fee) && (float) $fee > 0) {
            $amount = 'PHP ' . number_format((float) $fee, 2);

            return $tl
                ? "ang karaniwang bayad sa delivery ay {$amount}, at kukumpirmahin pa rin po ito ng shop sa inyo."
                : "the usual delivery fee is {$amount}, and the shop confirms it with you.";
        }

        return $tl
            ? 'ang shop po ang magtatakda ng bayad sa delivery at kukumpirmahin muna ito sa inyo.'
            : 'the shop sets the delivery fee and confirms it with you first.';
    }
}
