<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\User;
use App\Rules\PhilippineMobile;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Everything that creates or changes an "Other Services" invoice: server-side validation and
 * money arithmetic (nothing posted by the browser is trusted for amounts), the delivery logic,
 * invoice numbers (INV-YYYY-NNNN), the draft -> issued -> paid / void lifecycle, the customer
 * notifications, and a timestamped line in the ticket's admin notes for every action.
 *
 * Deliberately separate from the payment ledger, Stripe, PayMongo and the custom order pipeline.
 */
final class ServiceInvoiceDesk
{
    public const TZ = 'Asia/Manila';
    public const MAX_AMOUNT = 999999.99;
    public const MAX_EXTRA_LINES = 20;

    public function __construct(private ServiceInquiryDesk $tickets)
    {
    }

    /* ───────────── prefill, validation, arithmetic ───────────── */

    /** "Labor service: dining table", from the ticket's service type and furniture type. */
    public static function suggestedService(ServiceInquiry $ticket): string
    {
        $base = match ($ticket->service_type) {
            'labor_only_own_wood' => 'Labor service',
            'repair'              => 'Repair service',
            default               => 'Service',
        };

        return $ticket->furniture_type ? $base . ': ' . mb_strtolower($ticket->furniture_type) : $base;
    }

    /**
     * Raw form input -> clean data: the snapshot and description, the itemised lines (service cost first,
     * then fees, then the delivery or home service line) and the totals. Throws a ValidationException.
     *
     * @return array{attributes: array, lines: list<array{kind: string, description: string, amount: string}>, subtotal: string, total: string}
     */
    public static function validate(array $input): array
    {
        $money = ['numeric', 'gt:0', 'max:' . self::MAX_AMOUNT, 'regex:/^\d+(\.\d{1,2})?$/'];
        $option = $input['delivery_option'] ?? null;
        $hasFee = isset(ServiceInvoice::DELIVERY_OPTIONS[$option]) && $option !== 'none';

        $validator = Validator::make($input, [
            'service_description' => ['required', 'string', 'max:200'],
            'service_cost'        => array_merge(['required'], $money),
            'lines'               => ['nullable', 'array', 'max:' . self::MAX_EXTRA_LINES],
            'lines.*.kind'        => ['nullable', Rule::in(['material_fee', 'other_fee'])],
            'lines.*.description' => ['nullable', 'string', 'max:200'],
            'lines.*.amount'      => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'gt:0', 'max:' . self::MAX_AMOUNT],
            'delivery_option'     => ['required', Rule::in(array_keys(ServiceInvoice::DELIVERY_OPTIONS))],
            'delivery_amount'     => $hasFee ? array_merge(['required'], $money) : ['nullable'],
            'notes'               => ['nullable', 'string', 'max:1000'],
            'customer_name'       => ['required', 'string', 'max:120'],
            'customer_phone'      => ['required', 'string', 'max:30', new PhilippineMobile()],
            'customer_email'      => ['nullable', 'email:rfc', 'max:150'],
            'customer_address'    => ['nullable', 'string', 'max:500'],
        ], [
            'service_description.required' => 'Enter the service the customer availed.',
            'service_cost.required'        => 'Enter the service cost agreed in the call.',
            'service_cost.gt'              => 'The service cost must be more than 0.',
            'service_cost.regex'           => 'Amounts can have at most 2 decimal places.',
            'service_cost.max'             => 'The service cost cannot be more than 999,999.99.',
            'lines.max'                    => 'You can add up to ' . self::MAX_EXTRA_LINES . ' fee lines.',
            'lines.*.amount.gt'            => 'Each fee amount must be more than 0.',
            'lines.*.amount.regex'         => 'Amounts can have at most 2 decimal places.',
            'lines.*.amount.max'           => 'A fee amount cannot be more than 999,999.99.',
            'delivery_option.required'     => 'Choose the delivery logic.',
            'delivery_option.in'           => 'Choose one of the listed delivery options.',
            'delivery_amount.required'     => 'Enter the delivery or service fee amount, or choose "No delivery or service fee".',
            'delivery_amount.gt'           => 'The delivery or service fee must be more than 0.',
            'delivery_amount.regex'        => 'Amounts can have at most 2 decimal places.',
            'customer_name.required'       => 'Enter the customer name.',
            'customer_phone.required'      => 'Enter the customer mobile number.',
        ]);

