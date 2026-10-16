<?php

namespace App\Support;

use App\Models\AgreementPolicy;
use App\Models\BusinessSetting;
use App\Models\CustomDesignRequest;
use App\Models\CustomOrderCallLog;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Models\DesignAgreement;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place a custom-order request's pipeline_status is changed
 * after submission. Every method:
 *   1. locks the request row (lockForUpdate),
 *   2. rejects a stale form (lock_version mismatch),
 *   3. enforces the transition table below,
 *   4. writes a custom_order_events row (and any call-log / notification),
 *   5. bumps lock_version.
 * Controllers must not set pipeline_status themselves.
 *
 * Failures throw PipelineException with a message safe to show an admin.
 */
class CustomOrderPipeline
{
    /** Statuses the admin can still work (Stage 4 scope). */
    public const OPEN = ['submitted', 'contacting', 'unreachable'];

    /** from => allowed targets, for the Stage 4 actions. */
    public const TRANSITIONS = [
        'submitted'   => ['contacting', 'unreachable', 'declined_by_admin'],
        'contacting'  => ['unreachable', 'declined_by_admin'],
        'unreachable' => ['contacting', 'declined_by_admin'],
    ];

    /** Pipeline statuses a quote can be drafted or sent from (Stage 5). */
    public const QUOTE_FROM = ['submitted', 'contacting', 'unreachable', 'quoted', 'expired', 'declined_by_customer'];

    public const LABELS = [
        'submitted'            => 'Submitted',
        'contacting'           => 'Contacting',
        'unreachable'          => 'Unreachable',
        'quoted'               => 'Quoted',
        'accepted'             => 'Accepted',
        'ordered'              => 'Ordered',
        'expired'              => 'Expired',
        'declined_by_customer' => 'Declined by customer',
        'declined_by_admin'    => 'Declined',
        'cancelled'            => 'Cancelled',
    ];

    /**
     * Log one call attempt. The first call moves submitted → contacting;
     * an answered call moves unreachable → contacting. An unanswered
     * call on an unreachable request is recorded and changes nothing.
     */
    public function logCall(int $requestId, User $admin, bool $answered, ?string $note, int $lockVersion): CustomOrderCallLog
    {
        return DB::transaction(function () use ($requestId, $admin, $answered, $note, $lockVersion) {
            $request = $this->lock($requestId, $lockVersion);
            $this->requireOpen($request, 'log a call');

            $attempt = ((int) CustomOrderCallLog::where('request_id', $request->id)->max('attempt_no')) + 1;

            $log = CustomOrderCallLog::create([
                'request_id' => $request->id,
                'admin_id'   => $admin->id,
                'attempt_no' => $attempt,
                'called_at'  => now(), // app timezone is Asia/Manila
                'answered'   => $answered,
                'note'       => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]);

            $from = $request->pipeline_status;
            $to   = $from;
            if ($from === 'submitted' || ($from === 'unreachable' && $answered)) {
                $to = 'contacting';
            }

            $this->commit($request, $to, 'call_logged', $admin, [
                'attempt_no' => $attempt,
                'answered'   => $answered,
                'note'       => $log->note,
                'from'       => $from,
                'to'         => $to,
            ]);

            return $log;
        });
    }

