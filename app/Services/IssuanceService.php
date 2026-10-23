<?php

namespace App\Services;

use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IssuanceService
{
    public function __construct(
        private PolicyStateMachine $stateMachine,
        private MockInsurerService $mockInsurer,
        private PremiumCalculationService $premiumService,
    ) {}

    /**
     * Submit a policy request to the insurer.
     * Handles idempotency: if already ISSUED, returns current state.
     * If SUBMITTED/PENDING, does not re-submit.
     */
    public function submit(PolicyRequest $policyRequest, string $scenario = 'SUCCESS'): PolicyRequest
    {
        // Guard: if already issued, return immediately
        if ($policyRequest->status === 'ISSUED') {
            return $policyRequest;
        }

        // Guard: if currently submitted, pending, or needs reconciliation, check with provider first
        if (in_array($policyRequest->status, ['SUBMITTED', 'PENDING_CONFIRMATION', 'NEEDS_RECONCILIATION'])) {
            $policyRequest = $this->reconcileIfNeeded($policyRequest);
            if ($policyRequest->status === 'ISSUED') {
                return $policyRequest;
            }
        }

        // Validate and calculate premium if not yet done
        if ($policyRequest->status === 'RECEIVED') {
            $this->validateAndPrice($policyRequest);
        }

        // Ensure we're in VALIDATED or FAILED (retriable) state
        if (!in_array($policyRequest->status, ['VALIDATED', 'FAILED', 'NEEDS_RECONCILIATION'])) {
            throw new \RuntimeException(
                "Cannot submit policy request in state: {$policyRequest->status}"
            );
        }

        return $this->performSubmission($policyRequest, $scenario);
    }

    /**
     * Validate fields and calculate premium.
     */
    public function validateAndPrice(PolicyRequest $policyRequest): PolicyRequest
    {
        $premium = $this->premiumService->calculate(
            $policyRequest->plan_code,
            $policyRequest->date_of_birth,
            $policyRequest->trip_start,
            $policyRequest->trip_end,
            $policyRequest->destination,
            $policyRequest->sum_insured,
        );

        $policyRequest->premium = $premium;
        $policyRequest->save();

        $this->stateMachine->transition($policyRequest, 'VALIDATED', 'premium_calculated', json_encode(['premium' => $premium]));

        return $policyRequest;
    }

    private function performSubmission(PolicyRequest $policyRequest, string $scenario): PolicyRequest
    {
        // Transition to SUBMITTED
        $this->stateMachine->transition($policyRequest, 'SUBMITTED', 'insurer_submission');

        $policyRequest->increment('attempt_count');
        $attemptNumber = $policyRequest->attempt_count;

        $requestPayload = [
            'policy_request_uuid' => $policyRequest->uuid,
            'customer_name' => $policyRequest->customer_name,
            'date_of_birth' => $policyRequest->date_of_birth->format('Y-m-d'),
            'destination' => $policyRequest->destination,
            'trip_start' => $policyRequest->trip_start->format('Y-m-d'),
            'trip_end' => $policyRequest->trip_end->format('Y-m-d'),
            'plan_code' => $policyRequest->plan_code,
            'sum_insured' => $policyRequest->sum_insured,
            'premium' => $policyRequest->premium,
        ];

        // Call mock insurer
        $response = $this->mockInsurer->submit($requestPayload, $scenario);

        // Record the attempt
        $attempt = IssuanceAttempt::create([
            'policy_request_id' => $policyRequest->id,
            'attempt_number' => $attemptNumber,
            'status' => $response['status'],
            'provider_reference' => $response['provider_reference'] ?? null,
            'policy_number' => $response['policy_number'] ?? null,
            'request_payload' => json_encode($requestPayload),
            'response_payload' => json_encode($response),
            'error_message' => $response['status'] !== 'ISSUED' ? ($response['message'] ?? null) : null,
        ]);

        // Update policy request with last response
        $policyRequest->last_provider_response = json_encode($response);
        $policyRequest->save();

        // Handle response
        return $this->handleProviderResponse($policyRequest, $response);
    }

    private function handleProviderResponse(PolicyRequest $policyRequest, array $response): PolicyRequest
    {
        return match ($response['status']) {
            'ISSUED' => $this->markIssued($policyRequest, $response),
            'PENDING' => $this->markPending($policyRequest, $response),
            'TIMEOUT' => $this->markNeedsReconciliation($policyRequest, $response),
            'FAILED' => $this->markFailed($policyRequest, $response),
            default => $this->markFailed($policyRequest, $response),
        };
    }

    private function markIssued(PolicyRequest $policyRequest, array $response): PolicyRequest
    {
        return DB::transaction(function () use ($policyRequest, $response) {
            // Re-fetch with lock to prevent race conditions
            $policyRequest = PolicyRequest::lockForUpdate()->find($policyRequest->id);

            // Double-check: if already ISSUED, don't issue again
            if ($policyRequest->status === 'ISSUED') {
                return $policyRequest;
            }

            $policyRequest->policy_number = $response['policy_number'];
            $policyRequest->provider_reference = $response['provider_reference'];
            $policyRequest->save();

            $this->stateMachine->transition($policyRequest, 'ISSUED', 'provider_confirmation', json_encode($response));

            return $policyRequest;
        });
    }

    private function markPending(PolicyRequest $policyRequest, array $response): PolicyRequest
    {
        $policyRequest->provider_reference = $response['provider_reference'] ?? $policyRequest->provider_reference;
        $policyRequest->save();

        $this->stateMachine->transition($policyRequest, 'PENDING_CONFIRMATION', 'awaiting_callback', json_encode($response));

        return $policyRequest;
    }

    private function markNeedsReconciliation(PolicyRequest $policyRequest, array $response): PolicyRequest
    {
        $policyRequest->failure_reason = $response['message'] ?? 'Timeout - needs reconciliation';
        $policyRequest->save();

        $this->stateMachine->transition($policyRequest, 'NEEDS_RECONCILIATION', 'timeout_response', json_encode($response));

        return $policyRequest;
    }

    private function markFailed(PolicyRequest $policyRequest, array $response): PolicyRequest
    {
        $policyRequest->failure_reason = $response['message'] ?? 'Unknown failure';
        $policyRequest->save();

        $this->stateMachine->transition($policyRequest, 'FAILED', 'provider_failure', json_encode($response));

        return $policyRequest;
    }

    /**
     * Reconcile with provider: check if they already issued the policy.
     */
    public function reconcileIfNeeded(PolicyRequest $policyRequest): PolicyRequest
    {
        $providerStatus = $this->mockInsurer->checkStatus($policyRequest->uuid);

        if ($providerStatus !== null) {
            // Provider has issued the policy, mark as ISSUED
            return $this->markIssued($policyRequest, [
                'status' => 'ISSUED',
                'policy_number' => $providerStatus['policy_number'],
                'provider_reference' => $providerStatus['provider_reference'],
                'message' => 'Reconciled from provider status check',
            ]);
        }

        return $policyRequest;
    }

    /**
     * Process a provider callback event idempotently.
     */
    public function processCallback(array $eventData): PolicyRequest
    {
        $eventId = $eventData['event_id'];
        $policyRequestUuid = $eventData['policy_request_uuid'];

        $policyRequest = PolicyRequest::where('uuid', $policyRequestUuid)->firstOrFail();

        // Check if event was already processed (idempotency)
        return DB::transaction(function () use ($eventId, $policyRequest, $eventData) {
            // Try to insert the event — unique constraint on event_id prevents duplicates
            $existingEvent = ProviderEvent::where('event_id', $eventId)->first();

            if ($existingEvent && $existingEvent->processed) {
                // Already processed — return current state
                Log::info("Duplicate callback ignored", ['event_id' => $eventId]);
                return $policyRequest;
            }

            // Create or get the event record
            $event = ProviderEvent::firstOrCreate(
                ['event_id' => $eventId],
                [
                    'policy_request_id' => $policyRequest->id,
                    'event_type' => $eventData['event_type'],
                    'provider_reference' => $eventData['provider_reference'] ?? null,
                    'policy_number' => $eventData['policy_number'] ?? null,
                    'payload' => json_encode($eventData),
                    'processed' => false,
                ]
            );

            // If this event was just retrieved (already existed but not processed), skip if already processed
            if ($event->processed) {
                return $policyRequest;
            }

            // If already ISSUED, don't process further
            if ($policyRequest->status === 'ISSUED') {
                $event->processed = true;
                $event->save();
                return $policyRequest;
            }

            // Process the event based on type
            if ($eventData['event_type'] === 'POLICY_ISSUED') {
                $policyRequest = PolicyRequest::lockForUpdate()->find($policyRequest->id);
                
                // Double check after lock
                if ($policyRequest->status === 'ISSUED') {
                    $event->processed = true;
                    $event->save();
                    return $policyRequest;
                }

                $policyRequest->policy_number = $eventData['policy_number'];
                $policyRequest->provider_reference = $eventData['provider_reference'] ?? $policyRequest->provider_reference;
                $policyRequest->save();

                $this->stateMachine->transition(
                    $policyRequest,
                    'ISSUED',
                    'provider_callback',
                    json_encode($eventData)
                );
            } elseif ($eventData['event_type'] === 'POLICY_FAILED') {
                if (!in_array($policyRequest->status, ['ISSUED'])) {
                    $policyRequest->failure_reason = $eventData['message'] ?? 'Failed via callback';
                    $policyRequest->save();

                    $this->stateMachine->transition(
                        $policyRequest,
                        'FAILED',
                        'provider_callback_failure',
                        json_encode($eventData)
                    );
                }
            }

            $event->processed = true;
            $event->save();

            return $policyRequest->fresh();
        });
    }
}
