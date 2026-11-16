<?php

namespace App\Services\Ops;

use App\Models\ClientContact;
use App\Models\Company;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Models\Ops\OpsSequence;
use App\Models\Ops\RepairJob;
use App\Models\Ops\RepairJobPart;
use App\Models\Ops\RepairJobQuote;
use App\Models\Ops\RepairJobUpdate;
use App\Models\Ops\ServiceRequest;
use App\Models\Ops\TechnicianProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Repair job lifecycle (Module 2).
 *
 * pending -> awaiting_approval -> approved -> assigned -> in_progress
 *        -> (waiting_for_parts | awaiting_approval for a revision) -> completed
 *
 * All state changes run inside a transaction with a row lock on the job and
 * write a repair_job_updates entry (status history / audit trail).
 */
class RepairJobService
{
    public function __construct(
        private TechnicianAssigner $assigner,
        private InventoryService $inventory,
        private QuoteBuilder $quotes,
    ) {}

    /* ------------------------------------------------------------------ */
    /* Creation                                                            */
    /* ------------------------------------------------------------------ */

    public function createFromServiceRequest(ServiceRequest $sr, ?User $by, array $overrides = []): RepairJob
    {
        if (! $sr->client_id) {
            throw ValidationException::withMessages(['client' => 'Link the request to a customer record before creating a repair job.']);
        }

        if ($sr->repair_job_id) {
            return $sr->repairJob;
        }

        return DB::transaction(function () use ($sr, $by, $overrides) {
            $job = new RepairJob();
            $job->company_id = $sr->company_id;
            $job->user_id = $by?->id;
            $job->service_request_id = $sr->id;
            $job->client_id = $sr->client_id;
            $job->device_id = $sr->device_id;
            $job->job_number = OpsSequence::next($sr->company_id, 'RJ');
            $job->priority = $overrides['priority'] ?? $sr->priority ?? 'normal';
            $job->required_skill = array_key_exists('required_skill', $overrides) ? $overrides['required_skill'] : $this->inferSkill($sr->device_type, $sr->reported_issue);
            $job->reported_issue = $sr->reported_issue;
            $job->scheduled_at = $sr->preferred_service_date;
            $job->status = 'pending';
            $job->save();

            $sr->repair_job_id = $job->id;
            $sr->save();

            $this->log($job, 'system', null, 'pending', "Repair job created from service request {$sr->request_number}.", true, $by?->id);

            AuditLogger::log($job->company_id, 'repair_job.created', $job, ['service_request' => $sr->request_number], $by?->id);

            return $job;
        });
    }

    /** Suggest a required skill from the device type and the reported issue. */
    public function inferSkill(?string $deviceType, ?string $issue): ?string
    {
        $issue = strtolower((string) $issue);

        return match (true) {
            (bool) preg_match('/data|recover|deleted|corrupt/', $issue) => 'data_recovery',
            (bool) preg_match('/virus|malware|windows|software|install|os |operating system|update|slow boot/', $issue) => 'software',
            in_array($deviceType, ['Mobile Phone', 'Tablet'], true) => 'mobile',
            $deviceType === 'Printer' => 'printer',
            $deviceType === 'Network Device' || (bool) preg_match('/wifi|wi-fi|network|internet|router/', $issue) => 'network',
            in_array($deviceType, ['Laptop', 'Desktop', 'Monitor', 'UPS'], true) => 'hardware',
            default => null,
        };
    }

    /* ------------------------------------------------------------------ */
    /* Quotes                                                              */
    /* ------------------------------------------------------------------ */

    public function linkQuote(RepairJob $job, Quote $quote, string $type): void
    {
        $link = RepairJobQuote::query()->where('repair_job_id', $job->id)->where('quote_id', $quote->id)->first() ?? new RepairJobQuote();
        $link->company_id = $job->company_id;
        $link->repair_job_id = $job->id;
        $link->quote_id = $quote->id;
        $link->type = $type;
        $link->save();

        if ($type !== 'revision') {
            $job->quote_id = $quote->id;
            $job->save();
        }
    }

