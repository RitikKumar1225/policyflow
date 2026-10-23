<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PolicyRequest extends Model
{
    protected $fillable = [
        'uuid',
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'premium',
        'status',
        'policy_number',
        'provider_reference',
        'last_provider_response',
        'attempt_count',
        'failure_reason',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'trip_start' => 'date',
        'trip_end' => 'date',
        'sum_insured' => 'integer',
        'premium' => 'decimal:2',
        'attempt_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (PolicyRequest $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function issuanceAttempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class);
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class);
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
