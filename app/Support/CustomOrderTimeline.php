<?php

namespace App\Support;

use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\DesignAgreement;
use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * One chronological list of what happened to a custom order, built
 * from custom_order_events plus the payment ledger.
 *
 *  forCustomer(): only things the customer is meant to see, worded
 *    for them. No internal call notes, no admin names (always "our
 *    team"), no IP or user agent, no hash checks, no cancel reasons,
 *    no quote drafts.
 *  forAdmin(): everything, with who did it.
 *
 * Each entry: ['at' => Carbon, 'title' => string, 'detail' => ?string,
 * 'actor' => ?string, 'kind' => string].
 */
class CustomOrderTimeline
{
    private const TZ = 'Asia/Manila';

    public function forCustomer(CustomDesignRequest $request): array
    {
        $entries = [];

        foreach ($this->events($request) as $e) {
            $d = $e->data ?? [];
            $at = Carbon::instance($e->occurred_at);

            $entry = match ($e->type) {
                'submitted' => ['You sent your request to our team', null, 'request'],
                'request_updated' => ['You updated your request', null, 'request'],
                'call_update' => match ($d['outcome'] ?? '') {
                    'answered' => ['Our team called you', null, 'contact'],
                    'ringing'  => ['We called you but nobody answered', null, 'contact'],
                    default    => ["We tried to call you but couldn't get through", null, 'contact'],
                },
                'call_logged' => !empty($d['answered'])
                    ? ['Our team called you', null, 'contact']
                    : ["We tried to call you but couldn't get through", null, 'contact'],
                'marked_unreachable' => ["We couldn't reach you by phone", 'Please check that your phone number is correct.', 'contact'],
                'declined_by_admin' => ['Our team was unable to take on this request', $this->when($d['reason'] ?? null, 'Reason: '), 'closed'],
                'quote_sent' => (int) ($d['version'] ?? 1) > 1
                    ? ['Your quote was updated (version ' . (int) $d['version'] . ')', $this->quoteLine($d), 'quote']
                    : ['Your quote is ready', $this->quoteLine($d), 'quote'],
                'quote_expired' => ['Your quote expired', null, 'closed'],
                'quote_accepted' => [
                    'You accepted agreement ' . ($d['agreement_number'] ?? ''),
                    'Agreed on ' . $at->timezone(self::TZ)->format('M d, Y h:i A'),
                    'agreement',
                ],
                'quote_declined_by_customer' => ['You declined the quote', null, 'closed'],
                'agreement_expired' => ['The time to start payment passed, so the agreement expired', null, 'closed'],
                'order_placed' => ['Order ' . ($d['order_number'] ?? '') . ' was created', null, 'order'],
                'order_status_changed' => [$this->statusTitle((string) ($d['to'] ?? '')), null, 'order'],
                'order_confirmed' => ['Your order was confirmed', 'Your payment was received.', 'order'],
                default => null,   // drafts, supersessions, integrity checks: not for customers
            };

            if ($entry === null) {
                continue;
            }

            $entries[] = ['at' => $at, 'title' => $entry[0], 'detail' => $entry[1], 'actor' => null, 'kind' => $entry[2]];
        }

        foreach ($this->payments($request) as $p) {
            $entries[] = [
                'at'     => Carbon::instance($p->created_at),
                'title'  => 'Payment of ₱' . number_format((float) $p->amount, 2) . ' received',
                'detail' => null,
                'actor'  => null,
                'kind'   => 'payment',
            ];
        }

        return $this->sorted($entries);
    }

    public function forAdmin(CustomDesignRequest $request): array
    {
        $events = $this->events($request);
        $actors = User::whereIn('id', $events->where('actor_type', 'admin')->pluck('actor_id')->filter()->unique())
            ->get()->keyBy('id');

        $entries = [];

        foreach ($events as $e) {
            $d = $e->data ?? [];
            $at = Carbon::instance($e->occurred_at);

            $title = match ($e->type) {
                'submitted'            => 'Customer sent the request to the team',
                'request_updated'      => 'Customer updated the request',
                'call_update'          => 'Call update: ' . (\App\Support\CallUpdateMessage::LABELS[$d['outcome'] ?? ''] ?? 'sent')
                    . (!empty($d['attempts']) ? ', ' . \App\Support\CallUpdateMessage::times((int) $d['attempts']) . ' called' : ''),
                'call_logged'          => 'Call logged (attempt ' . ($d['attempt_no'] ?? '?') . ', ' . (!empty($d['answered']) ? 'answered' : 'no answer') . ')',
                'marked_unreachable'   => 'Marked unreachable, customer notified',
                'declined_by_admin'    => 'Declined by our team, customer notified',
                'quote_draft_created'  => 'Quote draft created (version ' . ($d['version'] ?? '?') . ')',
                'quote_draft_updated'  => 'Quote draft updated (version ' . ($d['version'] ?? '?') . ')',
                'quote_draft_deleted'  => 'Quote draft deleted',
                'quote_sent'           => 'Quote version ' . ($d['version'] ?? '?') . ' sent to the customer',
                'quote_superseded'     => 'Earlier quote version ' . ($d['version'] ?? '?') . ' replaced',
                'quote_expired'        => 'Quote version ' . ($d['version'] ?? '?') . ' expired',
                'quote_accepted'       => 'Customer accepted agreement ' . ($d['agreement_number'] ?? ''),
                'quote_declined_by_customer' => 'Customer declined the quote',
                'agreement_expired'    => 'Agreement ' . ($d['agreement_number'] ?? '') . ' expired: no payment started',
                'agreement_integrity_failed' => 'Agreement ' . ($d['agreement_number'] ?? '') . ' FAILED its hash check and was blocked',
                'order_placed'         => 'Order ' . ($d['order_number'] ?? '') . ' placed by the customer',
                'order_confirmed'      => 'Order confirmed by payment',
                'order_status_changed' => 'Order status: ' . str_replace('_', ' ', (string) ($d['from'] ?? '?')) . ' to ' . str_replace('_', ' ', (string) ($d['to'] ?? '?')),
                default                => ucfirst(str_replace('_', ' ', $e->type)),
            };

            $detail = collect([
                !empty($d['note']) ? 'Note: ' . $d['note'] : null,
                !empty($d['reason']) ? 'Reason: ' . $d['reason'] : null,
                isset($d['total']) && in_array($e->type, ['quote_sent', 'quote_accepted'], true)
                    ? 'Total ₱' . number_format((float) $d['total'], 2) : null,
                isset($d['upfront']) && $e->type === 'quote_accepted'
                    ? 'Upfront ₱' . number_format((float) $d['upfront'], 2) : null,
            ])->filter()->implode(' · ') ?: null;

            $entries[] = [
                'at'     => $at,
                'title'  => $title,
                'detail' => $detail,
                'actor'  => match ($e->actor_type) {
                    'admin'    => isset($actors[$e->actor_id])
                        ? trim($actors[$e->actor_id]->first_name . ' ' . $actors[$e->actor_id]->last_name)
                        : 'Admin',
                    'customer' => 'Customer',
                    default    => 'System',
                },
                'kind'   => str_contains($e->type, 'integrity') ? 'alert' : 'event',
            ];
        }

        foreach ($this->payments($request) as $p) {
            $entries[] = [
                'at'     => Carbon::instance($p->created_at),
                'title'  => 'Payment of ₱' . number_format((float) $p->amount, 2) . ' recorded (' . (RecordPayment::METHOD_LABELS[$p->method] ?? $p->method) . ')',
                'detail' => collect([
                    'Paid on ' . $p->paid_on->format('M d, Y'),
                    $p->gateway_reference ? 'Ref ' . $p->gateway_reference : null,
                    $p->note ? 'Note: ' . $p->note : null,
                ])->filter()->implode(' · '),
                'actor'  => $p->recorder ? trim($p->recorder->first_name . ' ' . $p->recorder->last_name) : 'System',
                'kind'   => 'payment',
            ];
        }

        // current flags have no timestamp of their own: put them last, at "now"
        $order = $this->order($request);
        if ($order && $order->needs_review) {
            $entries[] = [
                'at' => now(), 'title' => 'Order flagged for review', 'detail' => $order->review_reason,
                'actor' => 'System', 'kind' => 'alert',
            ];
        }
        if ($order && $order->reviewed_at) {
            $by = $order->reviewed_by ? User::find($order->reviewed_by) : null;
            $entries[] = [
                'at' => Carbon::instance($order->reviewed_at), 'title' => 'Review flag cleared', 'detail' => null,
                'actor' => $by ? trim($by->first_name . ' ' . $by->last_name) : 'Admin', 'kind' => 'event',
            ];
        }

        return $this->sorted($entries);
    }

    /* ───────────── internals ───────────── */

    private function events(CustomDesignRequest $request)
    {
        return CustomOrderEvent::where('request_id', $request->id)->orderBy('occurred_at')->orderBy('id')->get();
    }

    private function order(CustomDesignRequest $request): ?Order
    {
        $agreementIds = DesignAgreement::where('request_id', $request->id)->pluck('id');

        return $agreementIds->isEmpty() ? null : Order::whereIn('design_agreement_id', $agreementIds)->first();
    }

    private function payments(CustomDesignRequest $request)
    {
        $order = $this->order($request);

        return $order
            ? PaymentRecord::where('order_id', $order->id)->with('recorder')->orderBy('id')->get()
            : collect();
    }

    private function sorted(array $entries): array
    {
        usort($entries, fn ($a, $b) => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        return $entries;
    }

    private function when(?string $text, string $prefix): ?string
    {
        return $text !== null && trim($text) !== '' ? $prefix . trim($text) : null;
    }

    private function quoteLine(array $d): ?string
    {
        if (!isset($d['total'])) {
            return null;
        }

        $line = 'Total ₱' . number_format((float) $d['total'], 2);
        if (!empty($d['expires_at'])) {
            $line .= ', valid until ' . Carbon::parse($d['expires_at'])->timezone(self::TZ)->format('M d, Y h:i A');
        }

        return $line;
    }

    private function statusTitle(string $to): string
    {
        return match ($to) {
            'confirmed'        => 'Your order was confirmed',
            'in_production'    => 'Your order is in production',
            'ready'            => 'Your order is ready',
            'out_for_delivery' => 'Your order is out for delivery',
            'completed'        => 'Your order was completed',
            'cancelled'        => 'Your order was cancelled',
            default            => 'Your order was updated',
        };
    }
}
