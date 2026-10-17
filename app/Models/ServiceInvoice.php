<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;

/**
 * A simple invoice for an "Other Services" ticket. Lifecycle: draft (editable) -> issued (locked)
 * -> paid, or issued/paid -> void. Once issued, the lines, amounts and customer snapshot can never
 * change and the invoice can never be deleted: a mistake is voided (with a reason) and a new
 * invoice is issued. The guards below enforce that at the model layer, like the other immutable
 * records. (Raw SQL or DB::table() can still bypass a model guard.)
 *
 * This is not an official receipt and has nothing to do with the payment ledger.
 */
class ServiceInvoice extends Model
{
    public const DRAFT  = 'draft';
    public const ISSUED = 'issued';
    public const PAID   = 'paid';
    public const VOID   = 'void';

    public const STATUSES = [
        self::DRAFT  => 'Draft',
        self::ISSUED => 'Sent',
        self::PAID   => 'Paid',
        self::VOID   => 'Cancelled',
    ];

    /** Shown in the "Send to customer" confirmation (admin pages). One place, so the wording stays the same everywhere. */
    public const SEND_CONFIRMATION = 'Send this invoice to the customer? They will be notified and can pay it online. Once sent, it can no longer be edited. If something is wrong you can cancel it and send a new one.';

    /** Shown with the reason box when an admin cancels a sent or paid invoice. */
    public const CANCEL_CONFIRMATION = 'Cancel this invoice? The customer will no longer be able to pay it. This cannot be undone. Please enter a reason.';

    public const PAID_METHODS = [
        'cash'          => 'Cash',
        'gcash_direct'  => 'GCash (direct to the shop)',
        'bank_transfer' => 'Bank transfer',
    ];

    /** The delivery choice, in plain words, and the line it creates (null = no line). */
    public const DELIVERY_OPTIONS = [
        'none' => [
            'label' => 'No delivery or service fee (customer brings the furniture and picks it up)',
            'short' => 'No delivery or service fee',
            'kind'  => null,
            'line'  => null,
        ],
        'home_service' => [
            'label' => "Home service fee (we do the work at the customer's location)",
            'short' => 'Home service fee',
            'kind'  => 'home_service_fee',
            'line'  => 'Home service fee',
        ],
        'delivery_one_way' => [
            'label' => 'Delivery fee, one way (furniture delivered to the shop, or returned to the customer)',
            'short' => 'Delivery fee, one way',
            'kind'  => 'delivery_fee',
            'line'  => 'Delivery fee (one way)',
        ],
        'delivery_round_trip' => [
            'label' => 'Delivery fee, round trip (picked up and returned)',
            'short' => 'Delivery fee, round trip',
            'kind'  => 'delivery_fee',
            'line'  => 'Delivery fee (round trip)',
        ],
    ];

    /** What may change on an issued invoice (nothing else), per status. */
    private const PAY_FIELDS  = ['status', 'paid_at', 'paid_method', 'paid_reference', 'amount_received'];
    private const VOID_FIELDS = ['status', 'voided_at', 'void_reason'];

    protected $fillable = [
        'service_inquiry_id', 'user_id', 'invoice_number', 'status',
        'customer_name', 'customer_phone', 'customer_email', 'customer_address',
        'service_description', 'delivery_option', 'notes', 'subtotal', 'total',
        'issued_at', 'paid_at', 'paid_method', 'paid_reference', 'amount_received',
        'voided_at', 'void_reason', 'created_by_admin_id',
    ];

    protected $casts = [
        'subtotal'        => 'decimal:2',
        'total'           => 'decimal:2',
        'amount_received' => 'decimal:2',
        'issued_at'       => 'datetime',
        'paid_at'         => 'datetime',
        'voided_at'       => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $invoice) {
            $from  = $invoice->getOriginal('status');
            $dirty = array_diff(array_keys($invoice->getDirty()), ['updated_at']);
            $to    = $invoice->status;

            if ($from === self::DRAFT) {
                // Editable; the only way out is to be issued (which fixes the number).
                if ($to !== self::DRAFT && $to !== self::ISSUED) {
                    throw new ImmutableRecordException('A draft invoice can only be sent.');
                }

                return;
            }

            $allowed = match ($from) {
                self::ISSUED => $to === self::PAID ? self::PAY_FIELDS : ($to === self::VOID ? self::VOID_FIELDS : []),
                self::PAID   => $to === self::VOID ? self::VOID_FIELDS : [],
                default      => [],
            };

            if ($dirty && (array_diff($dirty, $allowed) || $to === $from)) {
                throw new ImmutableRecordException(
                    "Invoice #{$invoice->getKey()} is {$from} and cannot be changed. Cancel it and send a new invoice instead."
                );
            }
        });

        static::deleting(function (self $invoice) {
            if ($invoice->getOriginal('status') !== self::DRAFT) {
                throw new ImmutableRecordException("Invoice #{$invoice->getKey()} is {$invoice->getOriginal('status')} and cannot be deleted.");
            }
        });
    }

    public function inquiry()
    {
        return $this->belongsTo(ServiceInquiry::class, 'service_inquiry_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function lines()
    {
        return $this->hasMany(ServiceInvoiceLine::class, 'invoice_id')->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /**
     * The one place the stored status values (draft, issued, paid, void) turn into the words people read:
     * Draft, Sent, Paid, Cancelled. The stored values and constants stay as they are; every screen, message
     * and note goes through here so the UI never shows "issued" or "void".
     */
    public static function labelFor(?string $status): string
    {
        return self::STATUSES[$status] ?? (string) $status;
    }

    public function statusLabel(): string
    {
        return self::labelFor($this->status);
    }

    public function deliveryLabel(): string
    {
        return self::DELIVERY_OPTIONS[$this->delivery_option]['label'] ?? (string) $this->delivery_option;
    }

    public function paidMethodLabel(): ?string
    {
        return self::PAID_METHODS[$this->paid_method] ?? $this->paid_method;
    }

    /** Invoices a customer may open: issued and paid ones. */
    public function scopeVisibleToCustomer($query)
    {
        return $query->whereIn('status', [self::ISSUED, self::PAID]);
    }

    /** A ticket can carry one live invoice at a time: draft, issued or paid. */
    public function scopeActive($query)
    {
        return $query->where('status', '!=', self::VOID);
    }
}
