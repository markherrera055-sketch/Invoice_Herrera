<?php

namespace App\Services\Ops;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Ops\OpsSequence;
use App\Models\Ops\RepairJob;
use App\Models\Ops\Warranty;
use App\Models\Ops\WarrantyClaim;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 4: warranty records, automatic eligibility checks and claims (P7).
 *
 * Claim -> eligibility check -> technician verification -> approve / reject
 *   approve: warranty repair job (covered parts/labor billed at zero)
 *   reject : regular repair job, so a paid quotation can be issued
 */
class WarrantyService
{
    public function createForJob(RepairJob $job, ?Invoice $invoice, ?User $by, ?int $days = null, string $coverage = 'parts_and_labor'): Warranty
    {
        $items = $job->parts()->with('product')->get()
            ->map(fn ($p) => $p->quantity . ' x ' . ($p->product->notes ?: $p->product->product_key))
            ->implode("\n");

        $w = new Warranty();
        $w->company_id = $job->company_id;
        $w->user_id = $by?->id;
        $w->client_id = $job->client_id;
        $w->device_id = $job->device_id;
        $w->repair_job_id = $job->id;
        $w->invoice_id = $invoice?->id;
        $w->warranty_number = OpsSequence::next($job->company_id, 'WR');
        $w->coverage_type = $coverage;
        $w->covered_items = trim("Repair work on {$job->job_number}" . ($items ? "\nReplaced parts:\n{$items}" : ''));
        $w->exclusions = 'Physical damage, liquid damage, and unauthorised modifications after service.';
        $w->start_date = now()->startOfDay();
        $w->end_date = now()->startOfDay()->addDays($days ?? config('pcfix.defaults.warranty_days'));
        $w->status = 'active';
        $w->save();

        $w->status = $w->computeStatus();
        $w->save();

        AuditLogger::log($w->company_id, 'warranty.created', $w, ['repair_job' => $job->job_number, 'end_date' => $w->end_date->toDateString()], $by?->id);

        return $w;
    }

    public function refreshStatus(?Warranty $w): void
    {
        if (! $w) {
            return;
        }

        $status = $w->computeStatus();

        if ($status !== $w->status) {
            $w->status = $status;
            $w->save();
        }
    }

    /**
     * Automatic eligibility check by warranty, device serial number, or client.
     *
     * @return array{eligible:bool, warranty_id:?int, warranty_number:?string, reason:string, coverage:?string, end_date:?string, checked_at:string}
     */
    public function checkEligibility(int $company_id, int $client_id, ?string $serial = null, ?int $warranty_id = null): array
    {
        $query = Warranty::query()->forCompany($company_id)->where('client_id', $client_id)->where('status', '!=', 'void');

        if ($warranty_id) {
            $query->whereKey($warranty_id);
        } elseif ($serial) {
            $query->whereHas('device', fn ($d) => $d->where('serial_number', trim($serial)));
        }

        $warranties = $query->orderByDesc('end_date')->get();

        $result = fn (bool $eligible, ?Warranty $w, string $reason) => [
            'eligible' => $eligible,
            'warranty_id' => $w?->id,
            'warranty_number' => $w?->warranty_number,
            'reason' => $reason,
            'coverage' => $w?->coverage_type,
            'end_date' => $w?->end_date?->toDateString(),
            'checked_at' => now()->toDateTimeString(),
        ];

        if ($warranties->isEmpty()) {
            return $result(false, null, $serial ? "No warranty found for serial number {$serial}." : 'No warranty found for this customer.');
        }

        $active = $warranties->first(fn (Warranty $w) => $w->isInCoverage());

        if (! $active) {
            $latest = $warranties->first();

            return $result(false, $latest, "Warranty {$latest->warranty_number} expired on {$latest->end_date->toDateString()}.");
        }

        $openClaim = $active->claims()->whereIn('status', ['submitted', 'approved', 'in_repair'])->exists();

        if ($openClaim) {
            return $result(false, $active, "Warranty {$active->warranty_number} already has an open claim.");
        }

        return $result(true, $active, "Covered by warranty {$active->warranty_number} (" . Warranty::COVERAGE[$active->coverage_type] . ") until {$active->end_date->toDateString()}.");
    }

    public function submitClaim(Company $company, Client $client, ?ClientContact $contact, array $data, string $via, ?User $by = null): WarrantyClaim
    {
        if ((int) $client->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['client' => 'Invalid customer.']);
        }

        $warrantyId = $data['warranty_id'] ?? null;

        if ($warrantyId && ! Warranty::query()->forCompany($company->id)->where('client_id', $client->id)->whereKey($warrantyId)->exists()) {
            throw ValidationException::withMessages(['warranty_id' => 'Invalid warranty.']);
        }

        $eligibility = $this->checkEligibility($company->id, $client->id, $data['serial_number'] ?? null, $warrantyId);

