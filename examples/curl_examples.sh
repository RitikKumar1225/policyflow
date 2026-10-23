#!/bin/bash
# PolicyFlow API - Example curl commands
# Base URL (adjust if running on different port)
BASE_URL="http://localhost:8000/api"

echo "========================================"
echo "PolicyFlow API - Example Requests"
echo "========================================"

# 1. Create a new policy request
echo ""
echo "=== 1. Create Policy Request ==="
curl -s -X POST "${BASE_URL}/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }' | python3 -m json.tool

# 2. Duplicate request (should return 200 with existing)
echo ""
echo "=== 2. Duplicate Request (idempotent) ==="
curl -s -X POST "${BASE_URL}/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }' | python3 -m json.tool

# 3. Validation error (trip_end before trip_start)
echo ""
echo "=== 3. Validation Error ==="
curl -s -X POST "${BASE_URL}/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0002",
    "agent_id": "AGENT-17",
    "customer_name": "Invalid Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-14",
    "trip_end": "2026-11-10",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }' | python3 -m json.tool

# 4. List all policy requests
echo ""
echo "=== 4. List Policy Requests ==="
curl -s -X GET "${BASE_URL}/policy-requests" \
  -H "Accept: application/json" | python3 -m json.tool

# 5. Get specific policy request (replace UUID with actual value from step 1)
echo ""
echo "=== 5. Get Policy Request (replace UUID) ==="
echo "curl -s -X GET '${BASE_URL}/policy-requests/{UUID}' -H 'Accept: application/json'"

# 6. Submit for issuance (SUCCESS scenario)
echo ""
echo "=== 6. Submit for Issuance ==="
echo "curl -s -X POST '${BASE_URL}/policy-requests/{UUID}/submit' -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{\"scenario\": \"SUCCESS\"}' | python3 -m json.tool"

# 7. Submit with TEMPORARY_FAILURE scenario
echo ""
echo "=== 7. Temporary Failure Scenario ==="
echo "curl -s -X POST '${BASE_URL}/policy-requests/{UUID}/submit' -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{\"scenario\": \"TEMPORARY_FAILURE\"}' | python3 -m json.tool"

# 8. Submit with TIMEOUT_AFTER_ISSUE scenario
echo ""
echo "=== 8. Timeout After Issue Scenario ==="
echo "curl -s -X POST '${BASE_URL}/policy-requests/{UUID}/submit' -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{\"scenario\": \"TIMEOUT_AFTER_ISSUE\"}' | python3 -m json.tool"

# 9. Run Reconciliation via Artisan Command
echo ""
echo "=== 9. Run Reconciliation Command ==="
echo "php artisan policy:reconcile"

# 10. Send a callback
echo ""
echo "=== 10. Mock Insurer Callback ==="
echo "curl -s -X POST '${BASE_URL}/mock-insurer/callback' -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{\"event_id\": \"EVT-001\", \"policy_request_uuid\": \"{UUID}\", \"event_type\": \"POLICY_ISSUED\", \"provider_reference\": \"PROV-ABC123\", \"policy_number\": \"POL-XYZ789\"}' | python3 -m json.tool"

# 11. Filter by status
echo ""
echo "=== 11. Filter by Status ==="
curl -s -X GET "${BASE_URL}/policy-requests?status=RECEIVED" \
  -H "Accept: application/json" | python3 -m json.tool

# 12. Check mock insurer status (reconciliation)
echo ""
echo "=== 12. Mock Insurer Status Check ==="
echo "curl -s -X GET '${BASE_URL}/mock-insurer/status/{UUID}' -H 'Accept: application/json' | python3 -m json.tool"

echo ""
echo "========================================"
echo "Replace {UUID} with actual UUID from step 1"
echo "========================================"
