<?php

namespace App\Http\Controllers;

use App\Models\DesignAgreement;
use App\Models\Order;
use App\Support\AgreementCheckout;
use App\Support\CheckoutRefused;
use App\Support\InstallmentScheduler;
use App\Support\OrderContact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Customer checkout for an accepted agreement (Stage 9).
 * Routes sit inside auth + validated; every lookup is scoped to
 * Auth::id(), so another customer's agreement is a 404. The form
 * posts only payment_method; everything else comes from the stored,
 * hash-verified terms_snapshot.
 */
class CustomCheckoutController extends Controller
{
    public function __construct(private AgreementCheckout $checkout)
    {
    }

    public function show($agreementId)
    {
        $agreement = DesignAgreement::where('user_id', Auth::id())->with(['request', 'quote'])->findOrFail($agreementId);

        if ($order = Order::where('design_agreement_id', $agreement->id)->first()) {
            return $this->noStore(redirect()->route('orders.show', $order->id)
                ->with('info', 'An order already exists for this agreement.'));
        }

        if ($problem = $this->checkout->problem($agreement, $agreement->request)) {
            return $this->noStore(redirect()->route('design.show', $agreement->request_id)
                ->withErrors(['quote' => $problem]));
        }

        $terms = $agreement->terms_snapshot;

        return $this->noStore(response()->view('design.agreement-checkout', [
            'agreement'     => $agreement,
            'designRequest' => $agreement->request,
            'terms'         => $terms,
            'due'           => $this->checkout->dueAt($agreement, $agreement->request),
            'imageUrl'      => CustomAgreementController::imagePathOf($agreement)
                ? route('design.agreement.image', $agreement->id) : null,
        ]));
    }

    public function place(Request $request, $agreementId)
    {
        $data = $request->validate(['payment_method' => 'required|in:stripe,gcash']);

        // 404 for anyone else's agreement, before anything else.
        $agreement = DesignAgreement::where('user_id', Auth::id())->findOrFail($agreementId);

        // A valid phone is required here too, and the full address when the agreement is for delivery (an order that already exists needs nothing new).
        $contact = Order::where('design_agreement_id', $agreement->id)->exists()
            ? null
            : OrderContact::fromRequest($request, Auth::user(), ($agreement->terms_snapshot['delivery']['option'] ?? 'pickup') === 'delivery');

        try {
            [$order, $created] = $this->checkout->place($agreement->id, Auth::user(), $data['payment_method'], $contact);
        } catch (CheckoutRefused $e) {
            return $this->noStore(redirect()->route('design.show', $agreement->request_id)
                ->withErrors(['quote' => $e->getMessage()]));
        }

        $order->load('payment');

        // Same destination as a catalog order: the normal Stripe / GCash page.
        $response = app(OrderController::class)->redirectForOrder($order);

        if (!$created) {
            $response->with('info', 'This agreement already has an order. Continue with it below.');
        }

        return $this->noStore($response);
    }

    private function noStore($response)
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
