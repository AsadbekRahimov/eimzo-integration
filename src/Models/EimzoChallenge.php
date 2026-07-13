<?php

namespace AsadbekRahimov\EimzoIntegration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $challenge
 * @property string $purpose
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array|null $meta
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class EimzoChallenge extends Model
{
    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('eimzo.tables.challenges', 'eimzo_challenges');
    }

    public static function issue(
        string $purpose = 'auth',
        ?string $ip = null,
        ?string $userAgent = null,
        ?array $meta = null,
        ?string $challenge = null,
        ?int $ttlSeconds = null
    ): self {
        $ttlSeconds = $ttlSeconds ?? (int) config('eimzo.auth.challenge_ttl', 120);

        return static::create([
            'challenge' => $challenge ?: (string) Str::uuid(),
            'purpose' => $purpose,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'meta' => $meta,
            'expires_at' => now()->addSeconds(max(1, $ttlSeconds)),
        ]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * Atomically claim this challenge for one-time use via a conditional
     * UPDATE. Returns false when a concurrent request already consumed it -
     * callers must treat that as a replay and abort.
     */
    public function markUsed(): bool
    {
        $now = now();
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->whereNull('used_at')
            ->where('expires_at', '>', $now)
            ->update(['used_at' => $now]) === 1;

        if ($claimed) {
            $this->used_at = $now;
            $this->syncOriginalAttribute('used_at');
        }

        return $claimed;
    }
}