    /**
     * The quick call update (Stage 13): one outcome, how many attempts,
     * an optional extra line. Writes a call log row, an event, ONE
     * customer notification with the fixed wording, and sets the status
     * (unreachable -> unreachable; ringing / answered -> contacting).
     * No call has to be logged before a quote any more.
     */
    public function sendCallUpdate(int $requestId, User $admin, string $outcome, ?int $attempts, ?string $note, int $lockVersion): CustomOrderCallLog
    {
        if (!isset(CallUpdateMessage::LABELS[$outcome])) {
            throw new PipelineException('Choose the call outcome.');
        }

        $needs = CallUpdateMessage::needsAttempts($outcome);
        if ($needs && ($attempts === null || $attempts < 1 || $attempts > 20)) {
            throw new PipelineException('Call attempts must be a number from 1 to 20.');
        }
        $attempts = $needs ? $attempts : null;

        return DB::transaction(function () use ($requestId, $admin, $outcome, $attempts, $note, $lockVersion) {
            $request = $this->lock($requestId, $lockVersion);
            $this->requireOpen($request, 'send a call update');

            $note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $from = $request->pipeline_status;
            $to   = CallUpdateMessage::STATUS[$outcome];
            $n    = (int) CustomOrderCallLog::where('request_id', $request->id)->max('attempt_no') + 1;

            $summary = CallUpdateMessage::LABELS[$outcome]
                . ($attempts ? ', ' . CallUpdateMessage::times($attempts) . ' called' : '');

            $log = CustomOrderCallLog::create([
                'request_id' => $request->id,
                'admin_id'   => $admin->id,
                'attempt_no' => $n,
                'called_at'  => now(),
                'answered'   => $outcome === CallUpdateMessage::ANSWERED,
                'note'       => $summary . ($note ? '. ' . $note : ''),
            ]);

            $message = CallUpdateMessage::build($outcome, $request, $attempts ?? 1, $note);

            $this->commit($request, $to, 'call_update', $admin, [
                'outcome'  => $outcome,
                'attempts' => $attempts,
                'note'     => $note,
                'from'     => $from,
                'to'       => $to,
                'message'  => $message,
            ]);

            $this->notifyCustomer($request, match ($outcome) {
                CallUpdateMessage::ANSWERED => 'Update on Your Custom Request',
                CallUpdateMessage::RINGING  => 'We Called You',
                default                     => 'We Could Not Reach You',
            }, $message);

            return $log;
        });
    }

    /** Needs at least one unanswered call on record. Customer is notified. */
    public function markUnreachable(int $requestId, User $admin, int $lockVersion): CustomDesignRequest
    {
        return DB::transaction(function () use ($requestId, $admin, $lockVersion) {
            $request = $this->lock($requestId, $lockVersion);
            $this->requireTransition($request, 'unreachable');

            $unanswered = CustomOrderCallLog::where('request_id', $request->id)->where('answered', false)->count();
            if ($unanswered < 1) {
                throw new PipelineException('Log at least one unanswered call before marking this request unreachable.');
            }

            $from = $request->pipeline_status;
            $this->commit($request, 'unreachable', 'marked_unreachable', $admin, [
                'from' => $from, 'to' => 'unreachable', 'unanswered_calls' => $unanswered,
            ]);

            $phone   = $request->contact_phone ?: $request->user?->phone_number;
            $contact = BusinessSetting::current()->phone;

            $this->notifyCustomer(
                $request,
                'We Could Not Reach You',
                "We tried to reach you about {$request->title} but couldn't get through. "
                . "Please check that {$phone} is correct, or message us at {$contact}."
            );

            return $request;
        });
    }

