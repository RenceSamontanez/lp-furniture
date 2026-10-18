<?php

namespace App\Support;

use App\Models\CustomDesignRequest;
use App\Models\Notification;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Rules\PhilippineMobile;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Everything that creates or changes an "Other Services" ticket: validation rules shared
 * by the public form and the admin walk-in form, reference numbers (SI-YYYY-NNNN), the
 * notifications, and the call update / notes / close actions. Tickets never become orders.
 */
final class ServiceInquiryDesk
{
    public const TZ = 'Asia/Manila';
    public const DATE_HORIZON_DAYS = 30;

    /** Status each call outcome leaves a ticket in. */
    public const STATUS_FOR = [
        CallUpdateMessage::UNREACHABLE => 'unreachable',
        CallUpdateMessage::RINGING     => 'contacting',
        CallUpdateMessage::ANSWERED    => 'called',
    ];

    public static function today(): Carbon
    {
        return Carbon::now(self::TZ)->startOfDay();
    }

    /** @return array<string, mixed> */
    public static function rules(bool $emailRequired): array
    {
        return [
            'service_type'          => ['required', Rule::in(array_keys(ServiceInquiry::SERVICE_TYPES))],
            'furniture_type'        => ['nullable', 'string', Rule::in(FurnitureTypes::LIST)],
            'concern'               => ['required', 'string', 'min:10', 'max:2000'],
            'name'                  => ['required', 'string', 'max:120'],
            'phone'                 => ['required', 'string', 'max:30', new PhilippineMobile()],
            'email'                 => [$emailRequired ? 'required' : 'nullable', 'email:rfc', 'max:150'],
            'preferred_call_window' => ['nullable', Rule::in(array_keys(CustomDesignRequest::CALL_WINDOWS))],
            'preferred_call_date'   => [
                'nullable', 'date_format:Y-m-d',
                'after_or_equal:' . self::today()->toDateString(),
                'before_or_equal:' . self::today()->addDays(self::DATE_HORIZON_DAYS)->toDateString(),
                function ($attribute, $value, $fail) {
                    if ($value && Carbon::parse($value)->isWeekend()) {
                        $fail('We call Monday to Friday only. Please choose a weekday.');
                    }
                },
            ],
        ];
    }

    public static function messages(): array
    {
        return [
            'service_type.required'               => 'Choose the kind of service you need.',
            'service_type.in'                     => 'Choose one of the listed services.',
            'furniture_type.in'                   => 'Choose one of the listed furniture types.',
            'concern.required'                    => 'Tell us a little about what you need.',
            'concern.min'                         => 'Please describe your concern in a few more words.',
            'concern.max'                         => 'Please keep your concern under 2,000 characters.',
            'name.required'                       => 'Please enter your name.',
            'phone.required'                      => 'Please enter your mobile number.',
            'email.required'                      => 'Please enter your email.',
            'email.email'                         => 'Please enter a valid email address.',
            'preferred_call_window.in'            => 'Choose one of the listed call times.',
            'preferred_call_date.date_format'     => 'Choose a valid date.',
            'preferred_call_date.after_or_equal'  => 'The preferred date cannot be in the past.',
            'preferred_call_date.before_or_equal' => 'Please choose a date within the next ' . self::DATE_HORIZON_DAYS . ' days.',
        ];
    }

