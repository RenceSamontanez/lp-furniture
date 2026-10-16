<?php

namespace App\Http\Controllers;

use App\Models\ServiceInquiry;
use App\Support\ServiceInquiryDesk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public "Other Services" request form (labor only with the customer's own wood, repairs,
 * anything priced by phone). It only collects the concern and contact details: no order.
 */
class ServiceInquiryController extends Controller
{
    public const LIMIT_PER_HOUR = 5;

    public function __construct(private ServiceInquiryDesk $desk)
    {
    }

    public function create(Request $request)
    {
        $user = Auth::user();

        $mine = $user
            ? ServiceInquiry::where('user_id', $user->id)->orderByDesc('id')->limit(10)->get()
            : collect();

        return view('services.request', [
            'defaults' => [
                'name'  => $user?->display_name,
                'phone' => $user ? ($user->saved_contact_phone ?: $user->phone_number) : null,
                'email' => $user ? ($user->saved_contact_email ?: $user->email) : null,
            ],
            'mine'        => $mine,
            'serviceType' => $request->query('type'),
        ]);
    }

    public function store(Request $request)
    {
        // Honeypot: real people never see this field. Bots get a quiet redirect and nothing is saved.
        if (filled($request->input('website'))) {
            return redirect()->route('home');
        }

        $key = 'service-inquiry:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, self::LIMIT_PER_HOUR)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            return back()->withInput()->withErrors([
                'rate' => "You have sent several requests in a short time. Please try again in {$minutes} " . ($minutes === 1 ? 'minute' : 'minutes') . ' or call us.',
            ]);
        }

        $data = $request->validate(ServiceInquiryDesk::rules(true), ServiceInquiryDesk::messages());

        $inquiry = $this->desk->create($data, Auth::user());
        RateLimiter::hit($key, 3600);

        $request->session()->put('service_inquiry_reference', $inquiry->reference_number);

        return redirect()->route('services.request.sent');
    }

    public function sent(Request $request)
    {
        $reference = $request->session()->get('service_inquiry_reference');
        if (!$reference) {
            return redirect()->route('services.request');
        }

        return view('services.sent', ['reference' => $reference]);
    }
}
