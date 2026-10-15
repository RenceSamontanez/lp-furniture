<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CustomAgreementController;
use App\Models\DesignAgreement;
use App\Support\AgreementIntegrity;

/**
 * Admin read-only views of an agreement record. Nothing here (or
 * anywhere) updates or deletes an agreement.
 */
class AgreementController extends Controller
{
    public function show($id)
    {
        $agreement = DesignAgreement::with(['request.user', 'finalizedBy'])->findOrFail($id);

        if (!AgreementIntegrity::check($agreement)) {
            return $this->noStore(response()->view('design.agreement-blocked', ['admin' => true], 409));
        }

        return $this->noStore(response()->view('admin.custom-orders.agreement', [
            'agreement' => $agreement,
            'terms'     => $agreement->terms_snapshot,
            'imageUrl'  => CustomAgreementController::imagePathOf($agreement)
                ? route('admin.agreements.image', $agreement->id) : null,
        ]));
    }

    public function printView($id)
    {
        $agreement = DesignAgreement::with('request')->findOrFail($id);

        if (!AgreementIntegrity::check($agreement)) {
            return $this->noStore(response()->view('design.agreement-blocked', ['admin' => true], 409));
        }

        return $this->noStore(response()->view('agreements.print', [
            'agreement' => $agreement,
            'terms'     => $agreement->terms_snapshot,
            'imageUrl'  => CustomAgreementController::imagePathOf($agreement)
                ? route('admin.agreements.image', $agreement->id) : null,
            'backUrl'   => route('admin.custom-orders.show', $agreement->request_id),
        ]));
    }

    public function image($id)
    {
        return CustomAgreementController::serveImage(DesignAgreement::findOrFail($id));
    }

    private function noStore($response)
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