        $claim = DB::transaction(function () use ($company, $client, $contact, $data, $via, $by, $eligibility) {
            $warranty = $eligibility['warranty_id'] ? Warranty::find($eligibility['warranty_id']) : null;

            $claim = new WarrantyClaim();
            $claim->company_id = $company->id;
            $claim->client_id = $client->id;
            $claim->client_contact_id = $contact?->id;
            $claim->warranty_id = $warranty?->id;
            $claim->device_id = $warranty?->device_id;
            $claim->claim_number = OpsSequence::next($company->id, 'WC');
            $claim->serial_number = $data['serial_number'] ?? null;
            $claim->issue_description = $data['issue_description'];
            $claim->eligibility = $eligibility;
            $claim->status = 'submitted';
            $claim->submitted_via = $via;
            $claim->save();

            AuditLogger::log($company->id, 'warranty_claim.submitted', $claim, ['eligible' => $eligibility['eligible']], $by?->id, $contact?->id);

            return $claim;
        });

        OpsNotifier::staff(
            $company,
            'warranty.manage',
            'warranty_claim',
            'c' . $claim->id,
            "Warranty claim {$claim->claim_number}",
            "{$client->present()->name()} submitted a warranty claim. Automatic check: {$eligibility['reason']}",
            route('ops.warranty.claims.show', $claim->hashed_id),
        );

        return $claim;
    }

    /**
     * Technician verification decision.
     */
    public function decide(WarrantyClaim $claim, User $by, bool $approve, string $notes, bool $createPaidJob = true): WarrantyClaim
    {
        if ($claim->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => 'This claim has already been decided.']);
        }

        // Re-check at decision time: coverage may have changed since submission.
        $eligibility = $this->checkEligibility($claim->company_id, $claim->client_id, $claim->serial_number, $claim->warranty_id);

        if ($approve && ! $eligibility['eligible'] && ! str_contains($eligibility['reason'], 'already has an open claim')) {
            throw ValidationException::withMessages(['decision' => 'Cannot approve: ' . $eligibility['reason']]);
        }

        $jobs = app(RepairJobService::class);

        DB::transaction(function () use ($claim, $by, $approve, $notes, $createPaidJob, $eligibility, $jobs) {
            $claim->verified_by = $by->id;
            $claim->verified_at = now();
            $claim->verification_notes = $notes;
            $claim->eligibility = $eligibility;

            $original = $claim->warranty?->repairJob;

            if ($approve || $createPaidJob) {
                $job = new RepairJob();
                $job->company_id = $claim->company_id;
                $job->user_id = $by->id;
                $job->client_id = $claim->client_id;
                $job->device_id = $claim->device_id ?? $original?->device_id;
                $job->job_number = OpsSequence::next($claim->company_id, 'RJ');
                $job->priority = 'high';
                $job->required_skill = $original?->required_skill;
                $job->reported_issue = ($approve ? '[WARRANTY] ' : '[NOT COVERED] ') . $claim->issue_description;
                $job->is_warranty_job = $approve;
                $job->warranty_claim_id = $claim->id;
                $job->status = $approve ? 'approved' : 'pending';
                $job->save();

                $jobs->log($job, 'system', null, $job->status, $approve
                    ? "Warranty claim {$claim->claim_number} approved by {$by->present()->name()}. Covered repair - no additional charge unless excluded."
                    : "Created from rejected warranty claim {$claim->claim_number}. A paid quotation is required.", true, $by->id);

                $claim->repair_job_id = $job->id;
            }

            $claim->status = $approve ? 'in_repair' : 'rejected';
            $claim->save();

            AuditLogger::log($claim->company_id, $approve ? 'warranty_claim.approved' : 'warranty_claim.rejected', $claim, ['notes' => $notes], $by->id);
        });

        $this->refreshStatus($claim->warranty);

        if ($approve && $claim->repairJob) {
            $jobs->autoAssign($claim->repairJob, $by);
        }

        OpsNotifier::contact(
            Company::find($claim->company_id),
            $claim->contact ?? $claim->client->contacts()->first(),
            'warranty_decision',
            'c' . $claim->id,
            "Warranty claim {$claim->claim_number}: " . ($approve ? 'Approved' : 'Not covered'),
            $approve
                ? 'Your claim was approved. We will repair your device at no additional charge.'
                : 'Your claim is not covered by warranty' . ($notes ? ": {$notes}" : '.') . ' We will send you a quotation for the repair.',
            route('client.ops.warranty.index'),
        );

        return $claim->fresh();
    }

    /** Daily: refresh statuses and notify customers about expiring warranties (deduplicated). */
    public function runDaily(): array
    {
        $updated = 0;
        $notified = 0;

        Warranty::query()->whereIn('status', ['active', 'expiring_soon', 'claimed'])->chunkById(200, function ($warranties) use (&$updated, &$notified) {
            foreach ($warranties as $w) {
                $before = $w->status;
                $this->refreshStatus($w);
                $updated += $before !== $w->status ? 1 : 0;

                if ($w->status === 'expiring_soon' && ! $w->expiry_notified_at) {
                    OpsNotifier::contact(
                        Company::find($w->company_id),
                        $w->client?->contacts()->first(),
                        'warranty_expiring',
                        'w' . $w->id,
                        "Warranty {$w->warranty_number} expires soon",
                        'Your warranty for ' . ($w->device?->label() ?? 'your repair') . " expires on {$w->end_date->toDateString()}. Contact us if you notice any issue before then.",
                        route('client.ops.warranty.index'),
                    );
                    $w->expiry_notified_at = now();
                    $w->save();
                    $notified++;
                }
            }
        });

        return ['status_changes' => $updated, 'expiry_notices' => $notified];
    }
}
