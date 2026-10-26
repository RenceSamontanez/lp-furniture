<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserValidation;
use App\Rules\RealJpegOrPng;
use App\Support\IdentityPhotos;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ValidationController extends Controller
{
    /* Show the validation page */
    public function show()
    {
        $user = Auth::user();
        $validation = $user->validation;

        // Set by CheckValidated when the customer came from the Other Services request page.
        $forService = session('validation_return') === 'services.request';
        if ($forService && $user->is_validated) {
            session()->forget('validation_return');
        }

        return view('validation.show', [
            'validation'  => $validation,
            'forService'  => $forService,
            'acceptedIds' => config('identity.accepted_ids'),
            'checks'      => config('identity.checks'),
            'attempt'     => ($validation?->attempt_number ?? 0) + 1,
        ]);
    }

    /* Handle document submission: each submission is a new attempt, only after a rejection (or the first one) */
    public function submit(Request $request)
    {
        $kb = config('identity.max_kb');

        $request->validate([
            'id_photo'     => ['required', 'file', "max:$kb", new RealJpegOrPng()],
            'selfie_photo' => ['required', 'file', "max:$kb", new RealJpegOrPng()],
            'confirm'      => ['accepted'],
        ], [
            'id_photo.required'     => 'Add a photo of your ID.',
            'selfie_photo.required' => 'Add a selfie holding your ID.',
            'id_photo.max'          => 'The ID photo is over 5 MB. Choose a smaller one.',
            'selfie_photo.max'      => 'The selfie is over 5 MB. Choose a smaller one.',
            'id_photo.uploaded'     => 'The ID photo could not be uploaded. It may be over 5 MB.',
            'selfie_photo.uploaded' => 'The selfie could not be uploaded. It may be over 5 MB.',
            'confirm.accepted'      => 'Please confirm that the photos are genuine and belong to you.',
        ]);

        $userId = Auth::id();
        $stored = [];

        try {
            DB::transaction(function () use ($request, $userId, &$stored) {
                $user = User::whereKey($userId)->lockForUpdate()->first();
                $latest = UserValidation::where('user_id', $userId)->orderByDesc('attempt_number')->first();

                if ($user->is_validated || $latest?->isApproved()) {
                    abort(409, 'Your identity is already verified.');
                }
                if ($latest?->isPending()) {
                    abort(409, 'Your photos are already under review. You can send new ones if they are rejected.');
                }

                $stored = [
                    IdentityPhotos::store($request->file('id_photo'), $userId, 'id'),
                    IdentityPhotos::store($request->file('selfie_photo'), $userId, 'selfie'),
                ];

                UserValidation::create([
                    'user_id'           => $userId,
                    'attempt_number'    => ($latest?->attempt_number ?? 0) + 1,
                    'id_photo_path'     => $stored[0],
                    'selfie_photo_path' => $stored[1],
                    'status'            => 'pending',
                    'submitted_at'      => now(),
                ]);
            });
        } catch (\Throwable $e) {
            // A database rollback does not remove files: delete what this request wrote.
            foreach ($stored as $path) {
                Storage::disk(IdentityPhotos::DISK)->delete($path);
            }

            if ($e instanceof QueryException) {
                return back()->with('error', 'Your photos were already sent. Please refresh the page.');
            }
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException && $e->getStatusCode() === 409) {
                return $this->backToVerification($request)->with('error', $e->getMessage());
            }
            throw $e;
        }

        return $this->backToVerification($request)->with('success', 'Submitted. We will review your photos and notify you here.');
    }

    /* The Verification tab of My Account posts here too and comes back to itself; the standalone page keeps its own redirect. */
    private function backToVerification(Request $request)
    {
        return $request->input('return_to') === 'profile'
            ? redirect()->route('profile', ['tab' => 'verify'])
            : redirect()->route('validation.show');
    }

    /* The owner's own photo, never anyone else's (404 either way). */
    public function photo($id, $type)
    {
        $validation = UserValidation::where('user_id', Auth::id())->findOrFail($id);

        return IdentityPhotos::response($validation, $type);
    }
}
