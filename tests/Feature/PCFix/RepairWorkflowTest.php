<?php

namespace Tests\Feature\PCFix;

use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Ops\InventoryMovement;
use App\Models\Ops\OpsNotification;
use App\Models\Ops\RepairJob;
use App\Models\Ops\ServiceRequest;
use App\Models\Ops\Warranty;
use App\Services\Ops\InvoiceGenerator;
use App\Services\Ops\QuoteBuilder;
use App\Services\Ops\RepairJobService;
use App\Services\Ops\ServiceRequestService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * Modules 2 & 3 — repair job assignment/execution and automated invoicing (P3, P4, P5, P9).
 */
class RepairWorkflowTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;
    use PCFixTestHelpers;

    private RepairJobService $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        Session::start();
        Model::reguard();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->makeTestData();

        $this->jobs = app(RepairJobService::class);
        $this->manager = $this->makeStaff('service_manager');
        $this->actAs($this->manager);
    }

    private function newJob(array $payload = []): RepairJob
    {
        $contact = $this->client->contacts()->first();
        [$sr] = app(ServiceRequestService::class)->submit($this->company, $this->requestPayload($payload), 'portal', $contact);

        return $this->jobs->createFromServiceRequest($sr, $this->manager);
    }

    private function quoteLines(): array
    {
        return [
            QuoteBuilder::line('SERVICE-FEE', 'Diagnostic fee', 300, 1, '2'),
            QuoteBuilder::line('LABOR', 'Repair labor', 500, 2, '2'),
        ];
    }

    public function test_initial_quote_is_a_real_invoice_ninja_quote_awaiting_approval(): void
    {
        $job = $this->newJob();
        $quote = $this->jobs->issueInitialQuote($job, $this->manager, $this->quoteLines());

        $job->refresh();
        $this->assertSame('awaiting_approval', $job->status);
        $this->assertSame($quote->id, $job->quote_id);
        $this->assertSame(Quote::STATUS_SENT, (int) $quote->status_id);
        $this->assertNotEmpty($quote->number);
        $this->assertEquals(1300.0, (float) $quote->amount, 'totals come from Invoice Ninja calc engine');
        $this->assertGreaterThan(0, $quote->invitations()->count(), 'invitation exists so the customer can approve in the portal');
        $this->assertSame('quoted', ServiceRequest::find($job->service_request_id)->status);
    }

    public function test_customer_approval_assigns_least_loaded_eligible_technician(): void
    {
        $busy = $this->makeStaff('technician', ['hardware']);
        $free = $this->makeStaff('technician', ['hardware']);
        $this->makeStaff('technician', ['hardware'], available: false);   // unavailable
        $this->makeStaff('technician', ['printer']);                      // wrong skill

        // Give $busy an existing active job.
        $existing = $this->newJob();
        $existing->forceFill(['status' => 'in_progress', 'assigned_user_id' => $busy->id])->save();

        $job = $this->newJob(['reported_issue' => 'Laptop keyboard broken and hinge cracked.']);
        $this->assertSame('hardware', $job->required_skill, 'skill inferred from device + issue');

        $quote = $this->jobs->issueInitialQuote($job, $this->manager, $this->quoteLines());

        // Native Invoice Ninja approval (same path as the client portal "Approve" button).
        $quote->fresh()->service()->approve($this->client->contacts()->first())->save();

        $job->refresh();
        $this->assertSame('assigned', $job->status);
        $this->assertSame($free->id, $job->assigned_user_id);
        $this->assertSame('auto', $job->assignment_mode);
        $this->assertTrue(OpsNotification::where('recipient_id', $free->id)->where('type', 'job_assigned')->exists(), 'technician notified');
        $this->assertTrue(OpsNotification::where('recipient_id', $this->manager->id)->where('type', 'quote_approved')->exists(), 'manager notified');
    }

    public function test_no_eligible_technician_leaves_job_approved_and_manager_can_override(): void
    {
        $full = $this->makeStaff('technician', ['hardware'], max: 1);
        $other = $this->newJob();
        $other->forceFill(['status' => 'in_progress', 'assigned_user_id' => $full->id])->save();

        $job = $this->newJob();
        $job->forceFill(['status' => 'approved', 'required_skill' => 'hardware'])->save();

        $this->assertNull($this->jobs->autoAssign($job, $this->manager));
        $this->assertSame('approved', $job->fresh()->status);

        $this->jobs->assignManual($job->fresh(), $full->id, $this->manager, 'Customer is a VIP');
        $job->refresh();
        $this->assertSame($full->id, $job->assigned_user_id);
        $this->assertSame('manual', $job->assignment_mode);
    }

    public function test_urgent_job_may_exceed_capacity_by_one(): void
    {
        $tech = $this->makeStaff('technician', ['hardware'], max: 1);
        $other = $this->newJob();
        $other->forceFill(['status' => 'in_progress', 'assigned_user_id' => $tech->id])->save();

        $job = $this->newJob();
        $job->forceFill(['status' => 'approved', 'priority' => 'urgent', 'required_skill' => 'hardware'])->save();

        $this->assertSame($tech->id, $this->jobs->autoAssign($job, $this->manager)?->id);
    }

    public function test_parts_are_deducted_atomically_and_insufficient_stock_is_rejected(): void
    {
        $tech = $this->makeStaff('technician');
        $ssd = $this->part('SSD-512', 3);

        $job = $this->newJob();
        $job->forceFill(['status' => 'in_progress', 'assigned_user_id' => $tech->id])->save();

        $this->jobs->addPart($job, $ssd, 2, $tech);
        $this->assertSame(1, (int) $ssd->fresh()->in_stock_quantity);

        $m = InventoryMovement::where('product_id', $ssd->id)->latest('id')->first();
        $this->assertSame('repair_usage', $m->type);
        $this->assertSame(-2, (int) $m->quantity);
        $this->assertSame(1, (int) $m->balance_after);

        try {
            $this->jobs->addPart($job, $ssd, 5, $tech);
            $this->fail('Expected insufficient stock');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Insufficient stock', $e->getMessage());
        }

        $this->assertSame(1, (int) $ssd->fresh()->in_stock_quantity, 'failed deduction must not change stock');
        $this->assertSame(1, $job->parts()->count());
    }

    public function test_revision_requires_customer_approval_and_rejection_resumes_original_scope(): void
    {
        $tech = $this->makeStaff('technician');
        $job = $this->newJob();
        $job->forceFill(['status' => 'in_progress', 'assigned_user_id' => $tech->id])->save();

        $rev = $this->jobs->requestRevision($job, $tech, [QuoteBuilder::line('LABOR', 'Replace hinge', 800, 1, '2')], 'Hinge cracked');
        $this->assertSame('awaiting_approval', $job->fresh()->status);
        $this->assertTrue($job->fresh()->hasPendingRevision());

        // Work cannot be completed while the customer has not decided.
        try {
            $this->jobs->complete($job->fresh(), $tech, 'done');
            $this->fail('Completion must be blocked while a revision is pending');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('revised quotation', $e->getMessage());
        }

        // Customer declines in the portal -> back to the originally approved scope.
        $rev->fresh()->service()->reject($this->client->contacts()->first(), 'Too expensive')->save();
        $this->assertSame('in_progress', $job->fresh()->status);
        $this->assertFalse($job->fresh()->hasPendingRevision());

        // A second revision approved by the customer resumes work.
        $rev2 = $this->jobs->requestRevision($job->fresh(), $tech, [QuoteBuilder::line('LABOR', 'Replace screen cable', 400, 1, '2')], 'Flickering screen');
        $rev2->fresh()->service()->approve($this->client->contacts()->first())->save();
        $this->assertSame('in_progress', $job->fresh()->status);
        $this->assertTrue(OpsNotification::where('recipient_id', $tech->id)->where('type', 'revision_approved')->exists());
    }

    public function test_rejected_initial_quote_cancels_job(): void
    {
        $job = $this->newJob();
        $quote = $this->jobs->issueInitialQuote($job, $this->manager, $this->quoteLines());
        $quote->fresh()->service()->reject($this->client->contacts()->first(), 'Not worth it')->save();

        $this->assertSame('cancelled', $job->fresh()->status);
        $this->assertSame('rejected', ServiceRequest::find($job->service_request_id)->status);
    }

    public function test_completion_notifies_accounting_and_generates_invoice_without_double_stock_deduction(): void
    {
        $this->company->track_inventory = true;
        $this->company->save();

        $tech = $this->makeStaff('technician');
        $accountant = $this->makeStaff('accounting');
        $ram = $this->part('RAM-8GB', 5, cost: 1200, price: 1800);

        $job = $this->newJob();
        $lines = array_merge($this->quoteLines(), [QuoteBuilder::productLine($ram, 1)]);
        $quote = $this->jobs->issueInitialQuote($job, $this->manager, $lines);
        $quote->fresh()->service()->approve($this->client->contacts()->first())->save();

        $job->refresh()->forceFill(['assigned_user_id' => $tech->id])->save();
        $this->jobs->start($job->fresh(), $tech);
        $this->jobs->addPart($job->fresh(), $ram, 1, $tech);
        $this->jobs->complete($job->fresh(), $tech, 'Replaced RAM, ran memtest.');

        $this->assertTrue(OpsNotification::where('recipient_id', $accountant->id)->where('type', 'job_completed')->exists());

        $this->actAs($accountant);
        $invoice = app(InvoiceGenerator::class)->verifyAndGenerate($job->fresh(), $accountant, false, false);

        $job->refresh();
        $this->assertSame($invoice->id, $job->invoice_id);
        $this->assertNotNull($job->completion_verified_at);
        $this->assertEquals(3100.0, (float) $invoice->amount, '300 + 2x500 + 1800');
        $this->assertSame(Invoice::STATUS_SENT, (int) $invoice->status_id);
        $this->assertSame(Quote::STATUS_CONVERTED, (int) $quote->fresh()->status_id);
        $this->assertSame($invoice->id, $quote->fresh()->invoice_id);
        $this->assertSame(4, (int) $ram->fresh()->in_stock_quantity, 'stock deducted exactly once (at time of use)');

        $warranty = Warranty::where('repair_job_id', $job->id)->first();
        $this->assertNotNull($warranty, 'warranty issued on verified paid repair');
        $this->assertSame($invoice->id, $warranty->invoice_id);

        // Cannot invoice twice.
        $this->expectException(ValidationException::class);
        app(InvoiceGenerator::class)->verifyAndGenerate($job->fresh(), $accountant, false, false);
    }

    public function test_unquoted_parts_are_only_billed_when_accounting_opts_in(): void
    {
        $tech = $this->makeStaff('technician');
        $accountant = $this->makeStaff('accounting');
        $fan = $this->part('FAN-01', 4, price: 650);

        $job = $this->newJob();
        $quote = $this->jobs->issueInitialQuote($job, $this->manager, $this->quoteLines());
        $quote->fresh()->service()->approve($this->client->contacts()->first())->save();
        $job->refresh()->forceFill(['assigned_user_id' => $tech->id, 'status' => 'in_progress'])->save();
        $this->jobs->addPart($job->fresh(), $fan, 1, $tech);
        $this->jobs->complete($job->fresh(), $tech, 'Replaced fan.');

        $billing = app(InvoiceGenerator::class);
        $this->assertCount(1, $billing->unquotedParts($job->fresh()));

        $invoice = $billing->verifyAndGenerate($job->fresh(), $accountant, true, false);
        $this->assertEquals(1950.0, (float) $invoice->amount, '1300 approved + 650 opted-in part');
    }

    public function test_invalid_manual_transition_is_rejected(): void
    {
        $job = $this->newJob();
        $this->expectException(ValidationException::class);
        $this->jobs->transition($job, 'completed', $this->manager);
    }
}