        // A fee line needs both a description and an amount; a fully blank row is simply skipped.
        $validator->after(function ($v) use ($input) {
            foreach (array_values((array) ($input['lines'] ?? [])) as $i => $row) {
                $desc   = trim((string) ($row['description'] ?? ''));
                $amount = trim((string) ($row['amount'] ?? ''));
                if ($desc === '' && $amount === '') {
                    continue;
                }
                if ($desc === '') {
                    $v->errors()->add("lines.{$i}.description", 'Describe this fee.');
                }
                if ($amount === '') {
                    $v->errors()->add("lines.{$i}.amount", 'Enter the amount for this fee.');
                }
            }
        });

        $data = $validator->validate();

        $lines = [[
            'kind'        => 'service_cost',
            'description' => trim($data['service_description']),
            'amount'      => self::money($data['service_cost']),
        ]];

        foreach (array_values((array) ($data['lines'] ?? [])) as $row) {
            $desc = trim((string) ($row['description'] ?? ''));
            if ($desc === '' || trim((string) ($row['amount'] ?? '')) === '') {
                continue;
            }
            $lines[] = [
                'kind'        => $row['kind'] ?? 'other_fee',
                'description' => $desc,
                'amount'      => self::money($row['amount']),
            ];
        }

        // "No delivery" forces the amount to 0 and makes no line, whatever was posted.
        $def = ServiceInvoice::DELIVERY_OPTIONS[$data['delivery_option']];
        if ($def['kind'] !== null) {
            $lines[] = ['kind' => $def['kind'], 'description' => $def['line'], 'amount' => self::money($data['delivery_amount'])];
        }

        $total = self::money(self::sumCents($lines) / 100);

        $name = NameFormat::proper(trim($data['customer_name']));

