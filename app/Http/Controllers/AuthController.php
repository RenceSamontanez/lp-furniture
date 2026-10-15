<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\PhilippineMobile;
use App\Support\SafeIntendedUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Http;

class AuthController extends Controller
{
    /* ════════════════════════════════════════
       SHOW LOGIN PAGE
    ════════════════════════════════════════ */
    public function showLogin()
    {
        return view('auth.login');
    }

    /* ════════════════════════════════════════
       PROCESS LOGIN
    ════════════════════════════════════════ */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email|max:150',
            'password' => 'required|string|max:100',
        ]);

        // Verify reCAPTCHA v3
        $recaptchaToken = $request->input('g-recaptcha-response');

        if (empty($recaptchaToken)) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Security verification failed. Please refresh and try again.']);
        }

        try {
            $verify = Http::timeout(5)->asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret'   => config('services.recaptcha.secret_key'),
                'response' => $recaptchaToken,
                'remoteip' => $request->ip(),
            ]);

            $data = $verify->json();

            // Log for debugging during development
            \Log::info('reCAPTCHA result:', $data);

            if (!($data['success'] ?? false) || ($data['score'] ?? 0) < 0.3) {
                return back()
                    ->withInput($request->only('email'))
                    ->withErrors(['email' => 'Security check failed. Please try again.']);
            }

        } catch (\Exception $e) {
            \Log::error('reCAPTCHA request failed: ' . $e->getMessage());
            // Don't block login if reCAPTCHA API is temporarily down
            // Just log and continue
        }

        // Keyed on the account being attempted, not the caller's IP —
        // an IP-based key lets one shared network's failed attempts
        // lock out everyone behind it, and does nothing to stop an
        // account being attacked from many different IPs.
        $key = 'login_attempts_' . strtolower(trim($request->email));

        // Check if this account is locked out
        if (cache()->has($key . '_lockout')) {
            $seconds = cache()->get($key . '_lockout_time', 60);
            return back()->withErrors([
                'email' => "Too many failed login attempts for this account. Please wait {$seconds} seconds before trying again.",
            ])->withInput($request->only('email'));
        }
    
        // Looked up and password-checked manually (rather than
        // Auth::attempt()) so an admin-role account is excluded from the
        // match entirely — it falls through to the same generic failure
        // as a wrong password below, instead of a separate branch after
        // a successful attempt() that would reveal the credentials were
        // actually valid.
        $user = User::where('email', $request->email)->first();

        if ($user && $user->role !== 'admin' && Hash::check($request->password, $user->password)) {
            // No remember-me: every login (this, Google, registration)
            // is a plain session login now, so idle logout actually
            // means something — a persistent recaller cookie used to
            // re-authenticate indefinitely even after the session row
            // itself was gone, which is why customers never logged out.
            Auth::login($user);

            // Clear failed attempt count on success
            cache()->forget($key);
            cache()->forget($key . '_lockout');

            // One identity per browser: a customer session replaces any
            // admin session already active in this browser.
            Auth::guard('admin')->logout();

            $request->session()->regenerate();

            return redirect(SafeIntendedUrl::resolve($request, route('home')))
                ->with('success', 'Welcome back, ' . Auth::user()->first_name . '!');
        }
    
        // Increment failed attempts
        $attempts = cache()->get($key, 0) + 1;
        cache()->put($key, $attempts, now()->addMinutes(10));
    
        if ($attempts >= 5) {
            cache()->put($key . '_lockout', true, now()->addMinutes(1));
            cache()->put($key . '_lockout_time', 60, now()->addMinutes(1));
            return back()->withErrors([
                'email' => 'Too many failed attempts for this account. Please wait 60 seconds before trying again.',
            ])->withInput($request->only('email'));
        }

        $remaining = 5 - $attempts;
        return back()
            ->withInput($request->only('email'))
            ->withErrors([
                'email' => "Incorrect email or password. {$remaining} attempt(s) remaining before lockout.",
            ]);
    }

    /* ════════════════════════════════════════
       SHOW REGISTER PAGE
    ════════════════════════════════════════ */
    public function showRegister()
    {
        return view('auth.register');
    }

    /* ════════════════════════════════════════
       PROCESS REGISTRATION
    ════════════════════════════════════════ */
    public function register(Request $request)
    {
        $request->validate([
            'first_name' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[a-zA-Z\s\-\.]+$/',
            ],
            'last_name' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[a-zA-Z\s\-\.]+$/',
            ],
            'email' => [
                'required',
                'email:rfc',
                'max:150',
                'unique:users,email',
                // Block obviously fake TLDs
                'not_regex:/\.(fake|test|invalid|example|localhost)$/i',
            ],
            'phone_number' => [
                'required',
                'regex:/^(09|\+639)\d{9}$/',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:20',
                'confirmed',
                // Must have uppercase, lowercase, number, special char
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,20}$/',
            ],
            'password_confirmation' => 'required',
        ], [
            'first_name.regex'   => 'First name may only contain letters, spaces, hyphens, and dots. No numbers or symbols.',
            'last_name.regex'    => 'Last name may only contain letters, spaces, hyphens, and dots. No numbers or symbols.',
            'email.email'        => 'Please enter a valid email address.',
            'email.not_regex'    => 'Please use a real email address.',
            'phone_number.regex' => 'Phone number must be a valid Philippine mobile number starting with 09 or +639.',
            'password.regex'     => 'Password must be 8–20 characters and include at least one uppercase letter, one lowercase letter, one number, and one special character (e.g. @#$%).',
            'password.min'       => 'Password must be at least 8 characters.',
            'password.max'       => 'Password cannot exceed 20 characters.',
        ]);
    
        $user = User::create([
            'first_name'     => $request->first_name,
            'last_name'      => $request->last_name,
            'email'          => $request->email,
            'phone_number'   => $request->phone_number,
            'password'       => Hash::make($request->password),
            'role'           => 'customer',
            'account_status' => 'active',
            'is_validated'   => false,
        ]);
    
        Auth::login($user);
