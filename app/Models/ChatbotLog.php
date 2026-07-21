<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatbotLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'session_id',
        'message',
        'response',
        'intent_detected',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // Belongs to a user
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}