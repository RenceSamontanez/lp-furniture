<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Support\CustomOrderPipeline;
use App\Support\PipelineException;
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
        $designRequest = CustomDesignRequest::whereNotNull('pipeline_status')
            ->with(['user', 'suggestions', 'selectedSuggestion', 'callLogs.admin'])
            ->findOrFail($id);

        $events = CustomOrderEvent::where('request_id', $designRequest->id)
            ->orderBy('occurred_at')->orderBy('id')->get();

        $actors = \App\Models\User::whereIn('id', $events->where('actor_type', 'admin')->pluck('actor_id')->filter()->unique())
            ->get()->keyBy('id');

        return view('admin.custom-orders.show', [
            'hideGlobalErrorToast' => true, // errors are shown inline on this page
            'designRequest' => $designRequest,
            'events'        => $events,
            'actors'        => $actors,
            'unanswered'    => $designRequest->callLogs->where('answered', false)->count(),
            'isOpen'        => in_array($designRequest->pipeline_status, CustomOrderPipeline::OPEN, true),
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
