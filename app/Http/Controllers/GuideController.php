<?php

namespace App\Http\Controllers;

/** The public "How to request a custom design" page with the measuring guide. */
class GuideController extends Controller
{
    public function show()
    {
        return view('guide.show');
    }
}
