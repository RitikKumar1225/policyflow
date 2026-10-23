<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use App\Services\MockInsurerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PolicyRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new MockInsurerService())->clearStore();
    }

    protected function tearDown(): void
    {
        (new MockInsurerService())->clearStore();
        parent::tearDown();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ], $overrides);
    }

    // ─────────────────────────────────────────────────────────────────
    // 3.1 Policy Request Intake
    // ─────────────────────────────────────────────────────────────────

    public function test_create_policy_request_successfully(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
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
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertDatabaseCount('policy_requests', 1);
    }

    public function test_returns_validation_errors_for_missing_fields(): void
    {
        $response = $this->postJson('/api/policy-requests', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'client_request_id',
                'agent_id',
                'customer_name',
                'date_of_birth',
                'destination',
                'trip_start',
                'trip_end',
                'plan_code',
                'sum_insured',
            ]);
    }

    public function test_rejects_invalid_trip_dates(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'trip_start' => '2026-11-14',
            'trip_end' => '2026-11-10', // before trip_start
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);
    }

    public function test_rejects_unsupported_plan_code(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'plan_code' => 'GOLD',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code']);
    }

    public function test_rejects_unsupported_sum_insured(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'sum_insured' => 200000,
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sum_insured']);
    }

    public function test_rejects_unsupported_destination(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'destination' => 'MARS',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination']);
    }

    // ─────────────────────────────────────────────────────────────────
    // 3.2 Premium Calculation
    // ─────────────────────────────────────────────────────────────────

    public function test_premium_calculation_example_from_spec(): void
    {
        // From spec: PLUS, 5 days, age 28, INTERNATIONAL, 300000
        // 20 × 5 × 1.00 × 1.80 × 1.25 = ₹225.00
        $response = $this->postJson('/api/policy-requests', $this->validPayload());

        $response->assertStatus(201);
        $this->assertEquals('225.00', $response->json('data.premium'));
    }

    public function test_premium_basic_domestic_young(): void
    {
        // BASIC, 3 days, age 10 (minor), DOMESTIC, 100000
        // 12 × 3 × 1.10 × 1.00 × 1.00 = 39.60
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'client_request_id' => 'TEST-BASIC-1',
            'plan_code' => 'BASIC',
            'date_of_birth' => '2016-01-01', // ~10 years old at trip start
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-12', // 3 days
            'sum_insured' => 100000,
        ]));

        $response->assertStatus(201);
        $this->assertEquals('39.60', $response->json('data.premium'));
    }

    public function test_premium_premium_plan_senior(): void
    {
        // PREMIUM, 7 days, age 65 (senior), INTERNATIONAL, 500000
        // 30 × 7 × 1.50 × 1.80 × 1.50 = 850.50
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'client_request_id' => 'TEST-PREM-1',
            'plan_code' => 'PREMIUM',
            'date_of_birth' => '1961-01-01', // ~65 years old at trip start
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-16', // 7 days
            'sum_insured' => 500000,
        ]));

        $response->assertStatus(201);
        $this->assertEquals('850.50', $response->json('data.premium'));
    }

    public function test_premium_elderly_multiplier(): void
    {
        // BASIC, 1 day, age 75 (71+), DOMESTIC, 100000
        // 12 × 1 × 2.00 × 1.00 × 1.00 = 24.00
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'client_request_id' => 'TEST-ELDER-1',
            'plan_code' => 'BASIC',
            'date_of_birth' => '1950-01-01', // ~76 years old
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-10', // 1 day
            'sum_insured' => 100000,
        ]));

        $response->assertStatus(201);
        $this->assertEquals('24.00', $response->json('data.premium'));
    }

    // ─────────────────────────────────────────────────────────────────
    // 3.3 Issuance Workflow
    // ─────────────────────────────────────────────────────────────────

    public function test_successful_issuance_produces_policy_number(): void
    {
        // Create the request
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $uuid = $createResponse->json('data.id');

        // Submit for issuance
        $submitResponse = $this->postJson("/api/policy-requests/{$uuid}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $submitResponse->assertStatus(200);
        $this->assertEquals('ISSUED', $submitResponse->json('data.status'));
        $this->assertNotNull($submitResponse->json('data.policy_number'));

        // Verify only one policy was issued
        $this->assertDatabaseCount('policy_requests', 1);
        $this->assertDatabaseHas('policy_requests', [
            'uuid' => $uuid,
            'status' => 'ISSUED',
        ]);
    }

    public function test_retrieve_policy_request_with_audit_trail(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $uuid = $createResponse->json('data.id');

        // Submit
        $this->postJson("/api/policy-requests/{$uuid}/submit", ['scenario' => 'SUCCESS']);

        // Retrieve
        $showResponse = $this->getJson("/api/policy-requests/{$uuid}");

        $showResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'status',
                    'policy_number',
                    'audit_trail',
                    'issuance_attempts',
                ],
            ]);

        $this->assertGreaterThanOrEqual(1, count($showResponse->json('data.audit_trail')));
    }

    public function test_list_policy_requests_with_status_filter(): void
    {
        // Create two requests
        $this->postJson('/api/policy-requests', $this->validPayload());
        $this->postJson('/api/policy-requests', $this->validPayload([
            'client_request_id' => 'AGT-2026-0002',
        ]));

        // List all
        $allResponse = $this->getJson('/api/policy-requests');
        $allResponse->assertStatus(200);
        $this->assertEquals(2, $allResponse->json('meta.total'));

        // Filter by status
        $filteredResponse = $this->getJson('/api/policy-requests?status=RECEIVED');
        $filteredResponse->assertStatus(200);
        $this->assertEquals(2, $filteredResponse->json('meta.total'));
    }

    public function test_submit_returns_404_for_unknown_request(): void
    {
        $response = $this->postJson('/api/policy-requests/nonexistent-uuid/submit');

        $response->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────────
    // 3.4 Reliability Case 1: Duplicate Intake
    // ─────────────────────────────────────────────────────────────────

    public function test_duplicate_intake_returns_existing_request(): void
    {
        $payload = $this->validPayload();

        // First submission
        $first = $this->postJson('/api/policy-requests', $payload);
        $first->assertStatus(201);

        // Second submission with same agent_id + client_request_id
        $second = $this->postJson('/api/policy-requests', $payload);
        $second->assertStatus(200); // 200 for existing, not 201

        // Verify same UUID returned
        $this->assertEquals(
            $first->json('data.id'),
            $second->json('data.id'),
        );

        // Verify only one record in database
        $this->assertDatabaseCount('policy_requests', 1);
    }

    public function test_duplicate_intake_different_agent_creates_new(): void
    {
        $payload = $this->validPayload();

        $first = $this->postJson('/api/policy-requests', $payload);
        $first->assertStatus(201);

        // Same client_request_id but different agent — should create a new request
        $second = $this->postJson('/api/policy-requests', array_merge($payload, ['agent_id' => 'AGENT-99']));
        $second->assertStatus(201);

        $this->assertDatabaseCount('policy_requests', 2);
    }

    public function test_duplicate_intake_enforced_by_database_constraint(): void
    {
        // This test verifies the database unique constraint works
        // even if application-level check is bypassed.
        $payload = $this->validPayload();

        $this->postJson('/api/policy-requests', $payload)->assertStatus(201);

        // Try to insert directly, bypassing application check
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        PolicyRequest::create([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => $payload['client_request_id'],
            'agent_id' => $payload['agent_id'],
            'customer_name' => $payload['customer_name'],
            'date_of_birth' => $payload['date_of_birth'],
            'destination' => $payload['destination'],
            'trip_start' => $payload['trip_start'],
            'trip_end' => $payload['trip_end'],
            'plan_code' => $payload['plan_code'],
            'sum_insured' => $payload['sum_insured'],
            'status' => 'RECEIVED',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 3.4 Reliability Case 3: Duplicate Callback
    // ─────────────────────────────────────────────────────────────────

    public function test_duplicate_callback_processed_only_once(): void
    {
        // Create and submit a request with delayed callback scenario
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $uuid = $createResponse->json('data.id');

        $this->postJson("/api/policy-requests/{$uuid}/submit", [
            'scenario' => 'DELAYED_CALLBACK',
        ]);

        // Simulate first callback
        $callbackPayload = [
            'event_id' => 'EVT-001',
            'policy_request_uuid' => $uuid,
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => 'PROV-ABC123',
            'policy_number' => 'POL-XYZ789',
            'message' => 'Policy issued',
        ];

        $first = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $first->assertStatus(200);
        $this->assertEquals('ISSUED', $first->json('data.status'));
        $this->assertEquals('POL-XYZ789', $first->json('data.policy_number'));

        // Simulate duplicate callback with the same event_id
        $second = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $second->assertStatus(200);
        $this->assertEquals('ISSUED', $second->json('data.status'));

        // Verify event was recorded only once (event_id is unique)
        $this->assertEquals(1, ProviderEvent::where('event_id', 'EVT-001')->count());

        // Verify policy number wasn't overwritten
        $policyRequest = PolicyRequest::where('uuid', $uuid)->first();
        $this->assertEquals('POL-XYZ789', $policyRequest->policy_number);
    }

    public function test_duplicate_callback_cannot_overwrite_issued_state(): void
    {
        // Create and submit successfully
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $uuid = $createResponse->json('data.id');

        $this->postJson("/api/policy-requests/{$uuid}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        // Verify it's ISSUED
        $policyRequest = PolicyRequest::where('uuid', $uuid)->first();
        $this->assertEquals('ISSUED', $policyRequest->status);
        $originalPolicyNumber = $policyRequest->policy_number;

        // Try to send a callback with a different policy number
        $callbackPayload = [
            'event_id' => 'EVT-ROGUE',
            'policy_request_uuid' => $uuid,
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => 'PROV-ROGUE',
            'policy_number' => 'POL-DIFFERENT',
            'message' => 'Rogue callback',
        ];

        $response = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $response->assertStatus(200);

        // Verify original policy number was not overwritten
        $policyRequest->refresh();
        $this->assertEquals($originalPolicyNumber, $policyRequest->policy_number);
        $this->assertEquals('ISSUED', $policyRequest->status);
    }

    // ─────────────────────────────────────────────────────────────────
    // Additional Reliability Tests
    // ─────────────────────────────────────────────────────────────────

    public function test_submitted_request_cannot_be_resubmitted_blindly(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $uuid = $createResponse->json('data.id');

        // Submit successfully
        $this->postJson("/api/policy-requests/{$uuid}/submit", ['scenario' => 'SUCCESS']);

        // Try to submit again — should return already issued
        $response = $this->postJson("/api/policy-requests/{$uuid}/submit", ['scenario' => 'SUCCESS']);
        $response->assertStatus(200);
        $this->assertEquals('ISSUED', $response->json('data.status'));

        // Verify only one policy number exists
        $this->assertDatabaseCount('policy_requests', 1);
    }

    public function test_temporary_failure_allows_retry(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $uuid = $createResponse->json('data.id');

        // Submit with temporary failure
        $failResponse = $this->postJson("/api/policy-requests/{$uuid}/submit", [
            'scenario' => 'TEMPORARY_FAILURE',
        ]);

        $this->assertEquals('FAILED', $failResponse->json('data.status'));

        // Retry with success
        $retryResponse = $this->postJson("/api/policy-requests/{$uuid}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $this->assertEquals('ISSUED', $retryResponse->json('data.status'));
        $this->assertNotNull($retryResponse->json('data.policy_number'));
    }

    public function test_timeout_scenario_marks_needs_reconciliation(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $uuid = $createResponse->json('data.id');

        // Submit with timeout after issue
        $response = $this->postJson("/api/policy-requests/{$uuid}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $this->assertEquals('NEEDS_RECONCILIATION', $response->json('data.status'));
    }

    public function test_timeout_after_success_reconciles_without_duplicate_issuance(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $uuid = $createResponse->json('data.id');

        // Submit with timeout after issue: insurer creates policy internally but client call times out
        $timeoutResponse = $this->postJson("/api/policy-requests/{$uuid}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $this->assertEquals('NEEDS_RECONCILIATION', $timeoutResponse->json('data.status'));
        $this->assertNull($timeoutResponse->json('data.policy_number'));

        // Client or automated worker safely resumes issuance
        // Engine checks provider status first and reconciles without issuing duplicate policy
        $reconciledResponse = $this->postJson("/api/policy-requests/{$uuid}/submit");
        $reconciledResponse->assertStatus(200);
        $this->assertEquals('ISSUED', $reconciledResponse->json('data.status'));
        $this->assertNotNull($reconciledResponse->json('data.policy_number'));

        // Reconciled policy number must match the provider's recorded policy
        $providerStatus = (new MockInsurerService())->checkStatus($uuid);
        $this->assertEquals($providerStatus['policy_number'], $reconciledResponse->json('data.policy_number'));

        // Exactly one policy in database
        $this->assertDatabaseCount('policy_requests', 1);
    }

    public function test_reconciliation_command_detects_and_resolves_timeout(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $uuid = $createResponse->json('data.id');

        // Submit with timeout after issue
        $this->postJson("/api/policy-requests/{$uuid}/submit", [
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ]);

        $this->assertDatabaseHas('policy_requests', [
            'uuid' => $uuid,
            'status' => 'NEEDS_RECONCILIATION',
        ]);

        // Run the artisan reconciliation command
        $this->artisan('policy:reconcile')
            ->expectsOutputToContain('Found 1 request(s) to reconcile.')
            ->expectsOutputToContain('Successfully reconciled')
            ->assertExitCode(0);

        // Verify request is now ISSUED
        $this->assertDatabaseHas('policy_requests', [
            'uuid' => $uuid,
            'status' => 'ISSUED',
        ]);
    }

    public function test_retry_after_temporary_failure_preserves_original_premium(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $uuid = $createResponse->json('data.id');
        $originalPremium = $createResponse->json('data.premium');

        // Attempt 1: temporary failure from provider
        $failResponse = $this->postJson("/api/policy-requests/{$uuid}/submit", [
            'scenario' => 'TEMPORARY_FAILURE',
        ]);
        $this->assertEquals('FAILED', $failResponse->json('data.status'));

        // Attempt 2: retry succeeds
        $retryResponse = $this->postJson("/api/policy-requests/{$uuid}/submit", [
            'scenario' => 'SUCCESS',
        ]);
        $this->assertEquals('ISSUED', $retryResponse->json('data.status'));
        $this->assertEquals($originalPremium, $retryResponse->json('data.premium'));
        $this->assertEquals(2, $retryResponse->json('data.attempt_count'));

        // Verify attempt records
        $this->assertDatabaseCount('issuance_attempts', 2);
    }

    public function test_invalid_state_transition_rejected(): void
    {
        // Create a request
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $uuid = $createResponse->json('data.id');

        // Submit and issue successfully
        $this->postJson("/api/policy-requests/{$uuid}/submit", ['scenario' => 'SUCCESS']);

        // Verify ISSUED state — state machine should prevent ISSUED -> SUBMITTED
        $policyRequest = PolicyRequest::where('uuid', $uuid)->first();
        $this->assertEquals('ISSUED', $policyRequest->status);

        // Try to send a failure callback — ISSUED state should not regress
        $callbackPayload = [
            'event_id' => 'EVT-FAIL',
            'policy_request_uuid' => $uuid,
            'event_type' => 'POLICY_FAILED',
            'message' => 'Attempting to fail an issued policy',
        ];

        $response = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $response->assertStatus(200);

        // Should still be ISSUED
        $policyRequest->refresh();
        $this->assertEquals('ISSUED', $policyRequest->status);
    }

    // ─────────────────────────────────────────────────────────────────
    // State History / Audit Trail
    // ─────────────────────────────────────────────────────────────────

    public function test_state_transitions_are_recorded(): void
    {
        $createResponse = $this->postJson('/api/policy-requests', $this->validPayload());
        $uuid = $createResponse->json('data.id');

        $this->postJson("/api/policy-requests/{$uuid}/submit", ['scenario' => 'SUCCESS']);

        $showResponse = $this->getJson("/api/policy-requests/{$uuid}");

        $auditTrail = $showResponse->json('data.audit_trail');
        $this->assertNotEmpty($auditTrail);

        // Should have: RECEIVED -> VALIDATED -> SUBMITTED -> ISSUED
        $states = array_column($auditTrail, 'to_status');
        $this->assertContains('RECEIVED', $states);
        $this->assertContains('VALIDATED', $states);
        $this->assertContains('SUBMITTED', $states);
        $this->assertContains('ISSUED', $states);
    }

    // ─────────────────────────────────────────────────────────────────
    // Edge Cases
    // ─────────────────────────────────────────────────────────────────

    public function test_single_day_trip(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'client_request_id' => 'TEST-SINGLE-DAY',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-10',
            'plan_code' => 'BASIC',
            'destination' => 'DOMESTIC',
            'sum_insured' => 100000,
            'date_of_birth' => '1998-04-10',
        ]));

        $response->assertStatus(201);
        // 12 × 1 × 1.00 × 1.00 × 1.00 = 12.00
        $this->assertEquals('12.00', $response->json('data.premium'));
    }
}
