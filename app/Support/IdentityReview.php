<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\User;
use App\Models\UserValidation;
use Illuminate\Support\Facades\DB;

/**
 * Every change to an identity submission goes through here. A submission is decided once (pending ->
 * approved|rejected) under a row lock and is then final; nothing in this class edits a decided row except the
 * one-time follow-up request on the customer's latest rejected attempt.
 */
final class IdentityReview
{
    /** @throws IdentityReviewException */
    public static function approve(int $validationId, array $confirmed, int $adminId): UserValidation
    {
        $keys = array_keys(config('identity.checks'));
        foreach ($keys as $key) {
            if (empty($confirmed[$key])) {
                throw new IdentityReviewException('Tick all three checks before approving.', 422);
            }
        }

        return DB::transaction(function () use ($validationId, $keys, $adminId) {
            $v = self::lockPending($validationId);

            $v->forceFill([
                'status'           => 'approved',
                'checks_confirmed' => array_fill_keys($keys, true),
                'rejection_reason' => null,
                'reviewed_by'      => $adminId,
                'reviewed_at'      => now(),
            ])->save();

            User::whereKey($v->user_id)->update(['is_validated' => true]);

            Notification::create([
                'user_id' => $v->user_id,
                'title'   => 'Identity Verified ✓',
                'message' => 'Your identity has been verified. You can now place orders.',
                'type'    => 'validation_approved',
                'url'     => route('home'),
                'is_read' => false,
            ]);

            return $v;
        });
    }

    /** @throws IdentityReviewException */
    public static function reject(int $validationId, string $reason, int $adminId): UserValidation
    {
        return DB::transaction(function () use ($validationId, $reason, $adminId) {
            $v = self::lockPending($validationId);

            $v->forceFill([
                'status'           => 'rejected',
                'rejection_reason' => $reason,
                'reviewed_by'      => $adminId,
                'reviewed_at'      => now(),
            ])->save();

            Notification::create([
                'user_id' => $v->user_id,
                'title'   => 'Identity Verification Rejected',
                'message' => $reason,
                'type'    => 'validation_rejected',
                'url'     => route('validation.show'),
                'is_read' => false,
            ]);

            return $v;
        });
    }

    /**
     * "Ask for a new submission": only on the customer's latest rejected attempt, once.
     *
     * @return bool true when the request was sent now, false when one was already on record (nothing is sent twice)
     * @throws IdentityReviewException
     */
    public static function requestNewSubmission(int $validationId, int $adminId): bool
    {
        return DB::transaction(function () use ($validationId, $adminId) {
            $v = UserValidation::whereKey($validationId)->lockForUpdate()->first();
            if (!$v) {
                throw new IdentityReviewException('That submission no longer exists.', 404);
            }
            if (!$v->isRejected()) {
                throw new IdentityReviewException('A new submission can only be requested after a rejection.', 409);
            }
            if (!$v->isLatestAttempt()) {
                throw new IdentityReviewException('The customer has already sent a newer submission.', 409);
            }
            if ($v->follow_up_requested_at !== null) {
                return false;
            }

            $v->forceFill(['follow_up_requested_at' => now(), 'follow_up_requested_by' => $adminId])->save();

            Notification::create([
                'user_id' => $v->user_id,
                'title'   => 'Please send new ID photos',
                'message' => 'We could not verify your last photos. Reason: ' . $v->rejection_reason . ' Please send a new submission.',
                'type'    => 'validation_rejected', // notifications.type is an enum; the follow-up belongs to the rejection
                'url'     => route('validation.show'),
                'is_read' => false,
            ]);

            return true;
        });
    }

    private static function lockPending(int $id): UserValidation
    {
        $v = UserValidation::whereKey($id)->lockForUpdate()->first();
        if (!$v) {
            throw new IdentityReviewException('That submission no longer exists.', 404);
        }
        if (!$v->isPending()) {
            throw new IdentityReviewException('This submission was already ' . $v->status . '. The decision is final.', 409);
        }

        return $v;
    }
}
