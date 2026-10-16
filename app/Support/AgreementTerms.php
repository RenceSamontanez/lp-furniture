<?php

namespace App\Support;

use App\Models\AgreementPolicy;
use App\Models\CustomDesignRequest;
use App\Models\CustomQuote;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The one place the agreed terms are assembled. The Review and Accept
 * page renders this array for the live quote, and acceptance stores
 * the very same array (plus who/when) as the agreement's terms_snapshot,
 * so what the customer saw is what is on record.
 *
 * Money is always a string with two decimals and dates are Asia/Manila
 * strings: floats and Carbon objects don't round-trip through JSON
 * identically, which would break the SHA-256 check.
 */
final class AgreementTerms
{
    public const TZ = 'Asia/Manila';

    /** D2: the later of quote expiry and acceptance + 24h. */
    public static function paymentDueAt(CarbonInterface $quoteExpiresAt, CarbonInterface $acceptedAt): Carbon
    {
        $grace = Carbon::instance($acceptedAt)->addHours(CustomQuote::PAYMENT_GRACE_HOURS);
        $expiry = Carbon::instance($quoteExpiresAt);

        return $expiry->greaterThan($grace) ? $expiry : $grace;
    }

    public static function fromQuote(
        CustomQuote $quote,
        CustomDesignRequest $request,
        AgreementPolicy $policy,
        CarbonInterface $asOf,
        ?string $referenceImage
    ): array {
        $sum   = QuoteTerms::summary($quote);
        $local = fn ($d) => $d ? Carbon::instance($d)->timezone(self::TZ)->format('Y-m-d H:i:s') : null;
        $money = fn ($n) => number_format((float) $n, 2, '.', '');
        $dim   = fn ($n) => $money($n);

        $delivery = $quote->delivery_option === 'delivery';
        $from     = Carbon::instance($asOf)->timezone(self::TZ);

        return [
            'design' => [
                'title'           => \App\Support\CustomOrderName::for($request->user),
                // New agreements record the type the customer chose. Nothing is added when
                // an older request has none, and existing snapshots are never rewritten.
                // The wood the customer chose, for new agreements. A special request records what it is and who supplies it.
                ...(filled($request->preferred_material) ? ['wood' => ['choice' => $request->preferred_material, 'label' => \App\Support\WoodChoices::label($request->preferred_material, $request->wood_special_details, $request->wood_supply), 'supply' => $request->wood_supply]] : []),
                ...(filled($request->furniture_type) ? ['furniture_type' => \App\Support\FurnitureTypes::label($request->furniture_type, $request->furniture_type_other)] : []),
                'request_id'      => $request->id,
                'reference_image' => $referenceImage,
            ],
            'quote' => [
                'id'            => $quote->id,
                'version'       => (int) $quote->version,
                'description'   => $quote->final_description,
                // Reading of the three numbers: Length, Width, Height (the older key names are kept).
                // New agreements also record the unit the customer typed them in.
                ...(filled($request->dimension_unit) ? ['dimension_unit' => $request->dimension_unit] : []),
                'width_cm'      => $dim($quote->width_cm),
                'depth_cm'      => $dim($quote->depth_cm),
                'height_cm'     => $dim($quote->height_cm),
                'material'      => $quote->material,
                'finish'        => $quote->finish,
                'modifications' => $quote->modifications,
                'sent_at'       => $local($quote->sent_at),
                'expires_at'    => $local($quote->expires_at),
            ],
            'delivery' => [
                'option'  => $delivery ? 'delivery' : 'pickup',
                'fee'     => $money($delivery ? $quote->delivery_fee : 0),
                'address' => $delivery ? $quote->delivery_address : null,
            ],
            'money' => [
                'price'          => $money($quote->price),
                'delivery_fee'   => $money($delivery ? $quote->delivery_fee : 0),
                'total'          => $money($sum['total']),
                'upfront'        => $money($sum['upfront']),
                'balance'        => $money($sum['balance']),
                'payment_terms'  => $quote->payment_terms,
                'deposit_amount' => $quote->payment_terms === 'deposit' ? $money($quote->deposit_amount) : null,
            ],
            'schedule' => array_map(fn ($row) => [
                'no'         => (int) $row['no'],
                'amount'     => $money($row['amount']),
                'day_offset' => (int) $row['day_offset'],
            ], $sum['schedule']),
            'schedule_every_days' => $quote->payment_terms === 'deposit' ? (int) $quote->installment_frequency_days : null,
            'production' => [
                'days'         => (int) $quote->production_days,
                'counted_from' => $from->format('Y-m-d'),
                'ready_about'  => WorkingDays::add($from, (int) $quote->production_days)->format('Y-m-d'),
            ],
            'policy' => [
                'version' => (int) $policy->version,
                'title'   => $policy->title,
                'text'    => $policy->body,
            ],
        ];
    }
}
