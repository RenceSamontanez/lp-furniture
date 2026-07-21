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
        'address',
        'role',
        'profile_image',
        'account_status',
        'google_id',
        'google_token',
        'is_validated',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'google_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_validated'      => 'boolean',
        'password'          => 'hashed',
    ];

    /* ── RELATIONSHIPS ── */
    public function cartItems()    { return $this->hasMany(CartItem::class); }
    public function orders()       { return $this->hasMany(Order::class); }
    public function notifications(){ return $this->hasMany(Notification::class); }
    public function feedbacks()    { return $this->hasMany(Feedback::class); }
    public function chatbotLogs()  { return $this->hasMany(ChatbotLog::class); }
    public function designRequests(){ return $this->hasMany(CustomDesignRequest::class); }
    public function payments()     { return $this->hasMany(Payment::class); }
    public function validation()   { return $this->hasOne(UserValidation::class); }

    /* ── SCOPES ── */
    public function scopeCustomers($query) { return $query->where('role', 'customer'); }
    public function scopeActive($query)    { return $query->where('account_status', 'active'); }
}