    /**
     * Initial quotation for a job: created in Invoice Ninja and sent to the customer.
     */
    public function issueInitialQuote(RepairJob $job, User $by, array $lines, string $notes = ''): Quote
    {
        $quote = $this->quotes->issue($job->client, $by->id, $lines, $this->quoteNotes($job, $notes));

        DB::transaction(function () use ($job, $quote, $by) {
            $this->linkQuote($job, $quote, 'initial');
            $this->transition($job, 'awaiting_approval', $by, "Quotation {$quote->number} sent to customer for approval.", true, system: true);
        });

        if ($job->serviceRequest) {
            $job->serviceRequest->forceFill(['status' => 'quoted', 'quote_id' => $quote->id])->save();
        }

        return $quote;
    }

    /**
     * Additional parts / services discovered during repair require a revised
     * quotation approved by the customer before work continues.
     */
    public function requestRevision(RepairJob $job, User $by, array $lines, string $reason): Quote
    {
        if (! in_array($job->status, ['in_progress', 'waiting_for_parts', 'assigned'], true)) {
            throw ValidationException::withMessages(['status' => 'A revised quotation can only be requested while the job is being worked on.']);
        }

        if ($job->hasPendingRevision()) {
            throw ValidationException::withMessages(['quote' => 'A revised quotation is already waiting for the customer.']);
        }

        $quote = $this->quotes->issue($job->client, $by->id, $lines, $this->quoteNotes($job, 'Revised quotation - additional work: ' . $reason));

        DB::transaction(function () use ($job, $quote, $by, $reason) {
            $this->linkQuote($job, $quote, 'revision');
            $this->transition($job, 'awaiting_approval', $by, "Revised quotation {$quote->number} sent: {$reason}", true, system: true);
        });

        return $quote;
    }

    private function quoteNotes(RepairJob $job, string $extra): string
    {
        $device = $job->device;
        $parts = array_filter([
            "Repair Job: {$job->job_number}",
            $job->serviceRequest ? "Service Request: {$job->serviceRequest->request_number}" : null,
            $device ? 'Device: ' . $device->label() . ($device->serial_number ? " (S/N {$device->serial_number})" : '') : null,
            $extra ?: null,
        ]);

        return implode("\n", $parts);
    }

    /** Called from the QuoteWasApproved / QuoteWasMarkedApproved listeners. */
    public function onQuoteApproved(Quote $quote): void
    {
        $link = RepairJobQuote::query()->where('company_id', $quote->company_id)->where('quote_id', $quote->id)->first();

        if (! $link || ! ($job = $link->repairJob)) {
            return;
        }

        if ($link->type === 'revision') {
            if ($job->status === 'awaiting_approval') {
                $this->transition($job, 'in_progress', null, "Customer approved revised quotation {$quote->number}. Work resumes.", true, system: true);
                $this->notifyTechnician($job, 'revision_approved', "Revision approved: {$job->job_number}", "The customer approved quotation {$quote->number}. You may proceed with the additional work.");
            }

            return;
        }

        if (in_array($job->status, ['pending', 'awaiting_approval'], true)) {
            $this->transition($job, 'approved', null, "Customer approved quotation {$quote->number}.", true, system: true);

            if ($job->serviceRequest) {
                $job->serviceRequest->forceFill(['status' => 'approved'])->save();
            }

            OpsNotifier::staff($job->company, 'repair_jobs.manage', 'quote_approved', 'q' . $quote->id, "Quotation approved: {$job->job_number}", "{$job->client->present()->name()} approved quotation {$quote->number}.", route('ops.repair_jobs.show', $job->hashed_id));

            $this->autoAssign($job->fresh(), null);
        }
    }

