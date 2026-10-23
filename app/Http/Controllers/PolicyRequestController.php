<?php

namespace App\Http\Controllers;

use App\Models\PolicyRequest;
use App\Services\IssuanceService;
use App\Services\PremiumCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PolicyRequestController extends Controller
{
    public function __construct(
        private PremiumCalculationService $premiumService,
        private IssuanceService $issuanceService,
    ) {}

    /**
     * POST /api/policy-requests
     * Create or safely return existing request (idempotent).
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_request_id' => 'required|string|max:255',
            'agent_id' => 'required|string|max:255',
            'customer_name' => 'required|string|max:255',
            'date_of_birth' => 'required|date|before:today',
            'destination' => ['required', 'string', Rule::in(PremiumCalculationService::getSupportedDestinations())],
            'trip_start' => 'required|date|after_or_equal:today',
            'trip_end' => 'required|date|after_or_equal:trip_start',
            'plan_code' => ['required', 'string', Rule::in(PremiumCalculationService::getSupportedPlans())],
            'sum_insured' => ['required', 'integer', Rule::in(PremiumCalculationService::getSupportedCoverages())],
        ], [
            'trip_end.after_or_equal' => 'The trip end date must be on or after the trip start date.',
            'destination.in' => 'Unsupported destination. Supported values: ' . implode(', ', PremiumCalculationService::getSupportedDestinations()),
            'plan_code.in' => 'Unsupported plan code. Supported values: ' . implode(', ', PremiumCalculationService::getSupportedPlans()),
            'sum_insured.in' => 'Unsupported sum insured. Supported values: ' . implode(', ', array_map('strval', PremiumCalculationService::getSupportedCoverages())),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // Idempotency check: look for existing request with same agent_id + client_request_id
        $existing = PolicyRequest::where('agent_id', $validated['agent_id'])
            ->where('client_request_id', $validated['client_request_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'Policy request already exists',
                'data' => $this->formatPolicyRequest($existing),
            ], 200);
        }

        // Calculate premium
        try {
            $premium = $this->premiumService->calculate(
                $validated['plan_code'],
                \Carbon\Carbon::parse($validated['date_of_birth']),
                \Carbon\Carbon::parse($validated['trip_start']),
                \Carbon\Carbon::parse($validated['trip_end']),
                $validated['destination'],
                (int) $validated['sum_insured'],
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Premium calculation failed',
                'errors' => ['calculation' => [$e->getMessage()]],
            ], 422);
        }

        // Create the request atomically with DB unique constraint as safety net
        try {
            $policyRequest = DB::transaction(function () use ($validated, $premium) {
                $pr = PolicyRequest::create([
                    'uuid' => (string) Str::uuid(),
                    'client_request_id' => $validated['client_request_id'],
                    'agent_id' => $validated['agent_id'],
                    'customer_name' => $validated['customer_name'],
                    'date_of_birth' => $validated['date_of_birth'],
                    'destination' => $validated['destination'],
                    'trip_start' => $validated['trip_start'],
                    'trip_end' => $validated['trip_end'],
                    'plan_code' => $validated['plan_code'],
                    'sum_insured' => $validated['sum_insured'],
                    'premium' => $premium,
                    'status' => 'RECEIVED',
                ]);

                // Record initial state
                $pr->stateHistory()->create([
                    'from_status' => null,
                    'to_status' => 'RECEIVED',
                    'trigger' => 'policy_request_created',
                    'metadata' => json_encode(['premium' => $premium]),
                ]);

                return $pr;
            });

            return response()->json([
                'success' => true,
                'message' => 'Policy request created successfully',
                'data' => $this->formatPolicyRequest($policyRequest),
            ], 201);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Race condition: another request created the same record
            $existing = PolicyRequest::where('agent_id', $validated['agent_id'])
                ->where('client_request_id', $validated['client_request_id'])
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'message' => 'Policy request already exists',
                'data' => $this->formatPolicyRequest($existing),
            ], 200);
        }
    }

    /**
     * GET /api/policy-requests/{id}
     * Retrieve status, premium and references.
     */
    public function show(string $id): JsonResponse
    {
        $policyRequest = PolicyRequest::where('uuid', $id)
            ->orWhere('id', is_numeric($id) ? $id : 0)
            ->first();

        if (!$policyRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Policy request not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatPolicyRequest($policyRequest, true),
        ]);
    }

    /**
     * POST /api/policy-requests/{id}/submit
     * Start or safely resume issuance.
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        $policyRequest = PolicyRequest::where('uuid', $id)
            ->orWhere('id', is_numeric($id) ? $id : 0)
            ->first();

        if (!$policyRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Policy request not found',
            ], 404);
        }

        // If already issued, return success
        if ($policyRequest->status === 'ISSUED') {
            return response()->json([
                'success' => true,
                'message' => 'Policy already issued',
                'data' => $this->formatPolicyRequest($policyRequest),
            ], 200);
        }

        // Get scenario from request (for testing/development only)
        $scenario = $request->input('scenario', 'SUCCESS');

        try {
            $policyRequest = $this->issuanceService->submit($policyRequest, $scenario);

            $statusCode = $policyRequest->status === 'ISSUED' ? 200 : 202;

            return response()->json([
                'success' => true,
                'message' => "Policy request status: {$policyRequest->status}",
                'data' => $this->formatPolicyRequest($policyRequest),
            ], $statusCode);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * GET /api/policy-requests
     * List requests with status filter and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::query();

        // Status filter
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // Agent filter
        if ($request->has('agent_id')) {
            $query->where('agent_id', $request->input('agent_id'));
        }

        $perPage = min($request->input('per_page', 15), 100);
        $paginated = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginated->getCollection()->map(fn ($pr) => $this->formatPolicyRequest($pr)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    private function formatPolicyRequest(PolicyRequest $pr, bool $includeAuditTrail = false): array
    {
        $data = [
            'id' => $pr->uuid,
            'client_request_id' => $pr->client_request_id,
            'agent_id' => $pr->agent_id,
            'customer_name' => $pr->customer_name,
            'date_of_birth' => $pr->date_of_birth->format('Y-m-d'),
            'destination' => $pr->destination,
            'trip_start' => $pr->trip_start->format('Y-m-d'),
            'trip_end' => $pr->trip_end->format('Y-m-d'),
            'plan_code' => $pr->plan_code,
            'sum_insured' => $pr->sum_insured,
            'premium' => $pr->premium,
            'status' => $pr->status,
            'policy_number' => $pr->policy_number,
            'provider_reference' => $pr->provider_reference,
            'attempt_count' => $pr->attempt_count,
            'failure_reason' => $pr->failure_reason,
            'created_at' => $pr->created_at->toISOString(),
            'updated_at' => $pr->updated_at->toISOString(),
        ];

        if ($includeAuditTrail) {
            $data['audit_trail'] = $pr->stateHistory()->orderBy('created_at')->get()->map(fn ($h) => [
                'from_status' => $h->from_status,
                'to_status' => $h->to_status,
                'trigger' => $h->trigger,
                'metadata' => $h->metadata ? json_decode($h->metadata, true) : null,
                'timestamp' => $h->created_at->toISOString(),
            ]);

            $data['issuance_attempts'] = $pr->issuanceAttempts()->orderBy('created_at')->get()->map(fn ($a) => [
                'attempt_number' => $a->attempt_number,
                'status' => $a->status,
                'provider_reference' => $a->provider_reference,
                'policy_number' => $a->policy_number,
                'error_message' => $a->error_message,
                'timestamp' => $a->created_at->toISOString(),
            ]);

            $data['provider_events'] = $pr->providerEvents()->orderBy('created_at')->get()->map(fn ($e) => [
                'event_id' => $e->event_id,
                'event_type' => $e->event_type,
                'provider_reference' => $e->provider_reference,
                'policy_number' => $e->policy_number,
                'processed' => $e->processed,
                'timestamp' => $e->created_at->toISOString(),
            ]);
        }

        return $data;
    }
}
