# AI Usage Disclosure

## Tools Used

1. **Google Antigravity (Claude)** — AI-assisted code generation and architectural planning
2. **GitHub Copilot** — Inline code suggestions during development

## How AI Helped

1. **Boilerplate scaffolding**: AI assisted in generating the initial Laravel controller, model, and migration boilerplate, significantly reducing time spent on repetitive structural code. This allowed more focus on business logic correctness.

2. **Test case generation**: AI helped enumerate edge cases for premium calculation (age boundary conditions, coverage multiplier combinations) and suggested test structures for idempotency verification.

3. **State machine design**: AI suggested the transition map pattern for the `PolicyStateMachine` service, which I refined to ensure terminal states (ISSUED) cannot regress.

## Incorrect or Incomplete Suggestions

1. **Table naming**: AI initially generated a `PolicyStateHistory` model without explicitly setting the `$table` property. Laravel's default pluralization inferred `policy_state_histories` instead of the migration's `policy_state_history`, causing a runtime table-not-found error. I caught this through test execution and added the explicit `$table = 'policy_state_history'` property.

2. **Premium rounding**: An initial suggestion used plain PHP `round()` for premium calculation. I replaced this with `bcmath` functions (`bcmul`, `bcadd`) to ensure decimal-safe arithmetic as required by the specification.

3. **Duplicate intake race condition**: The initial suggestion only checked for duplicates at the application level (a `WHERE` query before insert). I added a database-level unique constraint (`unique(['agent_id', 'client_request_id'])`) and a `try/catch` for `UniqueConstraintViolationException` to handle true concurrent requests safely.

## Verification Process

- **All AI-generated code was reviewed line-by-line** before integration.
- **Automated tests were written and executed** to verify business logic correctness, including the specific example from the spec (PLUS plan, 5 days, ₹225.00).
- **Manual API testing** was performed using curl commands to verify end-to-end flows.
- **Edge cases were tested** by deliberately triggering validation errors, duplicate submissions, and invalid state transitions.
- **Database constraints were verified** by attempting direct model creation bypassing application logic.