    /** Called from the QuoteWasRejected listener. */
    public function onQuoteRejected(Quote $quote, string $notes = ''): void
    {
        $link = RepairJobQuote::query()->where('company_id', $quote->company_id)->where('quote_id', $quote->id)->first();

        if (! $link || ! ($job = $link->repairJob) || $job->status !== 'awaiting_approval') {
            return;
        }

        $why = $notes ? " Reason: {$notes}" : '';

        if ($link->type === 'revision') {
            $this->transition($job, 'in_progress', null, "Customer declined revised quotation {$quote->number}; continue with the originally approved scope only.{$why}", true, system: true);
            $this->notifyTechnician($job, 'revision_rejected', "Revision declined: {$job->job_number}", "The customer declined quotation {$quote->number}. Continue with the original scope only.");
        } else {
            $this->transition($job, 'cancelled', null, "Customer declined quotation {$quote->number}.{$why}", true, system: true);

            if ($job->serviceRequest) {
                $job->serviceRequest->forceFill(['status' => 'rejected'])->save();
            }
        }

        OpsNotifier::staff($job->company, 'repair_jobs.manage', 'quote_rejected', 'q' . $quote->id, "Quotation declined: {$job->job_number}", "{$job->client->present()->name()} declined quotation {$quote->number}.{$why}", route('ops.repair_jobs.show', $job->hashed_id));
    }

    /* ------------------------------------------------------------------ */
    /* Assignment                                                          */
    /* ------------------------------------------------------------------ */

    public function autoAssign(RepairJob $job, ?User $by): ?User
    {
        if (! in_array($job->status, ['approved', 'assigned'], true)) {
            throw ValidationException::withMessages(['status' => 'Only approved jobs can be assigned.']);
        }

        $profile = $this->assigner->pick($job);

        if (! $profile) {
            $this->log($job, 'assignment', null, null, 'No eligible technician available (availability, skill or capacity). Waiting for manual assignment.', false, $by?->id);

            OpsNotifier::staff($job->company, 'repair_jobs.manage', 'assignment_needed', 'j' . $job->id . ':' . now()->format('YmdH'), "Manual assignment needed: {$job->job_number}", 'No technician currently meets availability, skill and workload requirements.', route('ops.repair_jobs.show', $job->hashed_id));

            return null;
        }

        $this->assignTo($job, $profile, $by, 'auto', 'Automatically assigned based on availability, skills and workload.');

        return $profile->user;
    }

    public function assignManual(RepairJob $job, int $technician_user_id, User $by, ?string $reason): User
    {
        if (! in_array($job->status, ['approved', 'assigned', 'in_progress', 'waiting_for_parts'], true)) {
            throw ValidationException::withMessages(['status' => 'This job cannot be (re)assigned in its current status.']);
        }

        $profile = TechnicianProfile::query()->forCompany($job->company_id)->where('user_id', $technician_user_id)->first();

        if (! $profile) {
            throw ValidationException::withMessages(['technician' => 'The selected user is not a technician of this company.']);
        }

        $this->assignTo($job, $profile, $by, 'manual', 'Manual assignment by ' . $by->present()->name() . ($reason ? ": {$reason}" : '.'));

        return $profile->user;
    }

    private function assignTo(RepairJob $job, TechnicianProfile $profile, ?User $by, string $mode, string $note): void
    {
        DB::transaction(function () use ($job, $profile, $by, $mode, $note) {
            $locked = RepairJob::query()->whereKey($job->id)->lockForUpdate()->first();
            $previous = $locked->assigned_user_id;

            $locked->assigned_user_id = $profile->user_id;
            $locked->assignment_mode = $mode;
            $locked->save();

            $profile->last_assigned_at = now();
            $profile->save();

            $this->log($locked, 'assignment', null, null, $note . ' Technician: ' . $profile->user->present()->name(), false, $by?->id);

            if ($locked->status === 'approved') {
                $this->transition($locked, 'assigned', $by, 'Technician assigned.', true, system: true);
            }

            AuditLogger::log($locked->company_id, 'repair_job.assigned', $locked, ['from' => $previous, 'to' => $profile->user_id, 'mode' => $mode], $by?->id);

            $job->setRawAttributes($locked->fresh()->getAttributes(), true);
        });

        $this->notifyTechnician($job, 'job_assigned', "New repair job assigned: {$job->job_number}", 'Priority: ' . ucfirst($job->priority) . '. Issue: ' . $job->reported_issue, 'a' . $profile->user_id);
    }

