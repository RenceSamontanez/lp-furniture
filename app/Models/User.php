<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class User extends Authenticatable implements MustVerifyEmail
{
    use Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'phone_number',
        'phone_e164',
        'phone_country',
        'address',
        'role',
        'profile_image',
        'account_status',
        'google_id',
        'google_token',
        'is_validated',
        'email_verified_at',
        'saved_contact_phone',
        'saved_contact_email',
        'contact_saved_at',
    ];

    // A changed password cancels every "keep me signed in" cookie: the remember
    // token is replaced in the same save (unless the caller already set one).
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->exists && $user->isDirty('password') && ! $user->isDirty('remember_token')) {
                $user->setRememberToken(\Illuminate\Support\Str::random(60));
            }
        });
    }

    // Display only; the stored name is never changed.
    public function getDisplayNameAttribute(): string
    {
        return \App\Support\NameFormat::proper(trim($this->first_name . ' ' . $this->last_name));
    }

    protected $hidden = [
        'password',
        'remember_token',
        'google_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_validated'      => 'boolean',
        'contact_saved_at'  => 'datetime',
        'last_login_at'     => 'datetime',
        'password_changed_at' => 'datetime',
        'password'          => 'hashed',
    ];

    /* ── RELATIONSHIPS ── */
    public function cartItems()    { return $this->hasMany(CartItem::class); }
    public function orders()       { return $this->hasMany(Order::class); }
    public function nameChanges()  { return $this->hasMany(UserNameChange::class)->latest('id'); }
    public function notifications(){ return $this->hasMany(Notification::class); }
    public function feedbacks()    { return $this->hasMany(Feedback::class); }
    public function chatbotLogs()  { return $this->hasMany(ChatbotLog::class); }
    public function designRequests(){ return $this->hasMany(CustomDesignRequest::class); }
    public function payments()     { return $this->hasMany(Payment::class); }
    /** The customer's latest identity attempt. */
    public function validation()   { return $this->hasOne(UserValidation::class)->latestOfMany('attempt_number'); }
    public function validations()  { return $this->hasMany(UserValidation::class)->orderBy('attempt_number'); }

    /* ── PHONE ── */

    // True when phone_number is a valid PH mobile number (any accepted
    // format). Numbers are not OTP-verified; this is a format check only.
    public function hasValidPhone(): bool
    {
        return \App\Support\PhoneNumber::forUser($this) !== null;
    }

    /* ── SCOPES ── */
    public function scopeCustomers($query) { return $query->where('role', 'customer'); }
    public function scopeActive($query)    { return $query->where('account_status', 'active'); }
}