$request->session()->regenerate();

// Wrapped in try/catch so registration succeeds even if email fails
// (Resend test mode only allows sending to the account owner's email)
try {
    $user->sendEmailVerificationNotification();
} catch (\Exception $e) {
    \Log::warning('Email verification send failed for ' . $user->email . ': ' . $e->getMessage());
}

return redirect()->route('verification.notice')
    ->with('success', 'Account created! Please check your email to verify your account.');
    }
    /* ════════════════════════════════════════
       LOGOUT
    ════════════════════════════════════════ */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')
            ->with('success', 'You have been signed out.');
    }

    /* ════════════════════════════════════════
       SHOW FORGOT PASSWORD PAGE
    ════════════════════════════════════════ */
    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    /* ════════════════════════════════════════
       SEND PASSWORD RESET LINK
    ════════════════════════════════════════ */
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with('success', 'Password reset link sent to your email. Check your inbox.');
        }

        return back()->withErrors(['email' => __($status)]);
    }

    /* ════════════════════════════════════════
       SHOW RESET PASSWORD PAGE
    ════════════════════════════════════════ */
    public function showResetPassword(Request $request, $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->email,
        ]);
    }

    /* ════════════════════════════════════════
       PROCESS PASSWORD RESET
    ════════════════════════════════════════ */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'                 => 'required',
            'email'                 => 'required|email',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')
                ->with('success', 'Password reset successfully. You can now sign in.');
        }

        return back()->withErrors(['email' => __($status)]);
    }

    /* ════════════════════════════════════════
       PROFILE PAGE
    ════════════════════════════════════════ */
    public function profile()
    {
        return view('profile.index', [
            'user' => Auth::user(),
        ]);
    }

    /* ════════════════════════════════════════
       UPDATE PROFILE
    ════════════════════════════════════════ */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        // The phone format is only checked when the number is edited, so
        // an older account whose stored number predates the rule can still
        // save its name/address/password untouched. An account with no
        // phone yet (Google sign-ups) may keep it empty.
        $submittedPhone = trim((string) $request->input('phone_number', ''));
        $phoneUnchanged = $user->phone_number !== null && $submittedPhone === $user->phone_number;

        $phoneRules = match (true) {
            $phoneUnchanged                                        => ['required', 'string', 'max:20'],
            $user->phone_number === null && $submittedPhone === '' => ['nullable'],
            default                                                => ['required', 'string', 'max:20', new PhilippineMobile()],
        };

        $request->validate([
            'first_name'   => 'required|string|max:100',
            'last_name'    => 'required|string|max:100',
            'phone_number' => $phoneRules,
            'address'      => 'nullable|string|max:500',
            'password'     => 'nullable|string|min:8|confirmed',
        ]);

        $user->first_name   = $request->first_name;
        $user->last_name    = $request->last_name;
        $user->phone_number = $phoneUnchanged
            ? $user->phone_number
            : PhilippineMobile::normalize($submittedPhone);
        // Only forms that carry the field may change it (the Security tab
        // form doesn't send it); an explicitly empty value still clears it.
        if ($request->has('address')) {
            $user->address = $request->address;
        }

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return back()->with('success', 'Profile updated successfully.');
    }

    /* ════════════════════════════════════════
       GOOGLE OAUTH — REDIRECT
    ════════════════════════════════════════ */
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    /* ════════════════════════════════════════
       GOOGLE OAUTH — HANDLE CALLBACK
    ════════════════════════════════════════ */
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Google sign-in failed. Please try again.']);
        }

        // Split full name into first/last
        $nameParts = explode(' ', $googleUser->getName(), 2);
        $firstName = $nameParts[0] ?? 'User';
        $lastName  = $nameParts[1] ?? '';

        $existingUser = User::where('email', $googleUser->getEmail())->first();

        if ($existingUser) {
            // Admin accounts never authenticate via Google — refuse
            // rather than silently demoting them to customer below.
            if ($existingUser->role === 'admin') {
                return redirect()->route('login')
                    ->withErrors(['email' => 'This account cannot sign in with Google.']);
            }

            // Only attach the Google identity to the existing account —
            // role, password, and account_status are account state that
            // a Google sign-in has no authority to change. The previous
            // updateOrCreate() here overwrote all of them on every
            // login, which also meant a re-authenticated Google user's
            // password silently got nulled out and status forced back
            // to "active" every time.
            $existingUser->update([
                'google_id'    => $googleUser->getId(),
                'google_token' => $googleUser->token,
            ]);

            $user = $existingUser;
        } else {
            $user = User::create([
                'first_name'        => $firstName,
                'last_name'         => $lastName,
                'email'             => $googleUser->getEmail(),
                'google_id'         => $googleUser->getId(),
                'google_token'      => $googleUser->token,
                'password'          => null, // Google users have no password
                'role'              => 'customer',
                'account_status'    => 'active',
                'email_verified_at' => now(),
            ]);
        }

        // No remember-me — this used to hardcode true, which meant
        // every Google sign-in got a persistent recaller cookie no
        // matter what, independent of the session itself.
        Auth::login($user);

        // One identity per browser: a customer session replaces any
        // admin session already active in this browser.
        Auth::guard('admin')->logout();

        request()->session()->regenerate();

        // New Google users go to validation, existing go to home
        if (!$user->is_validated) {
            return redirect()->route('validation.show')
                ->with('success', 'Signed in with Google! Please verify your identity to place orders.');
        }

        return redirect(SafeIntendedUrl::resolve(request(), route('home')))
            ->with('success', 'Welcome back, ' . $user->first_name . '!');
    }

    // Removed verifyRecaptcha — now handled inline in login()
}