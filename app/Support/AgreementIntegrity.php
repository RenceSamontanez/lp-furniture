<?php

namespace App\Support;

use App\Models\CustomOrderEvent;
use App\Models\DesignAgreement;
use App\Models\Notification;
use App\Models\User;

/**
 * Re-verifies an agreement's SHA-256 every time it is shown. On a
 * mismatch the caller blocks the page; this records one system event
 * and alerts every admin (once per agreement until they read it).
 */
final class AgreementIntegrity
{
    public static function check(DesignAgreement $agreement): bool
    {
        if ($agreement->snapshotIsIntact()) {
            return true;
        }

        self::alert($agreement);

        return false;
    }

    private static function alert(DesignAgreement $agreement): void
    {
        $url   = route('admin.custom-orders.show', $agreement->request_id);
        $title = 'Agreement Integrity Check Failed';

        foreach (User::where('role', 'admin')->pluck('id') as $adminId) {
            $already = Notification::where('user_id', $adminId)
                ->where('title', $title)
                ->where('url', $url)
                ->where('message', 'like', '%' . $agreement->agreement_number . '%')
                ->where('is_read', false)
                ->exists();

            if ($already) {
                continue;
            }

            Notification::create([
                'user_id' => $adminId,
                'title'   => $title,
                'message' => NotificationTemplates::message('admin_integrity_failed', ['agreement' => $agreement->agreement_number]),
                'type'    => 'custom_order',
                'url'     => $url,
                'is_read' => false,
            ]);
        }

        CustomOrderEvent::create([
            'request_id'  => $agreement->request_id,
            'type'        => 'agreement_integrity_failed',
            'actor_type'  => CustomOrderEvent::ACTOR_SYSTEM,
            'actor_id'    => null,
            'data'        => ['agreement_id' => $agreement->id, 'agreement_number' => $agreement->agreement_number],
            'occurred_at' => now(),
        ]);
    }
}