    /* ------------------------------------------------------------------ */
    /* Status changes & work                                               */
    /* ------------------------------------------------------------------ */

    public function transition(RepairJob $job, string $to, ?User $by, ?string $note = null, bool $customerVisible = true, bool $system = false): RepairJob
    {
        return DB::transaction(function () use ($job, $to, $by, $note, $customerVisible, $system) {
            $locked = RepairJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($from === $to) {
                return $locked;
            }

            $allowed = config('pcfix.repair_transitions.' . $from, []);

            if (! $system && ! in_array($to, $allowed, true)) {
                throw ValidationException::withMessages(['status' => "Cannot move a job from \"{$locked->statusLabel()}\" to \"" . (config('pcfix.repair_statuses')[$to] ?? $to) . '".']);
            }

            if (in_array($from, ['completed', 'cancelled'], true) && ! $system) {
                throw ValidationException::withMessages(['status' => 'Closed jobs cannot be changed.']);
            }

            if ($to === 'in_progress' && ! $locked->started_at) {
                $locked->started_at = now();
            }

            if ($to === 'completed') {
                $locked->completed_at = now();
            }

            $locked->status = $to;
            $locked->save();

            $this->log($locked, 'status_change', $from, $to, $note, $customerVisible, $by?->id);

            $job->setRawAttributes($locked->getAttributes(), true);

            DB::afterCommit(fn () => $this->notifyCustomerOfStatus($locked));

            return $locked;
        });
    }

    public function start(RepairJob $job, User $by): RepairJob
    {
        return $this->transition($job, 'in_progress', $by, 'Technician started working on the device.');
    }

    public function addNote(RepairJob $job, User $by, string $note, bool $customerVisible = false, string $type = 'note'): RepairJobUpdate
    {
        if (in_array($job->status, ['completed', 'cancelled'], true) && $type !== 'note') {
            throw ValidationException::withMessages(['status' => 'Closed jobs cannot be changed.']);
        }

        if ($type === 'diagnosis') {
            $job->diagnosis = trim(($job->diagnosis ? $job->diagnosis . "\n" : '') . $note);
            $job->save();
        }

        return $this->log($job, $type, null, null, $note, $customerVisible, $by->id);
    }

    /**
     * Record a spare part used on the job and deduct it from stock atomically.
     */
    public function addPart(RepairJob $job, Product $product, int $quantity, User $by, ?float $unitPrice = null): RepairJobPart
    {
        if ((int) $product->company_id !== (int) $job->company_id) {
            throw ValidationException::withMessages(['product' => 'Invalid product.']);
        }

        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be at least 1.']);
        }

        if (! in_array($job->status, ['assigned', 'in_progress', 'waiting_for_parts'], true)) {
            throw ValidationException::withMessages(['status' => 'Parts can only be recorded while the job is being worked on.']);
        }

