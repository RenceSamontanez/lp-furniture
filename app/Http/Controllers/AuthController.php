<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserNameChange;
use App\Rules\PhilippineMobile;
use App\Support\AccountPassword;
use App\Support\DeliveryAddress;
use App\Support\PhoneNumber;
use App\Support\SafeIntendedUrl;
use App\Support\TextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
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

            \App\Support\ActivityLog::signedIn($user);

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
            'phone_e164'     => PhoneNumber::parse($request->phone_number, 'PH')['e164'] ?? null,
            'phone_country'  => 'PH',
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

// A guest who was sent here from a protected page (for example /services/request) goes back to it.
$intended = SafeIntendedUrl::resolve($request, route('verification.notice'));

return redirect($intended)
    ->with('success', 'Account created! Please check your email to verify your account.');
    }
    /* ════════════════════════════════════════
       LOGOUT
    ════════════════════════════════════════ */
    public function logout(Request $request)
    {
        \App\Support\Luma\ChatState::forget();   // Luma's conversation never outlives the sign-in
        \App\Support\ActivityLog::signedOut(Auth::guard('web')->user());
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
        $user = Auth::user();
        $validation = $user->validation;
        $e164 = PhoneNumber::forUser($user);
        [$country, $national] = PhoneNumber::split($e164);
        $saved = DeliveryAddress::fromUser($user);
        $tab = request('tab');
        if (!in_array($tab, ['personal', 'security', 'verify', 'account'], true)) {
            $tab = session('tab', 'personal');
        }

        return view('profile.index', [
            'user'          => $user,
            'tab'           => $tab,
            'validation'    => $validation,
            'acceptedIds'   => config('identity.accepted_ids'),
            'checks'        => config('identity.checks'),
            'attempt'       => ($validation?->attempt_number ?? 0) + 1,
            'countries'     => PhoneNumber::countries(),
            'phoneCountry'  => $user->phone_country ?: $country,
            'phoneNational' => $e164 ? $national : '',
            'phoneDisplay'  => PhoneNumber::display($e164),
            'addressLine'   => $saved ? DeliveryAddress::line($saved) : '',
            'savedAddress'  => $saved ? $saved + DeliveryAddress::names($saved) : null,
            'legacyAddress' => $saved ? null : (trim((string) $user->address) !== '' ? trim($user->address) : null),
            'googleOnly'    => AccountPassword::isGoogleOnly($user),
            'pwAvailableAt' => AccountPassword::availableAt($user),
            'pwDaysAgo'     => AccountPassword::daysAgo($user),
        ]);
    }

    /* ════════════════════════════════════════
       UPDATE ONE CARD OF PERSONAL INFO
       One endpoint, a `section` field: name | phone | address. Each card saves on its own and never touches the
       others. JSON for the page script (fetch), a normal redirect without it.
    ════════════════════════════════════════ */
    public const NAME_CHANGE_NOTICE = 'Your name must match your ID. If it changes, we may ask you to verify again.';

    public function updateSection(Request $request)
    {
        $section = (string) $request->input('section');
        if (!in_array($section, ['name', 'phone', 'address'], true)) {
            throw ValidationException::withMessages(['section' => 'We could not tell which part to save. Reload the page and try again.']);
        }

        [$message, $values] = match ($section) {
            'name'    => $this->saveName($request),
            'phone'   => $this->savePhone($request),
            'address' => $this->saveAddress($request),
        };

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'section' => $section, 'message' => $message, 'values' => $values]);
        }

        return redirect()->route('profile', ['tab' => 'personal'])->with('success', $message);
    }

    private function saveName(Request $request): array
    {
        $request->validate([
            'first_name' => DeliveryAddress::nameRules(),
            'last_name'  => DeliveryAddress::nameRules(),
        ], [
            'first_name.required' => 'Enter your first name.',
            'last_name.required'  => 'Enter your last name.',
            'first_name.regex'    => 'Use letters only. No numbers or symbols.',
            'last_name.regex'     => 'Use letters only. No numbers or symbols.',
        ]);

        $first = TextSanitizer::line($request->first_name);
        $last  = TextSanitizer::line($request->last_name);

        $changed = DB::transaction(function () use ($request, $first, $last) {
            $user = User::whereKey(Auth::id())->lockForUpdate()->first();
            if ($user->first_name === $first && $user->last_name === $last) {
                return false;
            }
            // A verified account is matched against an ID photo: the customer must see that warning before saving.
            if ($user->is_validated && !$request->boolean('confirm_name_change')) {
                throw ValidationException::withMessages(['name_confirm' => self::NAME_CHANGE_NOTICE]);
            }

            UserNameChange::create([
                'user_id' => $user->id, 'old_first_name' => (string) $user->first_name, 'old_last_name' => (string) $user->last_name,
                'new_first_name' => $first, 'new_last_name' => $last, 'was_verified' => (bool) $user->is_validated,
            ]);
            // the verification is NOT reset here: an admin decides from the history on the customer page
            $user->forceFill(['first_name' => $first, 'last_name' => $last])->save();

            return true;
        });

        return [$changed ? 'Name saved' : 'Name saved. Nothing was different.', ['first_name' => $first, 'last_name' => $last, 'full_name' => trim($first . ' ' . $last)]];
    }

    private function savePhone(Request $request): array
    {
        $phone = PhoneNumber::parse((string) $request->input('phone_number'), (string) $request->input('phone_country', PhoneNumber::DEFAULT_COUNTRY));
        if (!$phone['ok']) {
            throw ValidationException::withMessages(['phone_number' => $phone['message']]);
        }

        DB::transaction(function () use ($phone) {
            $user = User::whereKey(Auth::id())->lockForUpdate()->first();
            $user->forceFill([
                'phone_e164' => $phone['e164'], 'phone_country' => $phone['country'], 'phone_number' => $phone['legacy'],
            ])->save();
        });

        [$country, $national] = PhoneNumber::split($phone['e164']);

        return ['Phone number saved', [
            'phone_country' => $country, 'phone_number' => $national, 'display' => PhoneNumber::display($phone['e164']), 'e164' => $phone['e164'],
        ]];
    }

    private function saveAddress(Request $request): array
    {
        // all of it or nothing: an empty or half-filled address is refused (Cancel is how to leave without saving)
        $address = DeliveryAddress::validate($request->all());

        DB::transaction(function () use ($address) {
            $user = User::whereKey(Auth::id())->lockForUpdate()->first();
            DeliveryAddress::saveFor($user, $address);
        });

        $names = DeliveryAddress::names($address);

        return ['Delivery address saved', $address + $names + ['line' => DeliveryAddress::line($address, $names)]];
    }

    /* ════════════════════════════════════════
       CHANGE PASSWORD (Security tab)
       Never expires. After a change here the next one waits 7 days. Forgot-password is not affected.
    ════════════════════════════════════════ */
    public function updatePassword(Request $request)
    {
        $back = fn () => redirect()->route('profile', ['tab' => 'security']);

        $user = Auth::user();
        if (AccountPassword::isGoogleOnly($user)) {
            return $back()->with('error', 'This account signs in with Google, so there is no password to change here.');
        }

        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ], [
            'current_password.required' => 'Enter your current password.',
            'password.required'         => 'Enter a new password.',
            'password.confirmed'        => 'The two new passwords do not match.',
            'password.min'              => 'Use at least 8 characters.',
            'password.mixed'            => 'Use upper and lower case letters.',
            'password.numbers'          => 'Include a number.',
        ]);

        $changed = DB::transaction(function () use ($user, $request, &$error) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();

            if (AccountPassword::isLocked($locked)) {
                $error = AccountPassword::lockedMessage($locked);
                return false;
            }
            if (!Hash::check($request->current_password, (string) $locked->password)) {
                $error = 'Your current password is not correct.';
                return false;
            }
            if (Hash::check($request->password, (string) $locked->password)) {
                $error = 'Choose a password that is different from your current one.';
                return false;
            }

            $locked->password = $request->password;          // hashed by the model cast
            $locked->password_changed_at = now();
            $locked->save();

            return true;
        });

        if (!$changed) {
            return $back()->withErrors(['password_form' => $error]);
        }

        // Other browsers and devices hold a stamp of the old password and are signed out on their next request;
        // this one takes the new stamp and stays signed in.
        $user->setRawAttributes(User::findOrFail($user->id)->getAttributes(), true);
        \App\Http\Middleware\SignOutOnPasswordChange::stamp($request, $user);

        return $back()->with('success', 'Password updated. Other devices were signed out.');
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

        \App\Support\ActivityLog::signedIn($user);

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