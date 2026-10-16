<?php

namespace App\Http\Controllers;

use App\Models\AgreementPolicy;

/** The public Terms and Conditions page: the latest policy version, no login needed. */
class TermsController extends Controller
{
    public function show()
    {
        return view('terms', ['policy' => AgreementPolicy::current()]);
    }
}
