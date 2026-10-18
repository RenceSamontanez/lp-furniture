<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of the visible thread between the customer and the team on a cancellation / refund request. */
class ServiceRefundMessage extends Model
{
    protected $fillable = ['service_refund_request_id', 'sender', 'admin_id', 'body'];

    public function request()
    {
        return $this->belongsTo(ServiceRefundRequest::class, 'service_refund_request_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
