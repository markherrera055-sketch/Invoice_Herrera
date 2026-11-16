<?php

namespace Tests\Feature\PCFix;

use App\Models\Expense;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Models\Ops\Device;
use App\Models\Ops\InventoryMovement;
use App\Models\Ops\OpsNotification;
use App\Models\Ops\PurchaseRequest;
use App\Models\Ops\RepairJob;
use App\Models\Ops\Warranty;
use App\Services\Ops\InventoryService;
use App\Services\Ops\InvoiceGenerator;
use App\Services\Ops\PurchaseRequestService;
use App\Services\Ops\RepairJobService;
use App\Services\Ops\WarrantyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * Module 4 (warranty, P7) and Module 5 (inventory, suppliers, purchasing, P8/P9).
 */
class WarrantyAndInventoryTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;
    use PCFixTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Session::start();
        Model::reguard();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->makeTestData();
    }

    private function warranty(int $daysLeft, string $coverage = 'parts_and_labor', string $serial = 'WSN-001'): Warranty
    {
        $device = new Device();
        $device->company_id = $this->company->id;
        $device->client_id = $this->client->id;
        $device->fill(['device_type' => 'Laptop', 'brand' => 'Acer', 'model' => 'Aspire 5', 'serial_number' => $serial])->save();

        $w = new Warranty();
        $w->company_id = $this->company->id;
        $w->client_id = $this->client->id;
        $w->device_id = $device->id;
        $w->warranty_number = 'WR-TEST-' . $serial;
        $w->coverage_type = $coverage;
        $w->start_date = now()->subDays(60);
        $w->end_date = now()->addDays($daysLeft);
        $w->status = 'active';
        $w->save();

        return $w;
    }

    public function test_eligibility_check_covered_expired_and_missing(): void
    {
        $svc = app(WarrantyService::class);

        $this->warranty(45, serial: 'COVERED-1');
        $this->warranty(-3, serial: 'EXPIRED-1');

        $this->assertTrue($svc->checkEligibility($this->company->id, $this->client->id, 'COVERED-1')['eligible']);

        $expired = $svc->checkEligibility($this->company->id, $this->client->id, 'EXPIRED-1');
        $this->assertFalse($expired['eligible']);
        $this->assertStringContainsString('expired', $expired['reason']);

        $none = $svc->checkEligibility($this->company->id, $this->client->id, 'UNKNOWN');
        $this->assertFalse($none['eligible']);
        $this->assertNull($none['warranty_id']);
    }

    public function test_status_is_computed_from_dates(): void
    {
        $this->assertSame('active', $this->warranty(200, serial: 'A')->computeStatus());
        $this->assertSame('expiring_soon', $this->warranty(10, serial: 'B')->computeStatus());
        $this->assertSame('expired', $this->warranty(-1, serial: 'C')->computeStatus());
    }

    public function test_approved_claim_creates_free_warranty_repair_with_zero_charges(): void
    {
        $tech = $this->makeStaff('technician');
        $manager = $this->makeStaff('service_manager');
        $accountant = $this->makeStaff('accounting');
        $this->actAs($manager);

        $w = $this->warranty(60, 'parts_and_labor', 'CLAIM-1');
        $part = $this->part('BATT-01', 3, price: 2200);

        $svc = app(WarrantyService::class);
        $claim = $svc->submitClaim($this->company, $this->client, $this->client->contacts()->first(), ['serial_number' => 'CLAIM-1', 'issue_description' => 'Battery not charging again'], 'portal');
        $this->assertTrue($claim->eligibility['eligible']);
        $this->assertTrue(OpsNotification::where('recipient_id', $manager->id)->where('type', 'warranty_claim')->exists());

        $claim = $svc->decide($claim, $manager, true, 'Battery cell failure, covered.');
        $job = $claim->repairJob;

        $this->assertTrue($job->is_warranty_job);
        $this->assertSame('in_repair', $claim->status);
        $this->assertSame('claimed', $w->fresh()->status);
        $this->assertSame($tech->id, $job->fresh()->assigned_user_id, 'auto-assigned');

        $jobs = app(RepairJobService::class);
        $jobs->start($job->fresh(), $tech);
        $p = $jobs->addPart($job->fresh(), $part, 1, $tech);
        $this->assertTrue($p->is_warranty_covered);
        $jobs->complete($job->fresh(), $tech, 'Replaced battery under warranty.');

        $invoice = app(InvoiceGenerator::class)->verifyAndGenerate($job->fresh(), $accountant, false, false);

        $this->assertNull($invoice, 'fully covered warranty repair raises no invoice');
        $this->assertSame('completed', $claim->fresh()->status);
        $this->assertNotNull($job->fresh()->completion_verified_at);
        $this->assertSame(2, (int) $part->fresh()->in_stock_quantity);
    }

    public function test_rejected_claim_opens_paid_job_and_expired_claim_cannot_be_approved(): void
    {
        $manager = $this->makeStaff('service_manager');
        $this->actAs($manager);
        $this->warranty(-10, serial: 'OLD-1');

        $svc = app(WarrantyService::class);
        $claim = $svc->submitClaim($this->company, $this->client, null, ['serial_number' => 'OLD-1', 'issue_description' => 'Screen flickers'], 'staff', $manager);
        $this->assertFalse($claim->eligibility['eligible']);

        try {
            $svc->decide($claim, $manager, true, 'try approve');
            $this->fail('Approving an expired warranty must fail');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }

        $claim = $svc->decide($claim->fresh(), $manager, false, 'Warranty expired');
        $this->assertSame('rejected', $claim->status);
        $this->assertFalse($claim->repairJob->is_warranty_job);
        $this->assertSame('pending', $claim->repairJob->status, 'ready for a paid quotation');
    }

    public function test_daily_job_sends_expiry_notice_once(): void
    {
        $this->warranty(5, serial: 'SOON-1');
        $svc = app(WarrantyService::class);

        $first = $svc->runDaily();
        $second = $svc->runDaily();

        $this->assertSame(1, $first['expiry_notices']);
        $this->assertSame(0, $second['expiry_notices']);
        $this->assertSame(1, OpsNotification::where('company_id', $this->company->id)->where('type', 'warranty_expiring')->where('channel', 'mail')->count());
    }

    public function test_low_stock_raises_one_purchase_request_and_notifies_inventory_staff(): void
    {
        $inventoryStaff = $this->makeStaff('inventory');
        $vendor = Vendor::factory()->create(['company_id' => $this->company->id, 'user_id' => $this->user->id]);
        $p = $this->part('HDMI-CBL', 4, min: 3);
        $p->vendor_id = $vendor->id;
        $p->saveQuietly();

        $inv = app(InventoryService::class);
        $inv->move($p, -2, 'stock_out', $this->user->id, 'sold');   // 2 left -> low
        $inv->move($p, -1, 'stock_out', $this->user->id, 'sold');   // 1 left -> still low, no 2nd request

        $requests = PurchaseRequest::where('company_id', $this->company->id)->whereHas('items', fn ($q) => $q->where('product_id', $p->id))->get();
        $this->assertCount(1, $requests);
        $this->assertSame('low_stock', $requests->first()->source);
        $this->assertSame($vendor->id, $requests->first()->vendor_id);
        $this->assertTrue(OpsNotification::where('recipient_id', $inventoryStaff->id)->where('type', 'low_stock')->exists());
        $this->assertSame(1, OpsNotification::where('recipient_id', $inventoryStaff->id)->where('type', 'low_stock')->where('channel', 'mail')->count(), 'one email per item per day');
    }

    public function test_purchase_request_approval_order_receive_and_expense(): void
    {
        $requester = $this->makeStaff('inventory');
        $manager = $this->makeStaff('management');
        $accountant = $this->makeStaff('accounting');
        $vendor = Vendor::factory()->create(['company_id' => $this->company->id, 'user_id' => $this->user->id]);
        $ram = $this->part('RAM-16', 0, min: 2, cost: 2500);

        $svc = app(PurchaseRequestService::class);

        $this->actAs($requester);
        $pr = $svc->create($this->company, $requester, [
            'type' => 'spare_parts',
            'vendor_id' => $vendor->id,
            'justification' => 'Restock RAM',
            'items' => [['product_id' => $ram->id, 'description' => '16GB DDR4', 'quantity' => 5, 'unit_cost' => 2400]],
        ]);
        $this->assertTrue(OpsNotification::where('recipient_id', $manager->id)->where('type', 'purchase_request')->exists());

        // Segregation of duties: the requester cannot approve their own request.
        $this->expectExceptionOnce(fn () => $svc->decide($pr, $requester, true, null));

        $this->actAs($manager);
        $svc->decide($pr, $manager, true, 'OK');
        $this->assertSame('approved', $pr->fresh()->status);

        $this->actAs($requester);
        $po = $svc->createPurchaseOrder($pr->fresh(), $requester);
        $this->assertInstanceOf(PurchaseOrder::class, $po);
        $this->assertSame('ordered', $pr->fresh()->status);
        $this->assertEquals(12000.0, (float) $po->amount, 'PO totals from Invoice Ninja calc');

        $item = $pr->items()->first();
        $svc->receive($pr->fresh(), $requester, [$item->id => 3]);
        $this->assertSame(3, (int) $ram->fresh()->in_stock_quantity);
        $this->assertSame('ordered', $pr->fresh()->status, 'partially received');

        $svc->receive($pr->fresh(), $requester, [$item->id => 2]);
        $this->assertSame(5, (int) $ram->fresh()->in_stock_quantity);
        $this->assertSame('received', $pr->fresh()->status);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, (int) $po->fresh()->status_id);
        $this->assertSame(2, InventoryMovement::where('product_id', $ram->id)->where('type', 'purchase_receipt')->count());
        $this->assertEquals(2400.0, (float) $ram->fresh()->cost, 'latest purchase cost recorded');

        $this->actAs($accountant);
        $expense = $svc->recordExpense($pr->fresh(), $accountant, ['amount' => 12000, 'date' => now()->toDateString()]);
        $this->assertSame($vendor->id, $expense->vendor_id);
        $this->assertSame($expense->id, $pr->fresh()->expense_id);
        $this->assertTrue(Expense::whereKey($expense->id)->exists());
    }

    public function test_cannot_receive_more_than_ordered(): void
    {
        $manager = $this->makeStaff('management');
        $p = $this->part('X-1', 0);
        $svc = app(PurchaseRequestService::class);
        $pr = $svc->create($this->company, $this->user, ['items' => [['product_id' => $p->id, 'description' => 'X', 'quantity' => 2]]]);
        $this->actAs($manager);
        $svc->decide($pr, $manager, true, null);

        $this->expectException(ValidationException::class);
        $svc->receive($pr->fresh(), $manager, [$pr->items()->first()->id => 3]);
    }

    private function expectExceptionOnce(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a validation exception');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }
    }
}
