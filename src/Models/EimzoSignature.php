<?php

namespace AsadbekRahimov\EimzoIntegration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $signable_type
 * @property int|null $signable_id
 * @property int|null $user_id
 * @property int|null $certificate_id
 * @property string|null $document_type
 * @property string|null $document_name
 * @property int|null $document_size
 * @property string|null $document_hash
 * @property string|null $pkcs7
 * @property string|null $pkcs7_path
 * @property bool $detached
 * @property string|null $pkcs7_with_timestamp
 * @property Carbon|null $signed_at
 * @property Carbon|null $timestamp_at
 * @property string $verification_status
 * @property array|null $verification_payload
 * @property Carbon|null $verified_at
 * @property array|null $meta
 */
class EimzoSignature extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALID = 'valid';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_ERROR = 'error';

    protected $guarded = [];

    protected $casts = [
        'detached' => 'boolean',
        'signed_at' => 'datetime',
        'timestamp_at' => 'datetime',
        'verified_at' => 'datetime',
        'verification_payload' => 'array',
        'meta' => 'array',
    ];

    public function getTable(): string
    {
        return config('eimzo.tables.signatures', 'eimzo_signatures');
    }

    public function signable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('eimzo.auth.user_model', \App\Models\User::class));
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(EimzoCertificate::class, 'certificate_id');
    }

    public function isValid(): bool
    {
        return $this->verification_status === self::STATUS_VALID;
    }
}
