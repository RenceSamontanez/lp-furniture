<?php

namespace App\Support\Luma;

use Illuminate\Support\Facades\Auth;

/**
 * Luma's short memory for one conversation: the last topic, the current flow and step, what she asked and is waiting
 * for an answer to, how many misses in a row. Kept in the session and thrown away after 30 minutes without a message.
 *
 * Why it is safe: the customer and admin guards share ONE session, so the state is stamped with the id of the
 * signed-in customer (0 for a guest) and is discarded the moment that id changes. Logging out clears it (both logouts).
 * It never holds a GCash number or a name: those only travel in the encrypted token of the refund form.
 */
final class ChatState
{
    public const KEY = 'luma.state';
    public const TTL = 1800;   // seconds of silence before the conversation is forgotten

    private function __construct(private array $d)
    {
    }

    public static function load(): self
    {
        $uid = (int) (Auth::id() ?? 0);
        $raw = session()->get(self::KEY);

        if (!is_array($raw) || ($raw['u'] ?? null) !== $uid || (now()->getTimestamp() - (int) ($raw['at'] ?? 0)) > self::TTL) {
            $raw = ['u' => $uid, 'at' => now()->getTimestamp(), 'turn' => 0];
        }

        return new self($raw);
    }

    public static function forget(): void
    {
        session()->forget(self::KEY);
    }

    public function save(): void
    {
        $this->d['at'] = now()->getTimestamp();
        session()->put(self::KEY, $this->d);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->d[$key] ?? $default;
    }

    public function set(string $key, mixed $value): self
    {
        if ($value === null) {
            unset($this->d[$key]);
        } else {
            $this->d[$key] = $value;
        }

        return $this;
    }

    /** What the conversation is about: 'os' (Other Services), 'furniture' (orders), or null when unknown. */
    public function domain(): ?string
    {
        return $this->get('domain');
    }

    public function inFlow(): bool
    {
        return is_array($this->get('flow'));
    }

    public function turn(): int
    {
        return (int) $this->get('turn', 0);
    }

    public function nextTurn(): int
    {
        $this->d['turn'] = $this->turn() + 1;

        return $this->d['turn'];
    }
}
