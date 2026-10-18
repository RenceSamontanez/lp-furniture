<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One try at giving the money back (sent, or did not go through). Kept forever as history. */
class ServiceRefundAttempt extends Model
{
    public const SENT                 = 'sent';
    public const FAILED_WRONG_DETAILS = 'failed_wrong_details';
    public const FAILED_OTHER         = 'failed_other';

    protected $fillable = ['service_refund_request_id', 'amount', 'method', 'transaction_number', 'sent_on', 'outcome', 'note', 'admin_id'];

    protected $casts = ['amount' => 'decimal:2', 'sent_on' => 'date'];

    public function request()
    {
        return $this->belongsTo(ServiceRefundRequest::class, 'service_refund_request_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
