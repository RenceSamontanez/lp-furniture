<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserValidation;
use App\Rules\NoLinkOrEmail;
use App\Support\IdentityPhotos;
use App\Support\IdentityReview;
use App\Support\IdentityReviewException;
use App\Support\TextSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IdentityValidationController extends Controller
{
    private const TABS = ['pending', 'approved', 'rejected'];

    public function index(Request $request)
    {
        $status = in_array($request->query('status'), self::TABS, true) ? $request->query('status') : 'pending';
        $search = TextSanitizer::line((string) $request->query('q', ''));

        $rows = UserValidation::with('user')
            ->where('status', $status)
            ->search($search)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $counts = UserValidation::selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('admin.validations.index', [
            'validations' => $rows,
            'status'      => $status,
            'search'      => $search,
            'counts'      => collect(self::TABS)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
        ]);
    }

    public function show($id)
    {
        $validation = UserValidation::with('user', 'reviewer', 'followUpBy')->findOrFail($id);

        return view('admin.validations.show', [
            'validation' => $validation,
            'earlier'    => $validation->earlierAttempts(),
            'latest'     => UserValidation::where('user_id', $validation->user_id)->orderByDesc('attempt_number')->first(),
        ]);
    }

    public function approve(Request $request, $id)
    {
        $confirmed = [];
        foreach (array_keys(config('identity.checks')) as $key) {
            $confirmed[$key] = $request->boolean('confirm_' . $key);
        }

        try {
            IdentityReview::approve((int) $id, $confirmed, (int) Auth::guard('admin')->id());
        } catch (IdentityReviewException $e) {
            return $this->refused($request, $e, (int) $id);
        }

        return redirect()->route('admin.validations.show', $id)->with('success', 'Customer verified. They have been notified.');
    }

    public function reject(Request $request, $id)
    {
        $request->merge(['rejection_reason' => TextSanitizer::line((string) $request->input('rejection_reason'))]);

        $request->validate([
            'rejection_reason' => ['required', 'string', 'max:' . config('identity.reason_max'), new NoLinkOrEmail()],
        ], [
            'rejection_reason.required' => 'Add a reason so the customer knows what to fix.',
        ]);

        try {
            IdentityReview::reject((int) $id, $request->input('rejection_reason'), (int) Auth::guard('admin')->id());
        } catch (IdentityReviewException $e) {
            return $this->refused($request, $e, (int) $id);
        }

        return redirect()->route('admin.validations.show', $id)->with('success', 'Verification rejected. The customer has been notified.');
    }

    public function requestNewSubmission(Request $request, $id)
    {
        try {
            $sent = IdentityReview::requestNewSubmission((int) $id, (int) Auth::guard('admin')->id());
        } catch (IdentityReviewException $e) {
            return $this->refused($request, $e, (int) $id);
        }

        return redirect()->route('admin.validations.show', $id)->with(
            $sent ? 'success' : 'info',
            $sent ? 'The customer was asked to send a new submission.' : 'A new submission was already requested. Nothing was sent again.'
        );
    }

    public function document($id, $type)
    {
        return IdentityPhotos::response(UserValidation::findOrFail($id), $type);
    }

    /** 409/422/404 as JSON for fetch callers, otherwise back to the page with a clear notice. */
    private function refused(Request $request, IdentityReviewException $e, int $id)
    {
        $code = $e->getCode() ?: 409;

        if ($request->expectsJson()) {
            return response()->json(['message' => $e->getMessage()], $code);
        }
        if ($code === 404) {
            abort(404);
        }

        return redirect()->route('admin.validations.show', $id)->with('error', $e->getMessage());
    }
}
