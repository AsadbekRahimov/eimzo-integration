<?php

namespace AsadbekRahimov\EimzoIntegration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $serial_number
 * @property string|null $cn
 * @property string|null $tin
 * @property string|null $pinfl
 * @property string|null $uid
 * @property string|null $o
 * @property string|null $t
 * @property string|null $country
 * @property string|null $email
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property string|null $subject_dn
 * @property string|null $issuer_dn
 * @property string|null $certificate_pem
 * @property array|null $last_verify_payload
 * @property Carbon|null $last_verified_at
 */
class EimzoCertificate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'last_verified_at' => 'datetime',
        'last_verify_payload' => 'array',
    ];

    public function getTable(): string
    {
        return config('eimzo.tables.certificates', 'eimzo_certificates');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('eimzo.auth.user_model', \App\Models\User::class));
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(EimzoSignature::class, 'certificate_id');
    }

    public function isExpired(): bool
    {
        return $this->valid_to !== null && $this->valid_to->isPast();
    }

    public function isNotYetValid(): bool
    {
        return $this->valid_from !== null && $this->valid_from->isFuture();
    }

    public function isCurrentlyValid(): bool
    {
        return ! $this->isExpired() && ! $this->isNotYetValid();
    }

    /**
     * Persist or refresh a certificate row from a parsed signer info array.
     * Matches on serial_number; fields with null values from $info don't overwrite.
     */
    public static function upsertFromSigner(array $info, ?int $userId = null): self
    {
        $serial = (string) ($info['serial_number'] ?? '');
        if ($serial === '') {
            throw new \InvalidArgumentException('serial_number is required to upsert a certificate');
        }

        $cert = static::firstOrNew(['serial_number' => $serial]);
        $fields = ['cn', 'tin', 'pinfl', 'uid', 'o', 't', 'country', 'email',
            'valid_from', 'valid_to', 'subject_dn', 'issuer_dn', 'certificate_pem'];
        foreach ($fields as $f) {
            if (array_key_exists($f, $info) && $info[$f] !== null) {
                $cert->{$f} = $info[$f];
            }
        }
        if ($userId !== null) {
            $cert->user_id = $userId;
        }
        $cert->save();

        return $cert;
    }
}