        return DB::transaction(function () use ($job, $product, $quantity, $by, $unitPrice) {
            $movement = $this->inventory->move($product, -$quantity, 'repair_usage', $by->id, "Used on {$job->job_number}", 'repair_job', $job->id);

            $part = new RepairJobPart();
            $part->company_id = $job->company_id;
            $part->repair_job_id = $job->id;
            $part->product_id = $product->id;
            $part->user_id = $by->id;
            $part->inventory_movement_id = $movement->id;
            $part->quantity = $quantity;
            $part->unit_cost = (float) $product->cost;
            $part->unit_price = $unitPrice ?? (float) $product->price;
            $part->is_warranty_covered = $job->is_warranty_job && ($job->warrantyClaim?->warranty?->coversParts() ?? false);
            $part->save();

            $this->log($job, 'part', null, null, "Used {$quantity} x {$product->product_key}" . ($part->is_warranty_covered ? ' (warranty covered)' : ''), false, $by->id);

            return $part;
        });
    }

    /** Return an unused part to stock (reverses the movement). */
    public function removePart(RepairJobPart $part, User $by): void
    {
        $job = $part->repairJob;

        if ($job->invoice_id) {
            throw ValidationException::withMessages(['part' => 'Parts on an invoiced job cannot be removed.']);
        }

        DB::transaction(function () use ($part, $job, $by) {
            $this->inventory->move($part->product, $part->quantity, 'repair_return', $by->id, "Returned from {$job->job_number}", 'repair_job', $job->id, (float) $part->unit_cost);
            $this->log($job, 'part', null, null, "Returned {$part->quantity} x {$part->product->product_key} to stock", false, $by->id);
            $part->delete();
        });
    }

    public function complete(RepairJob $job, User $by, string $summary): RepairJob
    {
        if ($job->hasPendingRevision()) {
            throw ValidationException::withMessages(['status' => 'A revised quotation is still awaiting the customer decision.']);
        }

        $job = $this->transition($job, 'completed', $by, 'Repair completed: ' . $summary);

        OpsNotifier::staff(
            $job->company,
            'billing.manage',
            'job_completed',
            'j' . $job->id,
            "Ready for billing: {$job->job_number}",
            "{$by->present()->name()} completed {$job->job_number} for {$job->client->present()->name()}. Verify completion and generate the invoice.",
            route('ops.billing.index'),
        );

        AuditLogger::log($job->company_id, 'repair_job.completed', $job, ['summary' => $summary], $by->id);

        return $job;
    }

    public function updatePriority(RepairJob $job, User $by, string $priority, ?string $skill): void
    {
        $changes = [];

        if ($job->priority !== $priority) {
            $changes[] = 'priority ' . $job->priority . ' -> ' . $priority;
            $job->priority = $priority;
        }

        if ($job->required_skill !== $skill) {
            $changes[] = 'required skill ' . ($job->required_skill ?: 'none') . ' -> ' . ($skill ?: 'none');
            $job->required_skill = $skill;
        }

        if ($changes) {
            $job->save();
            $this->log($job, 'note', null, null, 'Updated ' . implode(', ', $changes), false, $by->id);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    public function log(RepairJob $job, string $type, ?string $from, ?string $to, ?string $note, bool $customerVisible, ?int $user_id): RepairJobUpdate
    {
        $u = new RepairJobUpdate();
        $u->company_id = $job->company_id;
        $u->repair_job_id = $job->id;
        $u->user_id = $user_id;
        $u->type = $type;
        $u->from_status = $from;
        $u->to_status = $to;
        $u->note = $note;
        $u->is_customer_visible = $customerVisible;
        $u->save();

        return $u;
    }

    public function customerContact(RepairJob $job): ?ClientContact
    {
        return $job->serviceRequest?->contact
            ?? $job->warrantyClaim?->contact
            ?? $job->client?->contacts()->first();
    }

    private function notifyCustomerOfStatus(RepairJob $job): void
    {
        $messages = [
            'approved' => 'Your repair has been approved and is being scheduled.',
            'assigned' => 'A technician has been assigned to your device.',
            'in_progress' => 'Our technician is now working on your device.',
            'waiting_for_parts' => 'We are waiting for replacement parts for your device.',
            'awaiting_approval' => 'A quotation is waiting for your approval in the client portal.',
            'completed' => 'Your device repair is complete.',
            'cancelled' => 'Your repair job has been cancelled.',
        ];

        if (! isset($messages[$job->status])) {
            return;
        }

        $company = Company::find($job->company_id);

        OpsNotifier::contact(
            $company,
            $this->customerContact($job),
            'repair_status',
            'j' . $job->id . ':' . $job->status . ':' . $job->updates()->count(),
            "Repair {$job->job_number}: " . $job->statusLabel(),
            $messages[$job->status],
            route('client.ops.repairs.show', $job->hashed_id),
            sms: in_array($job->status, ['completed', 'awaiting_approval'], true),
        );
    }

    private function notifyTechnician(RepairJob $job, string $type, string $title, string $body, string $suffix = ''): void
    {
        if (! $job->assigned_user_id || ! ($tech = User::find($job->assigned_user_id))) {
            return;
        }

        OpsNotifier::user(Company::find($job->company_id), $tech, $type, 'j' . $job->id . $suffix . ':' . $job->updates()->count(), $title, $body, route('ops.repair_jobs.show', $job->hashed_id));
    }
}
