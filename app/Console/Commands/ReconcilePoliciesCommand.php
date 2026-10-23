<?php

namespace App\Console\Commands;

use App\Models\PolicyRequest;
use App\Services\IssuanceService;
use Illuminate\Console\Command;

class ReconcilePoliciesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'policy:reconcile {--uuid= : Specific policy request UUID to reconcile}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile stale, pending, or timeout policy requests against the external/mock insurer';

    public function handle(IssuanceService $issuanceService): int
    {
        $this->info('Starting policy reconciliation scan...');

        $query = PolicyRequest::query();

        if ($uuid = $this->option('uuid')) {
            $query->where('uuid', $uuid);
        } else {
            $query->whereIn('status', ['PENDING_CONFIRMATION', 'NEEDS_RECONCILIATION', 'SUBMITTED']);
        }

        $pendingRequests = $query->get();

        if ($pendingRequests->isEmpty()) {
            $this->info('No policy requests require reconciliation.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d request(s) to reconcile.', $pendingRequests->count()));
        $reconciledCount = 0;

        foreach ($pendingRequests as $policyRequest) {
            $prevStatus = $policyRequest->status;
            $updated = $issuanceService->reconcileIfNeeded($policyRequest);

            if ($updated->status === 'ISSUED') {
                $this->info(sprintf(
                    '[%s] Successfully reconciled %s -> ISSUED (Policy #%s, Provider Ref: %s)',
                    $policyRequest->uuid,
                    $prevStatus,
                    $updated->policy_number,
                    $updated->provider_reference
                ));
                $reconciledCount++;
            } else {
                $this->warn(sprintf(
                    '[%s] Status unchanged (%s). Provider has not confirmed issuance.',
                    $policyRequest->uuid,
                    $updated->status
                ));
            }
        }

        $this->info(sprintf('Reconciliation complete. %d request(s) marked ISSUED.', $reconciledCount));

        return self::SUCCESS;
    }
}
