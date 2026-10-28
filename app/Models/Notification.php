<?php
namespace App\Models;

use App\Support\NotificationKind;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class Notification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'title',
        'message',
        'type',
        'is_read',
        'url',
    ];

    protected $casts = [
        'is_read'    => 'boolean',
        'created_at' => 'datetime',
    ];

    // Belongs to a user
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scope — unread notifications only
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /** @param array{unread: bool, from: ?string, to: ?string} $f from filtersFrom() */
    public function scopeFiltered(Builder $query, array $f): Builder
    {
        if ($f['unread']) {
            $query->where('is_read', false);
        }
        if ($f['from']) {
            $query->where('created_at', '>=', Carbon::createFromFormat('!Y-m-d', $f['from'])->startOfDay());
        }
        if ($f['to']) {
            $query->where('created_at', '<=', Carbon::createFromFormat('!Y-m-d', $f['to'])->endOfDay());
        }

        return $query;
    }

    /** Reads ?unread=1&from=YYYY-MM-DD&to=YYYY-MM-DD; a date that is not a real Y-m-d date is ignored. */
    public static function filtersFrom(Request $request): array
    {
        $date = function ($v): ?string {
            if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return null;
            }
            try {
                $d = Carbon::createFromFormat('!Y-m-d', $v);
            } catch (\Throwable) {
                return null;
            }

            return ($d && $d->format('Y-m-d') === $v) ? $v : null;
        };

        return [
            'unread' => in_array((string) $request->query('unread'), ['1', 'true', 'on'], true),
            'from'   => $date($request->query('from')),
            'to'     => $date($request->query('to')),
        ];
    }

    // Mark as read
    public function markAsRead(): void
    {
        $this->update(['is_read' => true]);
    }

    /** order | service | account (see NotificationKind) */
    public function kind(): string
    {
        return NotificationKind::for($this);
    }

    public function hasDetails(): bool
    {
        return NotificationKind::hasDetails($this);
    }

    public function detailsUrl(): ?string
    {
        return NotificationKind::detailsUrl($this);
    }

    /** "8 hours ago" for the last week, then "Oct 12" (with the year when it is not this year). */
    public function timeLabel(): string
    {
        $at = $this->created_at;
        if ($at->greaterThan(now()->subDays(7))) {
            return $at->diffForHumans();
        }

        return $at->format($at->isCurrentYear() ? 'M j' : 'M j, Y');
    }
}