    /** Next reference for the current year. The unique index is the real guard; insert retries. */
    public static function nextReference(): string
    {
        $year   = self::today()->format('Y');
        $prefix = "SI-{$year}-";
        $last   = ServiceInquiry::where('reference_number', 'like', $prefix . '%')->max('reference_number');
        $n      = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Validated data (see rules()) -> a saved ticket, with notifications.
     * $admin is the staff member entering it for a walk-in or phone customer.
     */
    public function create(array $data, ?User $customer = null, ?User $admin = null): ServiceInquiry
    {
        $email = isset($data['email']) ? trim((string) $data['email']) : '';

        $attributes = [
            'user_id'               => $customer?->id,
            'service_type'          => $data['service_type'],
            'furniture_type'        => $data['furniture_type'] ?? null,
            'concern'               => trim($data['concern']),
            'name'                  => NameFormat::proper(trim($data['name'])),
            'phone'                 => PhilippineMobile::normalize($data['phone']),
            'email'                 => $email !== '' ? $email : null,
            'preferred_call_window' => $data['preferred_call_window'] ?? null,
            'preferred_call_date'   => $data['preferred_call_date'] ?? null,
            'status'                => 'new',
            'created_by_admin_id'   => $admin?->id,
            'terms_accepted_at'     => !empty($data['terms']) ? now() : null,   // only the customer's own form asks for it
        ];

        return DB::transaction(function () use ($attributes, $customer, $admin) {
            $inquiry = $this->insertWithReference($attributes);

            $values = [
                'reference' => $inquiry->reference_number,
                'phone'     => $inquiry->phone,
                'window'    => $inquiry->callTimeSummary(),
            ];

            if ($customer && !$admin) {
                $t = NotificationTemplates::render('inquiry_received', $values);
                Notification::create([
                    'user_id' => $customer->id, 'title' => $t['title'], 'message' => $t['message'],
                    'type' => 'general', 'url' => route('services.request'), 'is_read' => false,
                ]);
            }

            $t = NotificationTemplates::render('admin_new_inquiry', $values + [
                'customer' => $inquiry->name,
                'service'  => strtolower($inquiry->serviceLabel()),
            ]);
            foreach (User::where('role', 'admin')->pluck('id') as $adminId) {
                if ($admin && (int) $adminId === (int) $admin->id) {
                    continue; // the admin who typed it in already knows
                }
                Notification::create([
                    'user_id' => $adminId, 'title' => $t['title'], 'message' => $t['message'],
                    'type' => 'general', 'url' => url('/admin/service-inquiries/' . $inquiry->id), 'is_read' => false,
                ]);
            }

            return $inquiry;
        });
    }

    private function insertWithReference(array $attributes): ServiceInquiry
    {
        for ($try = 0; ; $try++) {
            try {
                // A savepoint keeps a duplicate-key error from poisoning the outer transaction.
                return DB::transaction(fn () => ServiceInquiry::create($attributes + ['reference_number' => self::nextReference()]));
            } catch (QueryException $e) {
                if ($try >= 4 || !str_contains($e->getMessage(), 'reference_number')) {
                    throw $e;
                }
            }
        }
    }

    /** What the admin tool's live preview needs: the same templates as are sent, plus the values. */
    public static function previewData(ServiceInquiry $inquiry): array
    {
        $t = NotificationTemplates::TEMPLATES;

        return [
            'templates' => [
                'unreachable' => $t['inquiry_call_unreachable']['body'],
                'ringing'     => $t['inquiry_call_ringing']['body'],
                'answered'    => $t['inquiry_call_answered']['body'],
            ],
            'titles' => [
                'unreachable' => $t['inquiry_call_unreachable']['title'],
                'ringing'     => $t['inquiry_call_ringing']['title'],
                'answered'    => $t['inquiry_call_answered']['title'],
            ],
            'values' => [
                'reference' => $inquiry->reference_number,
                'contact'   => NotificationTemplates::contact(),
            ],
        ];
    }

    /**
     * Log a call. Moves the ticket to unreachable / contacting / called, counts attempts,
     * writes a line to the notes and notifies the customer when the ticket belongs to an account.
     */
    public function callUpdate(int $id, User $admin, string $outcome, ?int $attempts, ?string $note): ServiceInquiry
    {
        if (!isset(CallUpdateMessage::LABELS[$outcome])) {
            throw new PipelineException('Choose the call outcome.');
        }
        $needs = CallUpdateMessage::needsAttempts($outcome);
        if ($needs && ($attempts === null || $attempts < 1 || $attempts > 20)) {
            throw new PipelineException('Call attempts must be a number from 1 to 20.');
        }

        return DB::transaction(function () use ($id, $admin, $outcome, $attempts, $note, $needs) {
            $inquiry = $this->lockOpen($id, 'send a call update');
            $note    = $note !== null && trim($note) !== '' ? trim($note) : null;

            $inquiry->attempts          = $needs ? max((int) $inquiry->attempts, (int) $attempts) : (int) $inquiry->attempts + 1;
            $inquiry->status            = self::STATUS_FOR[$outcome];
            $inquiry->last_contacted_at = now();

            $this->appendNote($inquiry, $admin, CallUpdateMessage::LABELS[$outcome]
                . ($needs ? ', ' . CallUpdateMessage::times((int) $attempts) . ' called' : '')
                . ($note ? '. ' . $note : ''));
            $inquiry->save();

            if ($inquiry->user_id) {
                $t = NotificationTemplates::render('inquiry_call_' . $outcome, [
                    'reference' => $inquiry->reference_number,
                    'times'     => CallUpdateMessage::times(max(1, (int) $attempts)),
                ]);
                Notification::create([
                    'user_id' => $inquiry->user_id, 'title' => $t['title'],
                    'message' => $t['message'] . ($note ? "\n" . $note : ''),
                    'type' => 'general', 'url' => route('services.request'), 'is_read' => false,
                ]);
            }

            return $inquiry;
        });
    }

    public function addNote(int $id, User $admin, string $note): ServiceInquiry
    {
        return DB::transaction(function () use ($id, $admin, $note) {
            $inquiry = ServiceInquiry::lockForUpdate()->findOrFail($id);
            $this->appendNote($inquiry, $admin, 'Note: ' . trim($note));
            $inquiry->save();

            return $inquiry;
        });
    }

    public function close(int $id, User $admin, string $reason): ServiceInquiry
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new PipelineException('A reason is required to close a ticket.');
        }

        return DB::transaction(function () use ($id, $admin, $reason) {
            $inquiry = $this->lockOpen($id, 'close it');
            $inquiry->status        = 'closed';
            $inquiry->closed_reason = $reason;
            $this->appendNote($inquiry, $admin, 'Closed. ' . $reason);
            $inquiry->save();

            return $inquiry;
        });
    }

    private function lockOpen(int $id, string $action): ServiceInquiry
    {
        $inquiry = ServiceInquiry::lockForUpdate()->findOrFail($id);
        if (!$inquiry->isOpen()) {
            throw new PipelineException("This ticket is closed, so you cannot {$action}.");
        }

        return $inquiry;
    }

    public function appendNote(ServiceInquiry $inquiry, User $admin, string $text): void
    {
        $who  = trim((string) $admin->first_name) ?: 'Admin';
        $when = Carbon::now(self::TZ)->format('M d, Y h:i A');

        $text = trim((string) preg_replace('/\s+/u', ' ', $text)); // one line per entry, so the log stays readable

        $inquiry->admin_notes = ($inquiry->admin_notes ? $inquiry->admin_notes . "\n" : '') . "{$when} · {$who} · {$text}";
    }
}
