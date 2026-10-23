<?php

namespace App\Services;

use Illuminate\Support\Str;

class MockInsurerService
{
    /**
     * Simulated insurer scenarios.
     * Maps scenario names to behavior.
     */
    public const SCENARIO_SUCCESS = 'SUCCESS';
    public const SCENARIO_TEMPORARY_FAILURE = 'TEMPORARY_FAILURE';
    public const SCENARIO_TIMEOUT_AFTER_ISSUE = 'TIMEOUT_AFTER_ISSUE';
    public const SCENARIO_DELAYED_CALLBACK = 'DELAYED_CALLBACK';
    public const SCENARIO_DUPLICATE_CALLBACK = 'DUPLICATE_CALLBACK';

    /**
     * In-memory store of issued policies for reconciliation lookups.
     * In production, this would be the insurer's database.
     * We use a database table (mock_insurer_policies) for persistence across requests.
     */

    /**
     * Submit a policy to the mock insurer.
     *
     * @param array $policyData The policy request data
     * @param string $scenario The scenario to simulate
     * @return array The insurer response
     */
    public function submit(array $policyData, string $scenario = self::SCENARIO_SUCCESS): array
    {
        return match ($scenario) {
            self::SCENARIO_SUCCESS => $this->handleSuccess($policyData),
            self::SCENARIO_TEMPORARY_FAILURE => $this->handleTemporaryFailure($policyData),
            self::SCENARIO_TIMEOUT_AFTER_ISSUE => $this->handleTimeoutAfterIssue($policyData),
            self::SCENARIO_DELAYED_CALLBACK => $this->handleDelayedCallback($policyData),
            self::SCENARIO_DUPLICATE_CALLBACK => $this->handleSuccess($policyData), // same as success, callback duplication handled separately
            default => $this->handleSuccess($policyData),
        };
    }

    private function handleSuccess(array $policyData): array
    {
        $policyNumber = 'POL-' . strtoupper(Str::random(8));
        $providerReference = 'PROV-' . strtoupper(Str::random(10));

        // Store in mock insurer's internal state for reconciliation
        $this->storeIssuedPolicy($policyData['policy_request_uuid'] ?? '', $policyNumber, $providerReference);

        return [
            'status' => 'ISSUED',
            'policy_number' => $policyNumber,
            'provider_reference' => $providerReference,
            'message' => 'Policy issued successfully',
        ];
    }

    private function handleTemporaryFailure(array $policyData): array
    {
        return [
            'status' => 'FAILED',
            'policy_number' => null,
            'provider_reference' => null,
            'message' => 'Service temporarily unavailable. Please retry.',
            'retriable' => true,
        ];
    }

    private function handleTimeoutAfterIssue(array $policyData): array
    {
        // The insurer internally issues the policy but the response times out
        $policyNumber = 'POL-' . strtoupper(Str::random(8));
        $providerReference = 'PROV-' . strtoupper(Str::random(10));

        // Store internally (policy was issued on insurer's side)
        $this->storeIssuedPolicy($policyData['policy_request_uuid'] ?? '', $policyNumber, $providerReference);

        // But return a timeout to the caller
        return [
            'status' => 'TIMEOUT',
            'policy_number' => null,
            'provider_reference' => null,
            'message' => 'Request timed out',
            'retriable' => true,
        ];
    }

    private function handleDelayedCallback(array $policyData): array
    {
        $providerReference = 'PROV-' . strtoupper(Str::random(10));

        return [
            'status' => 'PENDING',
            'policy_number' => null,
            'provider_reference' => $providerReference,
            'message' => 'Policy is being processed. A callback will be sent.',
        ];
    }

    /**
     * Store an issued policy for reconciliation lookup.
     * Uses cache/file for simplicity in mock.
     */
    private function storeIssuedPolicy(string $requestUuid, string $policyNumber, string $providerReference): void
    {
        $store = $this->loadStore();
        $store[$requestUuid] = [
            'policy_number' => $policyNumber,
            'provider_reference' => $providerReference,
            'issued_at' => now()->toISOString(),
        ];
        $this->saveStore($store);
    }

    /**
     * Reconciliation: check if a policy was issued on the insurer's side.
     */
    public function checkStatus(string $requestUuid): ?array
    {
        $store = $this->loadStore();
        return $store[$requestUuid] ?? null;
    }

    private function getStorePath(): string
    {
        return storage_path('app/mock_insurer_store.json');
    }

    private function loadStore(): array
    {
        $path = $this->getStorePath();
        if (!file_exists($path)) {
            return [];
        }
        return json_decode(file_get_contents($path), true) ?: [];
    }

    private function saveStore(array $store): void
    {
        $dir = dirname($this->getStorePath());
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->getStorePath(), json_encode($store, JSON_PRETTY_PRINT));
    }

    /**
     * Clear the mock store (for testing).
     */
    public function clearStore(): void
    {
        $path = $this->getStorePath();
        if (file_exists($path)) {
            unlink($path);
        }
    }
}
