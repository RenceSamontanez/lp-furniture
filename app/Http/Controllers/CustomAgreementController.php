<?php

namespace App\Http\Controllers;

use App\Models\AgreementPolicy;
use App\Models\CustomDesignRequest;
use App\Models\CustomQuote;
use App\Models\DesignAgreement;
use App\Support\AgreementIntegrity;
use App\Support\AgreementTerms;
use App\Support\CustomOrderPipeline;
use App\Support\PipelineException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Customer side of Review and Accept (Stage 6).
 *
 * Every lookup is scoped to Auth::id(), so another customer's quote or
 * agreement is a 404. Nothing here updates or deletes an agreement:
 * the only write is CustomOrderPipeline::acceptQuote(), which inserts.
 * Pages and form responses are Cache-Control: no-store.
 */
class CustomAgreementController extends Controller
{
    public function __construct(private CustomOrderPipeline $pipeline)
    {
    }

    /* ───────────── review and accept ───────────── */

    public function review($id, $quoteId)
    {
        $this->pipeline->expireStaleQuotes();

        [$designRequest, $quote] = $this->ownedQuote($id, $quoteId);

        if ($agreement = $quote->agreement) {
            return $this->noStore(redirect()->route('design.agreement', $agreement->id));
        }

        if ($quote->status !== CustomQuote::STATUS_SENT || $designRequest->pipeline_status !== 'quoted') {
            return $this->noStore(redirect()->route('design.show', $designRequest->id)
                ->with('info', 'That quote is no longer open for acceptance. The latest status is shown below.'));
        }

        $policy = AgreementPolicy::current();
        $now    = now();

        return $this->noStore(response()->view('design.review', [
            'designRequest' => $designRequest,
            'quote'         => $quote,
            'terms'         => AgreementTerms::fromQuote($quote, $designRequest, $policy, $now, null),
            'dueIfAccepted' => AgreementTerms::paymentDueAt($quote->expires_at, $now),
            'policy'        => $policy,
        ]));
    }

    public function accept(Request $request, $id, $quoteId)
    {
        [$designRequest, $quote] = $this->ownedQuote($id, $quoteId);

        $request->validate(
            ['agree' => 'accepted'],
            ['agree.accepted' => 'Tick the box to confirm you agree to the Terms and Conditions.']
        );

        // Copy first, outside the transaction: a DB rollback can't undo a
        // file write. The copy is deleted unless the agreement is created.
        $copy = $quote->status === CustomQuote::STATUS_SENT
            ? $this->copyReferenceImage($designRequest, $quote)
            : null;

        try {
            $result = $this->pipeline->acceptQuote(
                $designRequest->id,
                $quote->id,
                Auth::user(),
                (string) $request->ip(),
                $request->userAgent(),
                $copy
            );
        } catch (PipelineException $e) {
            $this->discard($copy);

            return $this->noStore(redirect()->route('design.show', $designRequest->id)
                ->withErrors(['quote' => $e->getMessage()]));
        } catch (\Throwable $e) {
            $this->discard($copy);
            throw $e;
        }

        if ($result['outcome'] !== CustomOrderPipeline::ACCEPTED) {
            $this->discard($copy);
        }

        return match ($result['outcome']) {
            CustomOrderPipeline::ACCEPTED => $this->noStore(redirect()->route('design.agreement', $result['agreement']->id)
                ->with('success', 'Agreement accepted. A copy is on record below.')),
            CustomOrderPipeline::ALREADY => $this->noStore(redirect()->route('design.agreement', $result['agreement']->id)
                ->with('info', 'This quote was already accepted. Here is your agreement.')),
            default => $this->noStore(redirect()->route('design.show', $designRequest->id)
                ->withErrors(['quote' => 'This quote expired before it was accepted. Contact us if you would still like to proceed.'])),
        };
    }

    public function decline(Request $request, $id, $quoteId)
    {
        [$designRequest, $quote] = $this->ownedQuote($id, $quoteId);

        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        try {
            $this->pipeline->declineQuote($designRequest->id, $quote->id, Auth::user(), $data['reason'] ?? null);
        } catch (PipelineException $e) {
            return $this->noStore(redirect()->route('design.show', $designRequest->id)
                ->withErrors(['quote' => $e->getMessage()]));
        }

        return $this->noStore(redirect()->route('design.show', $designRequest->id)
            ->with('success', 'You declined this quote. Nothing further is due.'));
    }

    /* ───────────── the agreement record (read only) ───────────── */

