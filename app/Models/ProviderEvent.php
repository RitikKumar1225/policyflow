<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderEvent extends Model
{
    protected $fillable = [
        'event_id',
        'policy_request_id',
        'event_type',
        'provider_reference',
        'policy_number',
        'payload',
        'processed',
    ];

    protected $casts = [
        'processed' => 'boolean',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
