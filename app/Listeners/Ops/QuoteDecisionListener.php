<?php

namespace App\Listeners\Ops;

use App\Libraries\MultiDB;
use App\Services\Ops\RepairJobService;
use Illuminate\Support\Facades\Log;

/**
 * Bridges Invoice Ninja's native quote approval / rejection (client portal or
 * admin "mark approved") into the repair job workflow.
 *
 * Runs synchronously and never throws back into Invoice Ninja's quote flow.
 */
class QuoteDecisionListener
{
    public function approved($event): void
    {
        $this->safely($event, fn () => app(RepairJobService::class)->onQuoteApproved($event->quote->fresh()));
    }

    public function rejected($event): void
    {
        $this->safely($event, fn () => app(RepairJobService::class)->onQuoteRejected($event->quote->fresh(), (string) ($event->notes ?? '')));
    }

    private function safely($event, callable $fn): void
    {
        try {
            MultiDB::setDb($event->company->db);
            $fn();
        } catch (\Throwable $e) {
            Log::error('PCFix quote listener failed: ' . $e->getMessage(), ['quote_id' => $event->quote->id ?? null]);
        }
    }
}