    public function show($agreementId)
    {
        $agreement = $this->ownedAgreement($agreementId);

        if (!AgreementIntegrity::check($agreement)) {
            return $this->blocked();
        }

        return $this->noStore(response()->view('design.agreement', [
            'agreement'     => $agreement,
            'terms'         => $agreement->terms_snapshot,
            'designRequest' => $agreement->request,
            'imageUrl'      => $this->hasImage($agreement) ? route('design.agreement.image', $agreement->id) : null,
        ]));
    }

    public function printView($agreementId)
    {
        $agreement = $this->ownedAgreement($agreementId);

        if (!AgreementIntegrity::check($agreement)) {
            return $this->blocked();
        }

        return $this->noStore(response()->view('agreements.print', [
            'agreement' => $agreement,
            'terms'     => $agreement->terms_snapshot,
            'imageUrl'  => $this->hasImage($agreement) ? route('design.agreement.image', $agreement->id) : null,
            'backUrl'   => route('design.agreement', $agreement->id),
        ]));
    }

    public function image($agreementId)
    {
        $agreement = $this->ownedAgreement($agreementId);

        return $this->serveImage($agreement);
    }

    /* ───────────── shared with the admin controller ───────────── */

    public static function imagePathOf(DesignAgreement $agreement): ?string
    {
        $path = $agreement->terms_snapshot['design']['reference_image'] ?? null;

        // Only our own copies, never a path built from anything else.
        if (!is_string($path) || !preg_match('#^agreements/[A-Za-z0-9._-]+$#', $path)) {
            return null;
        }

        return Storage::disk('local')->exists($path) ? $path : null;
    }

    public static function serveImage(DesignAgreement $agreement)
    {
        if (!AgreementIntegrity::check($agreement)) {
            abort(404);
        }

        $path = self::imagePathOf($agreement);
        abort_unless($path, 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control'          => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /* ───────────── internals ───────────── */

    private function ownedQuote($requestId, $quoteId): array
    {
        $designRequest = CustomDesignRequest::where('user_id', Auth::id())->with('user')->findOrFail($requestId);
        $quote = CustomQuote::where('request_id', $designRequest->id)->with('agreement')->findOrFail($quoteId);

        // A draft is internal: the customer must never be able to open one.
        abort_if($quote->status === CustomQuote::STATUS_DRAFT, 404);

        return [$designRequest, $quote];
    }

    private function ownedAgreement($id): DesignAgreement
    {
        return DesignAgreement::where('user_id', Auth::id())->with('request')->findOrFail($id);
    }

    private function hasImage(DesignAgreement $agreement): bool
    {
        return self::imagePathOf($agreement) !== null;
    }

    private function blocked()
    {
        return $this->noStore(response()->view('design.agreement-blocked', [], 409));
    }

    private function noStore($response)
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Copy the design image to a stable, private file for the agreement.
     * The original (a generated concept under public/images/ai-designs,
     * or the customer's upload) is only ever read.
     */
    private function copyReferenceImage(CustomDesignRequest $designRequest, CustomQuote $quote): ?string
    {
        $source = $this->referenceSource($designRequest);
        if (!$source || !is_file($source)) {
            return null;
        }

        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            return null;
        }

        $dest = 'agreements/r' . $designRequest->id . '-q' . $quote->id . '-' . Str::random(16) . '.' . $ext;
        $disk = Storage::disk('local');

        $stream = fopen($source, 'rb');
        if ($stream === false) {
            return null;
        }

        try {
            $ok = $disk->put($dest, $stream);
        } finally {
            fclose($stream);
        }

        return $ok ? $dest : null;
    }

    private function referenceSource(CustomDesignRequest $designRequest): ?string
    {
        $suggestion = $designRequest->selectedSuggestion
            ?? $designRequest->suggestions()->where('is_selected', true)->first();

        if ($suggestion && $suggestion->isLoadable()) {
            $path = $suggestion->image_path;

            return public_path(substr($path, strpos($path, 'images/ai-designs/')));
        }

        if ($designRequest->reference_image) {
            $public = Storage::disk('public');
            if ($public->exists($designRequest->reference_image)) {
                return $public->path($designRequest->reference_image);
            }
        }

        return null;
    }

    /** Removes a copy we made; never anything else. */
    private function discard(?string $copy): void
    {
        if ($copy !== null && preg_match('#^agreements/[A-Za-z0-9._-]+$#', $copy)) {
            Storage::disk('local')->delete($copy);
        }
    }
}
