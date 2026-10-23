<?php

namespace App\Services;

use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use InvalidArgumentException;

class PolicyStateMachine
{
    /**
     * Allowed state transitions.
     * Key = from state, Value = array of allowed 'to' states.
     */
    private const TRANSITIONS = [
        'RECEIVED' => ['VALIDATED', 'FAILED'],
        'VALIDATED' => ['SUBMITTED', 'FAILED'],
        'SUBMITTED' => ['PENDING_CONFIRMATION', 'ISSUED', 'FAILED', 'NEEDS_RECONCILIATION'],
        'PENDING_CONFIRMATION' => ['ISSUED', 'FAILED', 'NEEDS_RECONCILIATION'],
        'NEEDS_RECONCILIATION' => ['ISSUED', 'FAILED', 'SUBMITTED'],
        'FAILED' => ['SUBMITTED'], // Allow retry from FAILED
        'ISSUED' => [], // Terminal state — no transitions allowed
    ];

    /**
     * Transition a policy request to a new state.
     *
     * @throws InvalidArgumentException if the transition is not allowed
     */
    public function transition(PolicyRequest $policyRequest, string $toStatus, string $trigger = '', ?string $metadata = null): PolicyRequest
    {
        $fromStatus = $policyRequest->status;

        if (!$this->canTransition($fromStatus, $toStatus)) {
            throw new InvalidArgumentException(
                "Invalid state transition from {$fromStatus} to {$toStatus}"
            );
        }

        $policyRequest->status = $toStatus;
        $policyRequest->save();

        PolicyStateHistory::create([
            'policy_request_id' => $policyRequest->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'trigger' => $trigger,
            'metadata' => $metadata,
        ]);

        return $policyRequest;
    }

    public function canTransition(string $fromStatus, string $toStatus): bool
    {
        if (!isset(self::TRANSITIONS[$fromStatus])) {
            return false;
        }

        return in_array($toStatus, self::TRANSITIONS[$fromStatus], true);
    }

    public static function getAllStates(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function getTerminalStates(): array
    {
        return ['ISSUED'];
    }
}
