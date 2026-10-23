<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuanceAttempt extends Model
{
    protected $fillable = [
        'policy_request_id',
        'attempt_number',
        'status',
        'provider_reference',
        'policy_number',
        'request_payload',
        'response_payload',
        'error_message',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
