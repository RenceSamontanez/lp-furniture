<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Support\CustomOrderPipeline;
use App\Support\PipelineException;
use App\Support\QuoteTerms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin side of the custom-order flow (Stage 4: receive and work
 * requests). All state changes go through CustomOrderPipeline.
 */
class CustomOrderController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(private CustomOrderPipeline $pipeline)
    {
    }

    public function index(Request $request)
    {
        $this->pipeline->expireStaleQuotes();
        $this->pipeline->expireUnpaidAgreements();

        $status = $request->query('status');
        if (!array_key_exists((string) $status, CustomOrderPipeline::LABELS)) {
            $status = null;
        }
        $search = trim((string) $request->query('q', ''));

        $query = CustomDesignRequest::query()
            ->whereNotNull('pipeline_status')
            ->with(['user', 'suggestions']);

        if ($status) {
            $query->where('pipeline_status', $status);
        }

        if ($search !== '') {
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('contact_phone', 'like', $like)
                  ->orWhere('contact_email', 'like', $like)
                  ->orWhereHas('user', function ($u) use ($like) {
                      $u->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone_number', 'like', $like)
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$like]);
                  });
            });
        }

        $requests = $query->orderByDesc('submitted_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        $counts = CustomDesignRequest::whereNotNull('pipeline_status')
            ->selectRaw('pipeline_status, COUNT(*) as total')
            ->groupBy('pipeline_status')
            ->pluck('total', 'pipeline_status');

        return view('admin.custom-orders.index', [
            'requests' => $requests,
            'status'   => $status,
            'search'   => $search,
            'counts'   => $counts,
            'total'    => $counts->sum(),
        ]);
    }

    public function show($id)
    {
        $this->pipeline->expireStaleQuotes(); // lazy expiry, like failStale()
        $this->pipeline->expireUnpaidAgreements();

        $designRequest = CustomDesignRequest::whereNotNull('pipeline_status')
            ->with(['user', 'suggestions', 'selectedSuggestion', 'callLogs.admin'])
            ->findOrFail($id);

        $events = CustomOrderEvent::where('request_id', $designRequest->id)
            ->orderBy('occurred_at')->orderBy('id')->get();

        $actors = \App\Models\User::whereIn('id', $events->where('actor_type', 'admin')->pluck('actor_id')->filter()->unique())
            ->get()->keyBy('id');

        $agreement = \App\Models\DesignAgreement::where('request_id', $designRequest->id)->orderByDesc('id')->first();

        return view('admin.custom-orders.show', [
            'hideGlobalErrorToast' => true, // errors are shown inline on this page
            'designRequest' => $designRequest,
            'events'        => $events,
            'actors'        => $actors,
            'unanswered'    => $designRequest->callLogs->where('answered', false)->count(),
            'lastCall'      => $this->lastCall($designRequest, $events),
            'callPreview'   => \App\Support\CallUpdateMessage::previewData($designRequest),
            'isOpen'        => in_array($designRequest->pipeline_status, CustomOrderPipeline::OPEN, true),
            'quotes'        => CustomQuote::where('request_id', $designRequest->id)->with(['sender', 'creator'])->orderByDesc('version')->get(),
            'quoteBlocker'  => $this->quoteBlocker($designRequest),
            'agreement'     => $agreement,
            'timeline'      => app(\App\Support\CustomOrderTimeline::class)->forAdmin($designRequest),
            'order'         => $agreement ? \App\Models\Order::with('payment', 'designRequest')->where('design_agreement_id', $agreement->id)->first() : null,
            'hashOk'        => $agreement ? \App\Support\AgreementIntegrity::check($agreement) : null,
        ]);
    }

    public function logCall(Request $request, $id)
    {
        $data = $request->validate([
            'lock_version' => 'required|integer|min:0',
            'answered'     => 'required|in:0,1',
            'note'         => 'nullable|string|max:1000',
        ], [
            'answered.required' => 'Say whether the customer answered.',
        ]);

        return $this->run($id, function () use ($id, $data) {
            $this->pipeline->logCall((int) $id, Auth::guard('admin')->user(), $data['answered'] === '1', $data['note'] ?? null, (int) $data['lock_version']);

            return 'Call logged.';
        });
    }

    /**
     * Requests made before the type was required have none. The team may set it
     * when preparing a quote. A type the customer chose is never overwritten here.
     */
    public function setFurnitureType(Request $request, $id)
    {
        $data = $request->validate([
            'furniture_type'       => ['required', 'string', \Illuminate\Validation\Rule::in(\App\Support\FurnitureTypes::LIST)],
            'furniture_type_other' => ['nullable', 'required_if:furniture_type,' . \App\Support\FurnitureTypes::OTHER, 'string', 'max:' . \App\Support\FurnitureTypes::OTHER_MAX],
        ], [
            'furniture_type.required' => 'Choose the furniture type.',
            'furniture_type.in'       => 'Choose one of the listed furniture types.',
            'furniture_type_other.required_if' => 'Describe the furniture in a few words.',
        ]);

        $designRequest = \App\Models\CustomDesignRequest::findOrFail($id);

        if (filled($designRequest->furniture_type)) {
            return redirect()->route('admin.custom-orders.show', $designRequest->id)
                ->withErrors(['furniture_type' => 'The customer already chose a furniture type for this request.']);
        }

        $designRequest->forceFill([
            'furniture_type'       => $data['furniture_type'],
            'furniture_type_other' => \App\Support\FurnitureTypes::needsText($data['furniture_type']) ? trim((string) $data['furniture_type_other']) : null,
        ])->save();

        return redirect()->route('admin.custom-orders.show', $designRequest->id)->with('success', 'Furniture type saved.');
    }

    public function callUpdate(Request $request, $id)
    {
        $data = $request->validate([
            'lock_version' => 'required|integer|min:0',
            'outcome'      => 'required|in:' . implode(',', array_keys(\App\Support\CallUpdateMessage::LABELS)),
            'attempts'     => 'nullable|integer|min:1|max:20',
            'note'         => 'nullable|string|max:500',
        ], [
            'outcome.required' => 'Choose the call outcome.',
            'attempts.min'     => 'Call attempts must be from 1 to 20.',
            'attempts.max'     => 'Call attempts must be from 1 to 20.',
        ]);

        return $this->run($id, function () use ($id, $data) {
            $this->pipeline->sendCallUpdate(
                (int) $id,
                Auth::guard('admin')->user(),
                $data['outcome'],
                isset($data['attempts']) ? (int) $data['attempts'] : null,
                $data['note'] ?? null,
                (int) $data['lock_version']
            );

            return 'Call update sent. The customer has been notified.';
        });
    }

    public function markUnreachable(Request $request, $id)
    {
        $data = $request->validate(['lock_version' => 'required|integer|min:0']);

        return $this->run($id, function () use ($id, $data) {
            $this->pipeline->markUnreachable((int) $id, Auth::guard('admin')->user(), (int) $data['lock_version']);

            return 'Marked unreachable. The customer has been notified.';
        });
    }

    public function decline(Request $request, $id)
    {
        $data = $request->validate([
            'lock_version' => 'required|integer|min:0',
            'reason'       => 'required|string|max:1000',
        ], [
            'reason.required' => 'A reason is required to decline a request.',
        ]);

        return $this->run($id, function () use ($id, $data) {
            $this->pipeline->decline((int) $id, Auth::guard('admin')->user(), $data['reason'], (int) $data['lock_version']);

            return 'Request declined. The customer has been notified.';
        });
    }

    /* ───────────── quotes (Stage 5) ───────────── */

    private const QUOTE_FIELDS = [
        'final_description', 'width_cm', 'depth_cm', 'height_cm', 'material', 'finish', 'modifications',
        'price', 'delivery_option', 'delivery_fee', 'delivery_address', 'production_days',
        'payment_terms', 'deposit_amount', 'installment_duration_months', 'installment_frequency_days',
        'call_notes',
    ];

    /** Blank form (first quote) or a prefilled next version. */
    public function quoteForm($id)
    {
        $designRequest = CustomDesignRequest::whereNotNull('pipeline_status')->with('user')->findOrFail($id);

        $draft = CustomQuote::where('request_id', $designRequest->id)->where('status', 'draft')->first();
        if ($draft) {
            return redirect()->route('admin.custom-orders.quotes.edit', [$designRequest->id, $draft->id]);
        }

        if ($problem = $this->quoteBlocker($designRequest)) {
            return redirect()->route('admin.custom-orders.show', $designRequest->id)->withErrors(['pipeline' => $problem]);
        }

        $latest = CustomQuote::where('request_id', $designRequest->id)->orderByDesc('version')->first();

        return view('admin.custom-orders.quote-form', [
            'hideGlobalErrorToast' => true,
            'designRequest' => $designRequest,
            'quote'         => null,
            'basedOn'       => $latest,
            'values'        => $this->formValues(old(), $latest, $designRequest),
            'action'        => route('admin.custom-orders.quotes.store', $designRequest->id),
        ]);
    }

    public function quoteStore(Request $request, $id)
    {
        $data = $request->validate(['lock_version' => 'required|integer|min:0']);
        $terms = $request->only(self::QUOTE_FIELDS);

        return $this->run($id, function () use ($id, $terms, $data) {
            $quote = $this->pipeline->createDraft((int) $id, Auth::guard('admin')->user(), $terms, (int) $data['lock_version']);

            return "Draft saved as version {$quote->version}. Review it, then send it to the customer.";
        });
    }

    public function quoteEdit($id, $quoteId)
    {
        $designRequest = CustomDesignRequest::whereNotNull('pipeline_status')->with('user')->findOrFail($id);
        $quote = CustomQuote::where('request_id', $designRequest->id)->findOrFail($quoteId);

        if ($quote->status !== 'draft') {
            return redirect()->route('admin.custom-orders.show', $designRequest->id)
                ->withErrors(['pipeline' => 'Only a draft can be edited. Start a new version from the quote history.']);
        }

        return view('admin.custom-orders.quote-form', [
            'hideGlobalErrorToast' => true,
            'designRequest' => $designRequest,
            'quote'         => $quote,
            'basedOn'       => null,
            'values'        => $this->formValues(old(), $quote, $designRequest),
            'action'        => route('admin.custom-orders.quotes.update', [$designRequest->id, $quote->id]),
        ]);
    }

    public function quoteUpdate(Request $request, $id, $quoteId)
    {
        $data = $request->validate(['lock_version' => 'required|integer|min:0']);
        $terms = $request->only(self::QUOTE_FIELDS);

        return $this->run($id, function () use ($id, $quoteId, $terms, $data) {
            $this->pipeline->updateDraft((int) $id, (int) $quoteId, Auth::guard('admin')->user(), $terms, (int) $data['lock_version']);

            return 'Draft updated.';
        });
    }

    public function quoteDelete(Request $request, $id, $quoteId)
    {
        $data = $request->validate(['lock_version' => 'required|integer|min:0']);

        return $this->run($id, function () use ($id, $quoteId, $data) {
            $this->pipeline->deleteDraft((int) $id, (int) $quoteId, Auth::guard('admin')->user(), (int) $data['lock_version']);

            return 'Draft deleted.';
        });
    }

    /** "Send P{total} quote to {name} ({phone})?" with the full terms. */
    public function quoteConfirm($id, $quoteId)
    {
        $designRequest = CustomDesignRequest::whereNotNull('pipeline_status')->with('user')->findOrFail($id);
        $quote = CustomQuote::where('request_id', $designRequest->id)->findOrFail($quoteId);

        if ($quote->status !== 'draft') {
            return redirect()->route('admin.custom-orders.show', $designRequest->id)
                ->withErrors(['pipeline' => 'Only a draft can be sent.']);
        }

        return view('admin.custom-orders.quote-confirm', [
            'hideGlobalErrorToast' => true,
            'designRequest' => $designRequest,
            'quote'         => $quote,
            'summary'       => QuoteTerms::summary($quote),
            'replaces'      => CustomQuote::where('request_id', $designRequest->id)->where('status', 'sent')->first(),
        ]);
    }

    public function quoteSend(Request $request, $id, $quoteId)
    {
        $data = $request->validate(['lock_version' => 'required|integer|min:0']);

        return $this->run($id, function () use ($id, $quoteId, $data) {
            $quote = $this->pipeline->sendQuote((int) $id, (int) $quoteId, Auth::guard('admin')->user(), (int) $data['lock_version']);

            return 'Quote version ' . $quote->version . ' sent. The customer has been notified and has '
                . CustomQuote::VALID_DAYS . ' days to respond.';
        });
    }

    /** Live preview: the same arithmetic and scheduler the real quote uses. */
    public function quotePreview(Request $request, $id)
    {
        CustomDesignRequest::whereNotNull('pipeline_status')->findOrFail($id);

        $delivery = $request->query('delivery_option') === 'delivery';
        $feeRaw   = $request->query('delivery_fee');
        $deposit  = $request->query('payment_terms') === 'deposit';

        $terms = [
            'price'           => is_numeric($request->query('price')) ? (float) $request->query('price') : 0,
            'delivery_option' => $delivery ? 'delivery' : 'pickup',
            'delivery_fee'    => $delivery ? (is_numeric($feeRaw) ? (float) $feeRaw : CustomQuote::DEFAULT_DELIVERY_FEE) : 0,
            'payment_terms'   => $deposit ? 'deposit' : 'full',
            'deposit_amount'  => is_numeric($request->query('deposit_amount')) ? (float) $request->query('deposit_amount') : 0,
            'installment_duration_months' => (int) $request->query('installment_duration_months'),
            'installment_frequency_days'  => (int) $request->query('installment_frequency_days'),
        ];

        $summary = QuoteTerms::summary($terms);
        $problem = $deposit
            ? QuoteTerms::depositProblem((int) round($summary['total'] * 100), (int) round($terms['deposit_amount'] * 100))
            : null;

        return response()->json($summary + ['delivery_fee' => $terms['delivery_fee'], 'deposit_problem' => $problem]);
    }

    /** The latest call update, from the new tool or an older call log row. */
    private function lastCall(CustomDesignRequest $r, $events): ?array
    {
        $update = $events->where('type', 'call_update')->sortByDesc('occurred_at')->first();
        $log = $r->callLogs->sortByDesc('attempt_no')->first();

        if ($update && (!$log || $update->occurred_at >= $log->called_at)) {
            $d = $update->data ?? [];

            return [
                'label'    => \App\Support\CallUpdateMessage::LABELS[$d['outcome'] ?? ''] ?? 'Call update',
                'attempts' => $d['attempts'] ?? null,
                'at'       => $update->occurred_at,
                'count'    => $r->callLogs->count(),
            ];
        }

        if ($log) {
            return [
                'label'    => $log->answered ? 'Answered' : 'No answer',
                'attempts' => null,
                'at'       => $log->called_at,
                'count'    => $r->callLogs->count(),
            ];
        }

        return null;
    }

    /** Why a new quote can't be started right now, or null. */
    private function quoteBlocker(CustomDesignRequest $r): ?string
    {
        if (!in_array($r->pipeline_status, CustomOrderPipeline::QUOTE_FROM, true)) {
            return 'A quote can only be started while the request is open, or after it was declined, expired or replaced.';
        }

        return null;
    }

    private function formValues(array $old, ?CustomQuote $from, CustomDesignRequest $r): array
    {
        $base = [
            'final_description' => $r->description,
            'material'          => $r->preferred_material,
            'delivery_option'   => 'pickup',
            'delivery_fee'      => '',
            'payment_terms'     => 'full',
        ];

        if ($from) {
            foreach (self::QUOTE_FIELDS as $f) {
                $base[$f] = $from->{$f};
            }
        }

        // Plain numbers in the inputs, not "10000.00" casts with thousands separators.
        foreach (['width_cm', 'depth_cm', 'height_cm', 'price', 'delivery_fee', 'deposit_amount'] as $f) {
            if (isset($base[$f]) && $base[$f] !== '') {
                $base[$f] = rtrim(rtrim(number_format((float) $base[$f], 2, '.', ''), '0'), '.');
            }
        }

        return array_merge($base, $old);
    }

    /** Runs a pipeline action; a refusal becomes a flash error, never a 500. */
    private function run($id, callable $action)
    {
        // 404 before anything else for an id that isn't a submitted request.
        CustomDesignRequest::whereNotNull('pipeline_status')->findOrFail($id);

        try {
            $message = $action();
        } catch (PipelineException $e) {
            return redirect()->route('admin.custom-orders.show', $id)
                ->withErrors(['pipeline' => $e->getMessage()]);
        }

        return redirect()->route('admin.custom-orders.show', $id)->with('success', $message);
    }
}
