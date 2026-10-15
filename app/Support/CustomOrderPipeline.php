<?php

namespace App\Support;

use App\Models\BusinessSetting;
use App\Models\CustomDesignRequest;
use App\Models\CustomOrderCallLog;
use App\Models\CustomOrderEvent;
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