    /** Terminal. A reason is required and is shown to the customer. */
    public function decline(int $requestId, User $admin, ?string $reason, int $lockVersion): CustomDesignRequest
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new PipelineException('A reason is required to decline a request.');
        }

        return DB::transaction(function () use ($requestId, $admin, $reason, $lockVersion) {
            $request = $this->lock($requestId, $lockVersion);
            $this->requireTransition($request, 'declined_by_admin');

            $from = $request->pipeline_status;

            // Legacy `status`/`admin_notes` are kept in step because the
            // customer's design page and the old admin list still read them.
            $request->forceFill([
                'status'         => 'declined',
                'admin_notes'    => $reason,
                'decline_reason' => $reason,
            ]);

            $this->commit($request, 'declined_by_admin', 'declined_by_admin', $admin, [
                'from' => $from, 'to' => 'declined_by_admin', 'reason' => $reason,
            ]);

            $this->notifyCustomer(
                $request,
                'Custom Request Update',
                "We're unable to take on {$request->title}. Reason: {$reason}"
            );

            return $request;
        });
    }

    /* ───────────── quotes (Stage 5) ───────────── */

    /**
     * Start a draft. First quote: needs status contacting and an
     * answered call. Later quotes (revision of a sent quote, or a new
     * version after expiry / customer decline) remember the previous
     * price. Never two drafts for one request.
     */
    public function createDraft(int $requestId, User $admin, array $terms, int $lockVersion): CustomQuote
    {
        return DB::transaction(function () use ($requestId, $admin, $terms, $lockVersion) {
            $request = $this->lock($requestId, $lockVersion);
            $this->requireQuotable($request);

            if (CustomQuote::where('request_id', $request->id)->where('status', CustomQuote::STATUS_DRAFT)->exists()) {
                throw new PipelineException('There is already a draft quote for this request. Edit or delete it first.');
            }

            $attrs   = QuoteTerms::validate($terms);
            $latest  = CustomQuote::where('request_id', $request->id)->orderByDesc('version')->first();
            $version = ($latest?->version ?? 0) + 1;

            $quote = CustomQuote::create(array_merge($attrs, [
                'request_id'     => $request->id,
                'version'        => $version,
                'status'         => CustomQuote::STATUS_DRAFT,
                'previous_price' => $latest?->price,
                'created_by'     => $admin->id,
            ]));

            $this->commit($request, $request->pipeline_status, 'quote_draft_created', $admin, [
                'quote_id' => $quote->id, 'version' => $version, 'price' => $quote->price,
            ]);

            return $quote;
        });
    }

    public function updateDraft(int $requestId, int $quoteId, User $admin, array $terms, int $lockVersion): CustomQuote
    {
        return DB::transaction(function () use ($requestId, $quoteId, $admin, $terms, $lockVersion) {
            $request = $this->lock($requestId, $lockVersion);
            $quote   = $this->lockDraft($request, $quoteId);

            $quote->update(QuoteTerms::validate($terms));

            $this->commit($request, $request->pipeline_status, 'quote_draft_updated', $admin, [
                'quote_id' => $quote->id, 'version' => $quote->version, 'price' => $quote->price,
            ]);

            return $quote;
        });
    }

    public function deleteDraft(int $requestId, int $quoteId, User $admin, int $lockVersion): void
    {
        DB::transaction(function () use ($requestId, $quoteId, $admin, $lockVersion) {
            $request = $this->lock($requestId, $lockVersion);
            $quote   = $this->lockDraft($request, $quoteId);
            $data    = ['quote_id' => $quote->id, 'version' => $quote->version];

            $quote->delete();

            $this->commit($request, $request->pipeline_status, 'quote_draft_deleted', $admin, $data);
        });
    }

    /**
     * Send a draft to the customer: draft -> sent, a sent predecessor
     * becomes superseded, expires in CustomQuote::VALID_DAYS days, and
     * the request moves to quoted. The customer is notified.
     */
    public function sendQuote(int $requestId, int $quoteId, User $admin, int $lockVersion): CustomQuote
    {
        return DB::transaction(function () use ($requestId, $quoteId, $admin, $lockVersion) {
            $request = $this->lock($requestId, $lockVersion);
            $this->requireQuotable($request);
            $quote = $this->lockDraft($request, $quoteId);

            // Re-validate what is stored: send never trusts an old save.
            try {
                QuoteTerms::validate($quote->only([
                    'final_description', 'width_cm', 'depth_cm', 'height_cm', 'material', 'finish',
                    'modifications', 'price', 'delivery_option', 'delivery_fee', 'delivery_address',
                    'production_days', 'payment_terms', 'deposit_amount',
                    'installment_duration_months', 'installment_frequency_days', 'call_notes',
                ]));
            } catch (\Illuminate\Validation\ValidationException $e) {
                throw new PipelineException('This draft is incomplete: ' . $e->validator->errors()->first());
            }

            $superseded = CustomQuote::where('request_id', $request->id)
                ->where('status', CustomQuote::STATUS_SENT)
                ->where('id', '!=', $quote->id)
                ->lockForUpdate()
                ->get();

            $previous = $superseded->sortByDesc('version')->first()
                ?? CustomQuote::where('request_id', $request->id)->where('id', '!=', $quote->id)->orderByDesc('version')->first();

            foreach ($superseded as $old) {
                $old->update(['status' => CustomQuote::STATUS_SUPERSEDED]);
                CustomOrderEvent::create([
                    'request_id' => $request->id, 'type' => 'quote_superseded',
                    'actor_type' => CustomOrderEvent::ACTOR_ADMIN, 'actor_id' => $admin->id,
                    'data' => ['quote_id' => $old->id, 'version' => $old->version, 'by_version' => $quote->version],
                    'occurred_at' => now(),
                ]);
            }

            $quote->update([
                'status'         => CustomQuote::STATUS_SENT,
                'sent_at'        => now(),
                'sent_by'        => $admin->id,
                'expires_at'     => now()->addDays(CustomQuote::VALID_DAYS),
                'previous_price' => $previous?->price ?? $quote->previous_price,
            ]);

            $from = $request->pipeline_status;
            $this->commit($request, 'quoted', 'quote_sent', $admin, [
                'quote_id' => $quote->id, 'version' => $quote->version, 'from' => $from, 'to' => 'quoted',
                'price' => $quote->price, 'total' => $quote->totalAmount(),
                'previous_price' => $quote->previous_price, 'expires_at' => $quote->expires_at->toIso8601String(),
            ]);

            $total  = number_format($quote->totalAmount(), 2);
            $expiry = $quote->expires_at->timezone('Asia/Manila')->format('M d, Y h:i A');

            if ($quote->version > 1 && $quote->previous_price !== null) {
                $this->notifyCustomer(
                    $request,
                    'Your Quote Was Updated',
                    "Your quote for {$request->title} was updated (version {$quote->version}, ₱"
                    . number_format((float) $quote->previous_price, 2) . ' → ₱' . number_format((float) $quote->price, 2)
                    . '). The previous quote can no longer be accepted.'
                );
            } else {
                $this->notifyCustomer(
                    $request,
                    'Your Custom Quote Is Ready',
                    "Your quote for {$request->title} is ready: ₱{$total}. Please review and accept by {$expiry}."
                );
            }

            return $quote;
        });
    }

    /**
     * Move every sent quote past its expiry to expired, and its request
     * from quoted to expired. Idempotent; safe from the command, the
     * lazy page-load check and concurrent callers (each quote is
     * re-checked under the request row lock).
     */
    public function expireStaleQuotes(): int
    {
        $ids = CustomQuote::where('status', CustomQuote::STATUS_SENT)
            ->where('expires_at', '<=', now())
            ->pluck('id');

        $expired = 0;

        foreach ($ids as $quoteId) {
            $expired += DB::transaction(function () use ($quoteId) {
                $peek = CustomQuote::find($quoteId);
                if (!$peek) {
                    return 0;
                }

                $request = CustomDesignRequest::with('user')->lockForUpdate()->find($peek->request_id);
                $quote   = CustomQuote::lockForUpdate()->find($quoteId);

                if (!$request || !$quote || $quote->status !== CustomQuote::STATUS_SENT || $quote->expires_at > now()) {
                    return 0;
                }

                $this->expireLocked($request, $quote);

                return 1;
            });
        }

        return $expired;
    }

    /**
     * An accepted agreement nobody started paying for: past
     * payment_due_at with no order, the request moves accepted ->
     * expired (events, customer and admin notifications). An order that
     * already exists is never touched here: an unpaid customized order
     * past the window is only shown as "overdue, unpaid" to admins.
     * Idempotent and safe from the command, lazy page loads and
     * concurrent callers (re-checked under the request row lock).
     */
    public function expireUnpaidAgreements(): int
    {
        $ids = CustomDesignRequest::where('pipeline_status', 'accepted')
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<=', now())
            ->pluck('id');

        $expired = 0;

        foreach ($ids as $requestId) {
            $expired += DB::transaction(function () use ($requestId) {
                $request = CustomDesignRequest::with('user')->lockForUpdate()->find($requestId);

                if (!$request || $request->pipeline_status !== 'accepted'
                    || $request->payment_due_at === null || $request->payment_due_at > now()) {
                    return 0;
                }

                $agreement = DesignAgreement::where('request_id', $request->id)->orderByDesc('id')->first();

                if (!$agreement || \App\Models\Order::where('design_agreement_id', $agreement->id)->exists()) {
                    return 0;
                }

                $request->forceFill([
                    'pipeline_status' => 'expired',
                    'lock_version'    => (int) $request->lock_version + 1,
                ])->save();

                CustomOrderEvent::create([
                    'request_id'  => $request->id,
                    'type'        => 'agreement_expired',
                    'actor_type'  => CustomOrderEvent::ACTOR_SYSTEM,
                    'actor_id'    => null,
                    'data'        => [
                        'from' => 'accepted', 'to' => 'expired', 'agreement_id' => $agreement->id,
                        'agreement_number' => $agreement->agreement_number,
                        'payment_due_at' => $request->payment_due_at->toIso8601String(),
                    ],
                    'occurred_at' => now(),
                ]);

                $when = $request->payment_due_at->timezone('Asia/Manila')->format('M d, Y h:i A');
                $this->notifyCustomer(
                    $request,
                    'Agreement Expired',
                    "The time to start payment for agreement {$agreement->agreement_number} ({$request->title}) passed on {$when}, "
                    . "so it has expired. Contact us if you'd still like to proceed.",
                    route('design.show', $request->id)
                );

                $name = trim($request->user->first_name . ' ' . $request->user->last_name);
                $this->notifyAdmins(
                    $request,
                    'Agreement Expired Unpaid',
                    "Agreement {$agreement->agreement_number} for {$request->title} ({$name}) expired: no payment was started by {$when}."
                );

                return 1;
            });
        }

        return $expired;
    }

    /**
     * Expire one sent quote whose request row and quote row the caller
     * already holds locked: quote -> expired, request quoted -> expired,
     * event, customer and admin notifications. Shared by the sweep and
     * by acceptance (which finds the quote expired at the moment of click).
     */
    private function expireLocked(CustomDesignRequest $request, CustomQuote $quote): void
    {
        $quote->update(['status' => CustomQuote::STATUS_EXPIRED]);

        $from = $request->pipeline_status;
        $to   = $from === 'quoted' ? 'expired' : $from;
        $request->forceFill([
            'pipeline_status' => $to,
            'lock_version'    => (int) $request->lock_version + 1,
        ])->save();

        CustomOrderEvent::create([
            'request_id'  => $request->id,
            'type'        => 'quote_expired',
            'actor_type'  => CustomOrderEvent::ACTOR_SYSTEM,
            'actor_id'    => null,
            'data'        => ['quote_id' => $quote->id, 'version' => $quote->version, 'from' => $from, 'to' => $to],
            'occurred_at' => now(),
        ]);

        $when = $quote->expires_at->timezone('Asia/Manila')->format('M d, Y');
        $this->notifyCustomer(
            $request,
            'Your Quote Has Expired',
            "Your quote for {$request->title} expired on {$when}. Contact us if you'd still like to proceed."
        );

        $name = trim($request->user->first_name . ' ' . $request->user->last_name);
        $this->notifyAdmins(
            $request,
            'Quote Expired',
            "Quote for {$request->title} ({$name}) expired without acceptance."
        );
    }

    /* ───────────── customer review: accept / decline (Stage 6) ───────────── */

    public const ACCEPTED = 'accepted';
    public const ALREADY  = 'already';
    public const EXPIRED  = 'expired';

    /**
     * Customer accepts a sent quote.
     *
     * Everything is read from the stored quote under the request row
     * lock; nothing the browser posted is used for price or terms.
     * $referenceImage is the already-copied stable image path (relative
     * to the local disk) or null; the caller owns deleting that copy
     * unless this returns ACCEPTED.
     *
     * Returns ['outcome' => ACCEPTED|ALREADY|EXPIRED, 'agreement' => ?DesignAgreement].
     * ALREADY means this quote already has an agreement (a double click,
     * or a second tab); EXPIRED means the quote ran out at the moment of
     * the click and was expired here. Anything else throws PipelineException.
     */
    public function acceptQuote(
        int $requestId,
        int $quoteId,
        User $customer,
        string $ip,
        ?string $userAgent,
        ?string $referenceImage
    ): array {
        return DB::transaction(function () use ($requestId, $quoteId, $customer, $ip, $userAgent, $referenceImage) {
            $request = CustomDesignRequest::with('user')->lockForUpdate()->find($requestId);

            if (!$request || (int) $request->user_id !== (int) $customer->id) {
                throw new PipelineException('That request could not be found.');
            }

            $quote = CustomQuote::where('request_id', $request->id)->lockForUpdate()->find($quoteId);
            if (!$quote) {
                throw new PipelineException('That quote could not be found.');
            }

            // Double click / second tab: the quote was already accepted.
            if ($existing = DesignAgreement::where('quote_id', $quote->id)->first()) {
                return ['outcome' => self::ALREADY, 'agreement' => $existing];
            }

            if ($quote->status !== CustomQuote::STATUS_SENT || $request->pipeline_status !== 'quoted') {
                throw new PipelineException('This quote can no longer be accepted. A newer version may have been sent, or it has expired or been withdrawn.');
            }

            $latestSent = CustomQuote::where('request_id', $request->id)
                ->where('status', CustomQuote::STATUS_SENT)->max('version');
            if ((int) $latestSent !== (int) $quote->version) {
                throw new PipelineException('This is no longer the current quote. Please review the newer version.');
            }

            $now = now();
            if ($quote->expires_at === null || $quote->expires_at <= $now) {
                $this->expireLocked($request, $quote);

                return ['outcome' => self::EXPIRED, 'agreement' => null];
            }

            $policy = AgreementPolicy::current();
            if (!$policy) {
                throw new PipelineException('The agreement policy is not available right now. Please contact us.');
            }
            if (!$quote->sent_by) {
                throw new PipelineException('This quote has no sender on record and cannot be accepted.');
            }

            // sent -> accepted, only if it is still sent.
            $changed = CustomQuote::where('id', $quote->id)
                ->where('status', CustomQuote::STATUS_SENT)
                ->update(['status' => CustomQuote::STATUS_ACCEPTED]);

            if ($changed === 0) {
                $existing = DesignAgreement::where('quote_id', $quote->id)->first();
                if ($existing) {
                    return ['outcome' => self::ALREADY, 'agreement' => $existing];
                }
                throw new PipelineException('This quote can no longer be accepted.');
            }

            $quote->refresh();
            $admin = User::find($quote->sent_by);

            $dueAt  = AgreementTerms::paymentDueAt($quote->expires_at, $now);
            $local  = $now->copy()->timezone(AgreementTerms::TZ);
            $localS = $local->format('Y-m-d H:i:s T');

            $terms = AgreementTerms::fromQuote($quote, $request, $policy, $now, $referenceImage);
            $customerName = trim($request->user->first_name . ' ' . $request->user->last_name);

            $agreement = null;
            for ($attempt = 0; $attempt < 5 && !$agreement; $attempt++) {
                $number = $this->nextAgreementNumber($local, $attempt);

                $snapshot = $terms + [
                    'agreement_number'  => $number,
                    'accepted_at_local' => $localS,
                    'payment_due_at'    => $dueAt->copy()->timezone(AgreementTerms::TZ)->format('Y-m-d H:i:s'),
                    'people' => [
                        'customer' => [
                            'id'    => $request->user->id,
                            'name'  => $customerName,
                            'email' => $request->user->email,
                            'phone' => $request->contact_phone ?: $request->user->phone_number,
                        ],
                        'finalized_by' => [
                            'id'   => $admin?->id,
                            'name' => $admin ? trim($admin->first_name . ' ' . $admin->last_name) : null,
                        ],
                    ],
                ];

                try {
                    // Nested transaction = savepoint, so a number collision
                    // can be retried without losing the quote update above.
                    $agreement = DB::transaction(fn () => DesignAgreement::create([
                        'agreement_number'  => $number,
                        'request_id'        => $request->id,
                        'quote_id'          => $quote->id,
                        'quote_version'     => $quote->version,
                        'user_id'           => $customer->id,
                        'finalized_by'      => $quote->sent_by,
                        'sent_at'           => $quote->sent_at,
                        'accepted_at'       => $now,
                        'accepted_at_local' => $localS,
                        'ip_address'        => mb_substr($ip, 0, 45),
                        'user_agent'        => $userAgent !== null ? mb_substr($userAgent, 0, 512) : null,
                        'policy_version'    => $policy->version,
                        'terms_snapshot'    => $snapshot,
                        'snapshot_sha256'   => DesignAgreement::hashSnapshot($snapshot),
                        'total_amount'      => $terms['money']['total'],
                        'upfront_amount'    => $terms['money']['upfront'],
                    ]));
                } catch (QueryException $e) {
                    if (!str_contains($e->getMessage(), 'agreement_number')) {
                        throw $e;
                    }
                }
            }

            if (!$agreement) {
                throw new PipelineException('Could not generate an agreement number. Please try again.');
            }

            $from = $request->pipeline_status;
            $request->forceFill(['payment_due_at' => $dueAt]);
            $this->commit($request, 'accepted', 'quote_accepted', $customer, [
                'from' => $from, 'to' => 'accepted',
                'quote_id' => $quote->id, 'version' => $quote->version,
                'agreement_id' => $agreement->id, 'agreement_number' => $agreement->agreement_number,
                'total' => $terms['money']['total'], 'upfront' => $terms['money']['upfront'],
                'payment_due_at' => $dueAt->toIso8601String(),
            ], CustomOrderEvent::ACTOR_CUSTOMER);

            $when    = $local->format('M d, Y h:i A');
            $dueText = $dueAt->copy()->timezone(AgreementTerms::TZ)->format('M d, Y h:i A');
            $no      = $agreement->agreement_number;

            $this->notifyCustomer(
                $request,
                'Agreement Accepted',
                "You accepted agreement {$no} for {$request->title}. Agreed on {$when}. "
                . 'Next: pay ₱' . number_format((float) $terms['money']['upfront'], 2) . " by {$dueText}.",
                route('design.agreement', $agreement->id)
            );
            $this->notifyAdmins(
                $request,
                'Agreement Accepted',
                "{$customerName} accepted agreement {$no} for {$request->title} (₱"
                . number_format((float) $terms['money']['total'], 2) . "). Agreed on {$when}."
            );

            return ['outcome' => self::ACCEPTED, 'agreement' => $agreement];
        });
    }

    /** Customer declines the sent quote; an optional reason is kept and shown to admins. */
    public function declineQuote(int $requestId, int $quoteId, User $customer, ?string $reason): CustomDesignRequest
    {
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        return DB::transaction(function () use ($requestId, $quoteId, $customer, $reason) {
            $request = CustomDesignRequest::with('user')->lockForUpdate()->find($requestId);

            if (!$request || (int) $request->user_id !== (int) $customer->id) {
                throw new PipelineException('That request could not be found.');
            }

            $quote = CustomQuote::where('request_id', $request->id)->lockForUpdate()->find($quoteId);

            if (!$quote || $quote->status !== CustomQuote::STATUS_SENT || $request->pipeline_status !== 'quoted') {
                throw new PipelineException('This quote can no longer be declined; it is not the current open quote.');
            }

            $quote->update([
                'status'                  => CustomQuote::STATUS_DECLINED,
                'customer_decline_reason' => $reason,
            ]);

            $from = $request->pipeline_status;
            $this->commit($request, 'declined_by_customer', 'quote_declined_by_customer', $customer, [
                'from' => $from, 'to' => 'declined_by_customer',
                'quote_id' => $quote->id, 'version' => $quote->version, 'reason' => $reason,
            ], CustomOrderEvent::ACTOR_CUSTOMER);

            $name = trim($request->user->first_name . ' ' . $request->user->last_name);

            $this->notifyCustomer($request, 'Quote Declined', "You declined the quote for {$request->title}.");
            $this->notifyAdmins(
                $request,
                'Quote Declined',
                "{$name} declined the quote for {$request->title}. Reason: " . ($reason ?? 'none given') . '.'
            );

            return $request;
        });
    }

    private function nextAgreementNumber(Carbon $local, int $attempt): string
    {
        $prefix = 'LP-A-' . $local->format('Y') . '-';

        $last = DesignAgreement::where('agreement_number', 'like', $prefix . '%')
            ->orderByRaw('LENGTH(agreement_number) DESC')
            ->orderByDesc('agreement_number')
            ->value('agreement_number');

        $seq = $last ? ((int) substr($last, strlen($prefix))) : 0;

        return $prefix . str_pad((string) ($seq + 1 + $attempt), 4, '0', STR_PAD_LEFT);
    }

    private function notifyAdmins(CustomDesignRequest $request, string $title, string $message): void
    {
        foreach (User::where('role', 'admin')->pluck('id') as $adminId) {
            Notification::create([
                'user_id' => $adminId,
                'title'   => $title,
                'message' => $message,
                'type'    => 'custom_order',
                'url'     => route('admin.custom-orders.show', $request->id),
                'is_read' => false,
            ]);
        }
    }

    /* ───────────── internals ───────────── */

    private function lock(int $requestId, int $lockVersion): CustomDesignRequest
    {
        $request = CustomDesignRequest::with('user')->lockForUpdate()->findOrFail($requestId);

        if ((int) $request->lock_version !== $lockVersion) {
            throw new PipelineException(
                'This request was changed by someone else while you had it open. The page has been reloaded with the latest details. Please review and try again.',
                stale: true
            );
        }

        return $request;
    }

    private function requireQuotable(CustomDesignRequest $request): void
    {
        if (!in_array($request->pipeline_status, self::QUOTE_FROM, true)) {
            $label = self::LABELS[$request->pipeline_status] ?? 'not submitted';
            throw new PipelineException("A quote can't be drafted or sent while the request is {$label}.");
        }
    }

    private function requireAnsweredCall(CustomDesignRequest $request): void
    {
        if (!CustomOrderCallLog::where('request_id', $request->id)->where('answered', true)->exists()) {
            throw new PipelineException('Log at least one answered call before quoting this request.');
        }
    }

    private function lockDraft(CustomDesignRequest $request, int $quoteId): CustomQuote
    {
        $quote = CustomQuote::where('request_id', $request->id)->lockForUpdate()->find($quoteId);

        if (!$quote) {
            throw new PipelineException('That quote no longer exists.');
        }
        if ($quote->status !== CustomQuote::STATUS_DRAFT) {
            throw new PipelineException('Only a draft quote can be changed. Start a new version instead.');
        }

        return $quote;
    }

    private function requireOpen(CustomDesignRequest $request, string $action): void
    {
        if (!in_array($request->pipeline_status, self::OPEN, true)) {
            $label = self::LABELS[$request->pipeline_status] ?? 'not submitted';
            throw new PipelineException("You can't {$action} on a request that is {$label}.");
        }
    }

    private function requireTransition(CustomDesignRequest $request, string $to): void
    {
        $from = $request->pipeline_status;
        if ($from === $to) {
            throw new PipelineException('This request is already ' . strtolower(self::LABELS[$to]) . '.');
        }
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            $label = self::LABELS[$from] ?? 'not submitted';
            throw new PipelineException("A request that is {$label} can't be moved to " . strtolower(self::LABELS[$to] ?? $to) . '.');
        }
    }

    private function commit(CustomDesignRequest $request, string $to, string $eventType, User $admin, array $data, string $actorType = CustomOrderEvent::ACTOR_ADMIN): void
    {
        $request->forceFill([
            'pipeline_status' => $to,
            'lock_version'    => (int) $request->lock_version + 1,
        ])->save();

        CustomOrderEvent::create([
            'request_id'  => $request->id,
            'type'        => $eventType,
            'actor_type'  => $actorType,
            'actor_id'    => $admin->id,
            'data'        => $data,
            'occurred_at' => now(),
        ]);
    }

    private function notifyCustomer(CustomDesignRequest $request, string $title, string $message, ?string $url = null): void
    {
        Notification::create([
            'user_id' => $request->user_id,
            'title'   => $title,
            'message' => $message,
            'type'    => 'custom_order',
            'url'     => $url ?? route('design.show', $request->id),
            'is_read' => false,
        ]);
    }
}
