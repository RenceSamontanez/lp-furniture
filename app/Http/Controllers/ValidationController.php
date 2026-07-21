<?php

namespace App\Http\Controllers;

use App\Models\UserValidation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ValidationController extends Controller
{
    /* Show the validation page */
    public function show()
    {
        $validation = Auth::user()->validation;
        return view('validation.show', compact('validation'));
    }

    /* Handle document submission */
    public function submit(Request $request)
    {
        $request->validate([
            'id_photo'     => 'required|image|mimes:jpg,jpeg,png|max:5120',
            'selfie_photo' => 'required|image|mimes:jpg,jpeg,png|max:5120',
        ]);

        $userId = Auth::id();

        $idPath     = $request->file('id_photo')
            ->store("validations/{$userId}/id", 'private');
        $selfiePath = $request->file('selfie_photo')
            ->store("validations/{$userId}/selfie", 'private');

        UserValidation::updateOrCreate(
            ['user_id' => $userId],
            [
                'id_photo_path'     => $idPath,
                'selfie_photo_path' => $selfiePath,
                'status'            => 'pending',
                'submitted_at'      => now(),
                'rejection_reason'  => null,
            ]
        );

        return back()->with('success', 'Documents submitted successfully! Admin will review within 24 hours.');
    }
}