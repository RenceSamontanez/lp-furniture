<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ActivityLog;
use App\Support\ActivityLogViewer as V;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only viewer for the Activity Log (admin only: the route group already carries `admin` + `admin.timeout`).
 * Every filter and the sort are GET parameters; the CSV export uses the same ones. Nothing here writes.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $f = V::filters($request->query());

        $rows = V::sorted(V::query($f), $f['sort'])->paginate(V::PER_PAGE)->withQueryString();
        // a page number past the end falls back to the last page
        if ($rows->isEmpty() && $rows->currentPage() > 1) {
            $rows = V::sorted(V::query($f), $f['sort'])->paginate(V::PER_PAGE, ['*'], 'page', $rows->lastPage())->withQueryString();
        }

        return view('admin.activity-log.index', [
            'f'       => $f,
            'rows'    => $rows,
            'links'   => V::links(collect($rows->items())),
            'counts'  => V::tabCounts($f),
            'summary' => V::summary(),
            'people'  => V::people(),
            'minDate' => V::minDate()->format('Y-m-d'),
            'maxDate' => V::today()->format('Y-m-d'),
            'alertSample' => ActivityLog::alertMessage(now()),
        ]);
    }

    /** Every row that matches the current filters and sort (not only the page on screen), at most 5000. */
    public function export(Request $request): StreamedResponse
    {
        $f = V::filters($request->query());
        $query = V::sorted(V::query($f), $f['sort'])->limit(V::EXPORT_CAP);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When', 'Who', 'Role', 'What Happened', 'Type', 'Item']);
            foreach ($query->cursor() as $r) {
                fputcsv($out, [
                    V::csvCell($r->created_at->format('Y-m-d H:i')),
                    V::csvCell($r->actor_name),
                    $r->role === 'admin' ? 'Admin' : ($r->role === 'customer' ? 'Customer' : 'System'),
                    V::csvCell($r->description),
                    V::categoryLabel($r->category),
                    V::csvCell($r->subject_label ?? ''),
                ]);
            }
            fclose($out);
        }, 'activity-log-' . now()->format('Y-m-d') . '.csv', [
            'Content-Type'  => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
