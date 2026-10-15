<?php

namespace App\Support;

use App\Models\BusinessSetting;
use App\Models\CustomDesignRequest;
use App\Models\CustomOrderCallLog;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Models\Notification;
use App\Models\User;
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
    public const QUOTE_FROM = ['contacting', 'quoted', 'expired', 'declined_by_customer'];

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

            if ($request->pipeline_status === 'contacting') {
                $this->requireAnsweredCall($request);
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

            if ($request->pipeline_status === 'contacting') {
                $this->requireAnsweredCall($request);
            }

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
                foreach (User::where('role', 'admin')->pluck('id') as $adminId) {
                    Notification::create([
                        'user_id' => $adminId,
                        'title'   => 'Quote Expired',
                        'message' => "Quote for {$request->title} ({$name}) expired without acceptance.",
                        'type'    => 'custom_order',
                        'url'     => route('admin.custom-orders.show', $request->id),
                        'is_read' => false,
                    ]);
                }

                return 1;
            });
        }

        return $expired;
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

    private function commit(CustomDesignRequest $request, string $to, string $eventType, User $admin, array $data): void
    {
        $request->forceFill([
            'pipeline_status' => $to,
            'lock_version'    => (int) $request->lock_version + 1,
        ])->save();

        CustomOrderEvent::create([
            'request_id'  => $request->id,
            'type'        => $eventType,
            'actor_type'  => CustomOrderEvent::ACTOR_ADMIN,
            'actor_id'    => $admin->id,
            'data'        => $data,
            'occurred_at' => now(),
        ]);
    }

    private function notifyCustomer(CustomDesignRequest $request, string $title, string $message): void
    {
        Notification::create([
            'user_id' => $request->user_id,
            'title'   => $title,
            'message' => $message,
            'type'    => 'custom_order',
            'url'     => route('design.show', $request->id),
            'is_read' => false,
        ]);
    }
}
