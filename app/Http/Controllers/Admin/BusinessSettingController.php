<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Rules\FacebookPageUrl;
use App\Rules\PhilippinePhone;
use App\Rules\TikTokUrl;
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
            'tiktok_url'   => trim((string) $request->input('tiktok_url')),
            'shop_address'   => trim((string) $request->input('shop_address')),
            'shop_landmarks' => trim((string) $request->input('shop_landmarks')),
            'map_lat'        => trim((string) $request->input('map_lat')),
            'map_lng'        => trim((string) $request->input('map_lng')),
        ]);

        $validated = $request->validate([
            'phone'        => ['required', 'string', 'max:30', new PhilippinePhone()],
            // rfc,filter alone still allows quoted local parts like "<b>"@x.com,
            // so also require a plain everyday address
            'email'        => ['required', 'string', 'max:191', 'email:rfc,filter', 'regex:/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/'],
            'facebook_url' => ['required', 'string', 'max:255', new FacebookPageUrl()],
            'tiktok_url'   => ['nullable', 'string', 'max:255', new TikTokUrl()],
            'shop_address'   => ['nullable', 'string', 'max:255'],
            'shop_landmarks' => ['nullable', 'string', 'max:500'],
            'map_lat'        => ['nullable', 'numeric', 'between:-90,90', 'required_with:map_lng'],
            'map_lng'        => ['nullable', 'numeric', 'between:-180,180', 'required_with:map_lat'],
        ], [
            'email.regex' => 'Enter a plain email address, e.g. hello@yourshop.com.',
            'map_lat.between' => 'Latitude must be between -90 and 90.',
            'map_lng.between' => 'Longitude must be between -180 and 180.',
            'map_lat.required_with' => 'Enter the latitude too, or clear both map numbers.',
            'map_lng.required_with' => 'Enter the longitude too, or clear both map numbers.',
        ]);

        $settings = BusinessSetting::orderBy('id')->first() ?? new BusinessSetting();
        foreach (['tiktok_url', 'shop_address', 'shop_landmarks', 'map_lat', 'map_lng'] as $optional) {
            $validated[$optional] = ($validated[$optional] ?? '') === '' ? null : $validated[$optional];
        }
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
