<?php

namespace App\Http\Controllers;

use App\Services\IssuanceService;
use App\Services\MockInsurerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MockInsurerController extends Controller
{
    public function __construct(
        private IssuanceService $issuanceService,
        private MockInsurerService $mockInsurer,
    ) {}

    /**
     * POST /api/mock-insurer/callback
     * Receive a simulated provider event (callback).
     */
    public function callback(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event_id' => 'required|string|max:255',
            'policy_request_uuid' => 'required|string',
            'event_type' => 'required|string|in:POLICY_ISSUED,POLICY_FAILED',
            'provider_reference' => 'nullable|string|max:255',
            'policy_number' => 'required_if:event_type,POLICY_ISSUED|nullable|string|max:255',
            'message' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $policyRequest = $this->issuanceService->processCallback($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Callback processed',
                'data' => [
                    'policy_request_id' => $policyRequest->uuid,
                    'status' => $policyRequest->status,
                    'policy_number' => $policyRequest->policy_number,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Policy request not found',
            ], 404);
        }
    }

    /**
     * GET /api/mock-insurer/status/{uuid}
     * Check the insurer's internal status for a request (reconciliation endpoint).
     */
    public function status(string $uuid): JsonResponse
    {
        $result = $this->mockInsurer->checkStatus($uuid);

        if ($result === null) {
            return response()->json([
                'success' => false,
                'message' => 'No policy found for this request',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
