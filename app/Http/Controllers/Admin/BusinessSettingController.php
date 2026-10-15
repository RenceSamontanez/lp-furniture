<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Rules\FacebookPageUrl;
use App\Rules\PhilippinePhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BusinessSettingController extends Controller
{
    public function edit()
    {
        $settings = BusinessSetting::with('updatedBy')->orderBy('id')->first()
            ?? BusinessSetting::current();

        return view('admin.settings.contact', compact('settings'));
    }

    public function update(Request $request)
    {
        $request->merge([
            'phone'        => trim((string) $request->input('phone')),
            'email'        => trim((string) $request->input('email')),
            'facebook_url' => $this->normalizeFacebookUrl((string) $request->input('facebook_url')),
        ]);

        $validated = $request->validate([
            'phone'        => ['required', 'string', 'max:30', new PhilippinePhone()],
            // rfc,filter alone still allows quoted local parts like "<b>"@x.com,
            // so also require a plain everyday address
            'email'        => ['required', 'string', 'max:191', 'email:rfc,filter', 'regex:/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/'],
            'facebook_url' => ['required', 'string', 'max:255', new FacebookPageUrl()],
        ], [
            'email.regex' => 'Enter a plain email address, e.g. hello@yourshop.com.',
        ]);

        $settings = BusinessSetting::orderBy('id')->first() ?? new BusinessSetting();
        $settings->fill($validated);
        $settings->updated_by = Auth::guard('admin')->id();

        // Re-saving identical values still counts as a save for the audit fields
        $settings->isDirty() ? $settings->save() : $settings->touch();

        return redirect()->route('admin.settings.contact.edit')
            ->with('success', 'Contact settings saved.');
    }

    // Accept the www. form people copy from the address bar, stored as facebook.com
    private function normalizeFacebookUrl(string $url): string
    {
        $url = trim($url);

        return preg_replace('#^https://www\.facebook\.com(?=/|$)#i', 'https://facebook.com', $url);
    }
}