        return [
            'attributes' => [
                'service_description' => trim($data['service_description']),
                'delivery_option'     => $data['delivery_option'],
                'notes'               => self::blank($data['notes'] ?? null),
                'customer_name'       => $name,
                'customer_phone'      => PhilippineMobile::normalize($data['customer_phone']),
                'customer_email'      => self::blank($data['customer_email'] ?? null),
                'customer_address'    => self::blank($data['customer_address'] ?? null),
            ],
            'lines'    => $lines,
            'subtotal' => $total,
            'total'    => $total,
        ];
    }

    /** Whole centavos, so totals never pick up float noise. */
    public static function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** "1234.50" */
    public static function money(mixed $amount): string
    {
        return number_format(self::cents($amount) / 100, 2, '.', '');
    }

    /** @param iterable<array{amount: mixed}|ServiceInvoiceLine> $lines */
    public static function sumCents(iterable $lines): int
    {
        $sum = 0;
        foreach ($lines as $line) {
            $sum += self::cents(is_array($line) ? $line['amount'] : $line->amount);
        }

        return $sum;
    }

    private static function blank(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    /* ───────────── lifecycle ───────────── */

    /** The live (draft, issued or paid) invoice of a ticket, if it has one. */
    public static function activeFor(ServiceInquiry $ticket): ?ServiceInvoice
    {
        return ServiceInvoice::where('service_inquiry_id', $ticket->id)->active()->orderByDesc('id')->first();
    }

    public function createDraft(int $ticketId, User $admin, array $clean): ServiceInvoice
    {
        return DB::transaction(function () use ($ticketId, $admin, $clean) {
            $ticket = ServiceInquiry::lockForUpdate()->findOrFail($ticketId);

            if ($live = self::activeFor($ticket)) {
                throw new PipelineException('This ticket already has an invoice (' . ($live->invoice_number ?: 'draft #' . $live->id) . '). Void it first if you need to issue a new one.');
            }

            $invoice = ServiceInvoice::create($clean['attributes'] + [
                'service_inquiry_id'  => $ticket->id,
                'user_id'             => $ticket->user_id,
                'status'              => ServiceInvoice::DRAFT,
                'subtotal'            => $clean['subtotal'],
                'total'               => $clean['total'],
                'created_by_admin_id' => $admin->id,
            ]);
            $this->replaceLines($invoice, $clean['lines']);

            $this->tickets->appendNote($ticket, $admin, "Invoice draft #{$invoice->id} created, total " . self::peso($invoice->total));
            $ticket->save();

            return $invoice;
        });
    }

    public function updateDraft(int $invoiceId, User $admin, array $clean): ServiceInvoice
    {
        return DB::transaction(function () use ($invoiceId, $admin, $clean) {
            [$ticket, $invoice] = $this->lock($invoiceId);
            $this->requireStatus($invoice, [ServiceInvoice::DRAFT], 'edit it. An issued invoice is locked; void it and issue a new one');

            $invoice->fill($clean['attributes'] + ['subtotal' => $clean['subtotal'], 'total' => $clean['total']])->save();
            $this->replaceLines($invoice, $clean['lines']);

            $this->tickets->appendNote($ticket, $admin, "Invoice draft #{$invoice->id} updated, total " . self::peso($invoice->total));
            $ticket->save();

            return $invoice->refresh();
        });
    }

    public function deleteDraft(int $invoiceId, User $admin): void
    {
        DB::transaction(function () use ($invoiceId, $admin) {
            [$ticket, $invoice] = $this->lock($invoiceId);
            $this->requireStatus($invoice, [ServiceInvoice::DRAFT], 'delete it. Only a draft can be deleted; an issued invoice is voided instead');

            foreach ($invoice->lines as $line) {
                $line->delete();
            }
            $invoice->delete();

            $this->tickets->appendNote($ticket, $admin, "Invoice draft #{$invoiceId} deleted");
            $ticket->save();
        });
    }

    /** Fixes the invoice number and locks the invoice. Notifies the customer when the ticket has an account. */
    public function issue(int $invoiceId, User $admin): ServiceInvoice
    {
        return DB::transaction(function () use ($invoiceId, $admin) {
            [$ticket, $invoice] = $this->lock($invoiceId);
            $this->requireStatus($invoice, [ServiceInvoice::DRAFT], 'issue it');

            // Re-derive the total from the saved lines: an invoice is issued from what is stored, not from a page.
            $cents = self::sumCents($invoice->lines);
            if ($cents <= 0) {
                throw new PipelineException('An invoice needs a service cost greater than 0 before it can be issued.');
            }
            $total = self::money($cents / 100);

            $now = Carbon::now(self::TZ);
            for ($attempt = 0; ; $attempt++) {
                try {
                    // A savepoint keeps a duplicate-number error from poisoning the outer transaction.
                    DB::transaction(function () use ($invoice, $total, $now, $attempt) {
                        $invoice->forceFill([
                            'invoice_number' => self::nextNumber($now, $attempt),
                            'subtotal'       => $total,
                            'total'          => $total,
                            'status'         => ServiceInvoice::ISSUED,
                            'issued_at'      => $now->copy()->setTimezone(config('app.timezone')),
                        ])->save();
                    });
                    break;
                } catch (QueryException $e) {
                    if ($attempt >= 5 || !str_contains($e->getMessage(), 'invoice_number')) {
                        throw $e;
                    }
                    $invoice->refresh();
                }
            }

            $this->tickets->appendNote($ticket, $admin, "Invoice {$invoice->invoice_number} issued, total " . self::peso($invoice->total));
            $ticket->save();

            if ($invoice->user_id) {
                $this->notify($invoice, 'service_invoice_issued', ['total' => $invoice->total, 'service' => $invoice->service_description]);
            }

            return $invoice;
        });
    }

    /**
     * @param array{method: string, reference: ?string, amount: mixed, date: string} $payment
     */
    public function markPaid(int $invoiceId, User $admin, array $payment): ServiceInvoice
    {
        $method = $payment['method'] ?? null;
        if (!isset(ServiceInvoice::PAID_METHODS[$method])) {
            throw new PipelineException('Choose how the customer paid.');
        }
        $reference = self::blank($payment['reference'] ?? null);
        if ($method !== 'cash' && $reference === null) {
            throw new PipelineException('Enter the reference number for a GCash or bank transfer payment.');
        }

        return DB::transaction(function () use ($invoiceId, $admin, $payment, $method, $reference) {
            [$ticket, $invoice] = $this->lock($invoiceId);
            $this->requireStatus($invoice, [ServiceInvoice::ISSUED], 'record a payment for it');

            if (self::cents($payment['amount']) < self::cents($invoice->total)) {
                throw new PipelineException('The amount received must cover the invoice total of ' . self::peso($invoice->total) . '.');
            }

            $day   = Carbon::parse($payment['date'], self::TZ)->startOfDay();
            $today = Carbon::now(self::TZ)->startOfDay();
            if ($day->gt($today)) {
                throw new PipelineException('The payment date cannot be in the future.');
            }
            $paidAt = $day->equalTo($today) ? Carbon::now(self::TZ) : $day->copy()->setTime(12, 0);

            $invoice->forceFill([
                'status'          => ServiceInvoice::PAID,
                'paid_at'         => $paidAt->copy()->setTimezone(config('app.timezone')),
                'paid_method'     => $method,
                'paid_reference'  => $reference,
                'amount_received' => self::money($payment['amount']),
            ])->save();

            $this->tickets->appendNote($ticket, $admin, "Invoice {$invoice->invoice_number} marked paid: " . ServiceInvoice::PAID_METHODS[$method]
                . ($reference ? ", reference {$reference}" : '') . ', ' . self::peso($invoice->amount_received) . ' received on ' . $paidAt->format('M d, Y'));
            $ticket->save();

            if ($invoice->user_id) {
                $this->notify($invoice, 'service_invoice_paid', ['amount' => $invoice->amount_received, 'when' => $paidAt->format('M d, Y')]);
            }

            return $invoice;
        });
    }

    public function void(int $invoiceId, User $admin, string $reason): ServiceInvoice
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new PipelineException('A reason is required to void an invoice.');
        }
        if (mb_strlen($reason) > 1000) {
            throw new PipelineException('Please keep the reason under 1,000 characters.');
        }

        return DB::transaction(function () use ($invoiceId, $admin, $reason) {
            [$ticket, $invoice] = $this->lock($invoiceId);
            $this->requireStatus($invoice, [ServiceInvoice::ISSUED, ServiceInvoice::PAID], 'void it');

            $invoice->forceFill([
                'status'      => ServiceInvoice::VOID,
                'voided_at'   => Carbon::now(self::TZ)->setTimezone(config('app.timezone')),
                'void_reason' => $reason,
            ])->save();

            $this->tickets->appendNote($ticket, $admin, "Invoice {$invoice->invoice_number} voided. {$reason}");
            $ticket->save();

            return $invoice;
        });
    }

    /* ───────────── internals ───────────── */

    /** INV-YYYY-NNNN for the Manila year; $attempt moves past a number another request took first. */
    public static function nextNumber(Carbon $local, int $attempt = 0): string
    {
        $prefix = 'INV-' . $local->format('Y') . '-';

        $last = ServiceInvoice::where('invoice_number', 'like', $prefix . '%')
            ->orderByRaw('LENGTH(invoice_number) DESC')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $seq = $last ? ((int) substr($last, strlen($prefix))) : 0;

        return $prefix . str_pad((string) ($seq + 1 + $attempt), 4, '0', STR_PAD_LEFT);
    }

    public static function peso(mixed $amount): string
    {
        return '₱' . number_format((float) $amount, 2);
    }

    /** @return array{0: ServiceInquiry, 1: ServiceInvoice} ticket first, then invoice: the same lock order everywhere */
    private function lock(int $invoiceId): array
    {
        $ticketId = ServiceInvoice::whereKey($invoiceId)->value('service_inquiry_id');
        if (!$ticketId) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        }
        $ticket  = ServiceInquiry::lockForUpdate()->findOrFail($ticketId);
        $invoice = ServiceInvoice::lockForUpdate()->findOrFail($invoiceId);

        return [$ticket, $invoice];
    }

    private function requireStatus(ServiceInvoice $invoice, array $allowed, string $action): void
    {
        if (!in_array($invoice->status, $allowed, true)) {
            throw new PipelineException("This invoice is {$invoice->status}, so you cannot {$action}.");
        }
    }

    /** @param list<array{kind: string, description: string, amount: string}> $lines */
    private function replaceLines(ServiceInvoice $invoice, array $lines): void
    {
        foreach ($invoice->lines()->get() as $old) {
            $old->delete();
        }
        foreach ($lines as $line) {
            ServiceInvoiceLine::create($line + ['invoice_id' => $invoice->id]);
        }
        $invoice->unsetRelation('lines');
    }

    private function notify(ServiceInvoice $invoice, string $template, array $values): void
    {
        $t = NotificationTemplates::render($template, $values + ['invoice' => $invoice->invoice_number]);
        Notification::create([
            'user_id' => $invoice->user_id, 'title' => $t['title'], 'message' => $t['message'],
            'type' => 'general', 'url' => route('services.invoice', $invoice->id), 'is_read' => false,
        ]);
    }
}
