<?php

namespace Tests\Feature\PCFix;

use App\Models\CompanyUser;
use App\Models\Expense;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Models\Ops\InventoryMovement;
use App\Models\Ops\OpsNotification;
use App\Models\Ops\PurchaseRequest;
use App\Models\Ops\RepairJob;
use App\Models\Ops\ServiceRequest;
use App\Models\Ops\TechnicianProfile;
use App\Models\Ops\Warranty;
use App\Models\Ops\WarrantyClaim;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Session;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * Drives every console form over HTTP exactly as the browser posts it
 * (field names, hashed ids, line-editor arrays), end to end.
 */
class ConsoleActionsTest extends TestCase
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

    public function test_full_repair_lifecycle_through_console_forms(): void
    {
        $manager = $this->makeStaff('service_manager');
        $tech = $this->makeStaff('technician', ['hardware', 'software']);
        $accountant = $this->makeStaff('accounting');
        $ssd = $this->part('SSD-1TB', 4, price: 4200);
        $contactEmail = $this->client->contacts()->first()->email;

        // 1. Walk-in request entered by staff (matches existing customer by email).
        $this->console($manager)->post(route('ops.service_requests.store'), $this->requestPayload(['channel' => 'walk_in', 'email' => $contactEmail, 'priority' => 'high']))
            ->assertSessionHasNoErrors()->assertRedirect();
        $sr = ServiceRequest::where('company_id', $this->company->id)->latest('id')->first();
        $this->assertSame('walk_in', $sr->channel);
        $this->assertSame($this->client->id, $sr->client_id);

        // 2. Edit the request.
        $this->console($manager)->put(route('ops.service_requests.update', $sr->hashed_id), $this->requestPayload(['email' => $contactEmail, 'device_model' => 'IdeaPad 5']))
            ->assertSessionHasNoErrors();
        $this->assertSame('IdeaPad 5', $sr->fresh()->device_model);

        // 3. Evaluate & quote with the line editor (fee + labor + inventory part).
        $this->console($manager)->post(route('ops.service_requests.quote', $sr->hashed_id), [
            'diagnosis' => 'Failing SSD, needs replacement and OS reinstall.',
            'priority' => 'high',
            'required_skill' => 'hardware',
            'lines' => [
                ['kind' => 'fee', 'description' => 'Diagnostic fee', 'quantity' => 1, 'price' => 300],
                ['kind' => 'labor', 'description' => 'Labor', 'quantity' => 2, 'price' => 500],
                ['kind' => 'part', 'product' => $ssd->hashed_id, 'quantity' => 1, 'price' => ''],
                ['kind' => 'labor', 'description' => 'ignored zero qty', 'quantity' => 0, 'price' => 1],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $job = RepairJob::where('service_request_id', $sr->id)->first();
        $this->assertSame('awaiting_approval', $job->status);
        $this->assertEquals(5500.0, (float) $job->quote->amount, '300 + 1000 + 4200 (part price defaulted)');

        // 4. Customer approves in the portal (native flow) -> auto-assigned.
        $job->quote->service()->approve($this->client->contacts()->first())->save();
        $this->assertSame($tech->id, $job->fresh()->assigned_user_id);

        // 5. Manager re-prioritises and re-assigns manually.
        $this->console($manager)->post(route('ops.repair_jobs.priority', $job->hashed_id), ['priority' => 'urgent', 'required_skill' => 'hardware'])->assertSessionHasNoErrors();
        $this->console($manager)->post(route('ops.repair_jobs.assign', $job->hashed_id), ['technician' => $tech->id, 'reason' => 'Specialist'])->assertSessionHasNoErrors();
        $this->assertSame('urgent', $job->fresh()->priority);

        // 6. Technician works the job.
        $this->console($tech)->post(route('ops.repair_jobs.status', $job->hashed_id), ['status' => 'in_progress'])->assertSessionHasNoErrors();
        $this->console($tech)->post(route('ops.repair_jobs.note', $job->hashed_id), ['type' => 'diagnosis', 'note' => 'SMART errors confirmed', 'customer_visible' => 1])->assertSessionHasNoErrors();
        $this->console($tech)->post(route('ops.repair_jobs.parts.add', $job->hashed_id), ['product' => $ssd->hashed_id, 'quantity' => 2])->assertSessionHasNoErrors();
        $this->assertSame(2, (int) $ssd->fresh()->in_stock_quantity);

        $extra = $job->parts()->latest('id')->first();
        $this->console($tech)->delete(route('ops.repair_jobs.parts.remove', [$job->hashed_id, $extra->hashed_id]))->assertSessionHasNoErrors();
        $this->assertSame(4, (int) $ssd->fresh()->in_stock_quantity, 'returned to stock');
        $this->console($tech)->post(route('ops.repair_jobs.parts.add', $job->hashed_id), ['product' => $ssd->hashed_id, 'quantity' => 1])->assertSessionHasNoErrors();

        // Over-stock request is rejected with a friendly validation error.
        $this->console($tech)->post(route('ops.repair_jobs.parts.add', $job->hashed_id), ['product' => $ssd->hashed_id, 'quantity' => 50])->assertSessionHasErrors('quantity');

        // 7. Revision for extra work, approved by customer.
        $this->console($tech)->post(route('ops.repair_jobs.revision', $job->hashed_id), [
            'reason' => 'Fan is also noisy',
            'lines' => [['kind' => 'labor', 'description' => 'Fan cleaning', 'quantity' => 1, 'price' => 250]],
        ])->assertSessionHasNoErrors();
        $this->assertSame('awaiting_approval', $job->fresh()->status);
        $job->fresh()->linkedQuotes('revision')->first()->service()->approve($this->client->contacts()->first())->save();
        $this->assertSame('in_progress', $job->fresh()->status);

        // 8. Complete -> accounting verifies and generates invoice from the console.
        $this->console($tech)->post(route('ops.repair_jobs.complete', $job->hashed_id), ['summary' => 'Replaced SSD, cleaned fan, reinstalled OS.'])->assertSessionHasNoErrors();
        $this->assertSame('completed', $job->fresh()->status);

        $this->console($accountant)->get(route('ops.billing.index'))->assertOk()->assertSee($job->job_number);
        $this->console($accountant)->post(route('ops.billing.generate', $job->hashed_id), ['send' => 0])->assertSessionHasNoErrors();

        $job->refresh();
        $this->assertNotNull($job->invoice_id);
        $this->assertEquals(5750.0, (float) $job->invoice->amount, 'initial 5500 + approved revision 250');
        $this->assertTrue(Warranty::where('repair_job_id', $job->id)->exists());

        // 9. Customer sees the repair in the portal.
        $this->actingAs($this->client->contacts()->first(), 'contact')
            ->get(route('client.ops.repairs.show', $job->hashed_id))->assertOk()->assertSee('SMART errors confirmed');

        // 10. Notifications inbox.
        $this->console($accountant)->post(route('ops.notifications.read'))->assertSessionHasNoErrors();
        $this->assertSame(0, OpsNotification::where('recipient_id', $accountant->id)->where('channel', 'database')->whereNull('read_at')->count());
    }

    public function test_intake_review_actions_and_cancel(): void
    {
        $manager = $this->makeStaff('service_manager');

        $this->post(route('ops.public.store', $this->company->company_key), $this->requestPayload(['email' => 'nobody@example.com', 'customer_name' => 'Pedro Penduko', 'phone' => '0922 333 4444']));
        $sr = ServiceRequest::where('email', 'nobody@example.com')->first();
        $this->assertNull($sr->client_id);

        $this->console($manager)->post(route('ops.service_requests.link_client', $sr->hashed_id), ['client' => $this->client->hashed_id])->assertSessionHasNoErrors();
        $this->assertSame($this->client->id, $sr->fresh()->client_id);

        $this->post(route('ops.public.store', $this->company->company_key), $this->requestPayload(['email' => 'second@example.com', 'customer_name' => 'Totally New', 'phone' => '0933 111 2222']));
        $sr2 = ServiceRequest::where('email', 'second@example.com')->first();
        $this->console($manager)->post(route('ops.service_requests.create_client', $sr2->hashed_id))->assertSessionHasNoErrors();
        $this->assertNotNull($sr2->fresh()->client_id);

        $this->console($manager)->post(route('ops.service_requests.cancel', $sr2->hashed_id), ['reason' => 'Customer withdrew'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $sr2->fresh()->status);
    }

    public function test_warranty_forms(): void
    {
        $manager = $this->makeStaff('service_manager');
        $this->makeStaff('technician');

        // A completed paid repair with a warranty.
        $job = new RepairJob();
        $job->company_id = $this->company->id;
        $job->client_id = $this->client->id;
        $job->job_number = 'RJ-T-1';
        $job->status = 'completed';
        $job->save();
        $this->actAs($manager);
        $w = app(\App\Services\Ops\WarrantyService::class)->createForJob($job, null, $manager);

        $this->console($manager)->put(route('ops.warranty.update', $w->hashed_id), [
            'coverage_type' => 'parts', 'end_date' => now()->addDays(120)->toDateString(), 'covered_items' => 'SSD', 'exclusions' => 'Water damage',
        ])->assertSessionHasNoErrors();
        $this->assertSame('parts', $w->fresh()->coverage_type);

        $this->console($manager)->post(route('ops.warranty.claims.store'), [
            'client' => $this->client->hashed_id, 'issue_description' => 'Drive not detected again',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $claim = WarrantyClaim::where('client_id', $this->client->id)->latest('id')->first();
        $this->assertTrue($claim->eligibility['eligible']);

        $this->console($manager)->get(route('ops.warranty.claims.show', $claim->hashed_id))->assertOk();
        $this->console($manager)->post(route('ops.warranty.claims.decide', $claim->hashed_id), ['decision' => 'approve', 'notes' => 'Covered part failed'])->assertSessionHasNoErrors();
        $this->assertSame('in_repair', $claim->fresh()->status);
        $this->assertTrue($claim->fresh()->repairJob->is_warranty_job);

        // Portal claim (no warranty selected -> automatic lookup) and void.
        $this->actingAs($this->client->contacts()->first(), 'contact')
            ->post(route('client.ops.warranty.claims.store'), ['serial_number' => 'NOPE-123', 'issue_description' => 'Keyboard keys not working'])
            ->assertSessionHasNoErrors()->assertRedirect(route('client.ops.warranty.index'));
        $this->assertSame('portal', WarrantyClaim::latest('id')->first()->submitted_via);

        $this->console($manager)->put(route('ops.warranty.update', $w->hashed_id), [
            'coverage_type' => 'parts', 'end_date' => now()->addDays(120)->toDateString(), 'void' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame('void', $w->fresh()->status);
    }

    public function test_inventory_and_procurement_forms(): void
    {
        $inv = $this->makeStaff('inventory');
        $mgmt = $this->makeStaff('management');
        $acct = $this->makeStaff('accounting');

        // Supplier create / update (Invoice Ninja vendor).
        $this->console($inv)->post(route('ops.suppliers.store'), ['name' => 'Mindoro PC Parts', 'contact_first_name' => 'Ana', 'email' => 'sales@mindoro-parts.test', 'phone' => '043 288 1234', 'products_supplied' => 'SSD, RAM'])
            ->assertSessionHasNoErrors();
        $vendor = Vendor::where('company_id', $this->company->id)->where('name', 'Mindoro PC Parts')->first();
        $this->assertSame('sales@mindoro-parts.test', $vendor->contacts()->first()->email);
        $this->console($inv)->put(route('ops.suppliers.update', $vendor->hashed_id), ['name' => 'Mindoro PC Parts Inc.', 'email' => 'orders@mindoro-parts.test'])->assertSessionHasNoErrors();
        $this->assertSame('orders@mindoro-parts.test', $vendor->contacts()->first()->fresh()->email);
        $this->console($inv)->get(route('ops.suppliers.show', $vendor->hashed_id))->assertOk();

        // Inventory item create with opening stock, update, adjust, count.
        $this->console($inv)->post(route('ops.inventory.store'), [
            'product_key' => 'RAM-DDR4-8', 'notes' => '8GB DDR4 SODIMM', 'category' => 'Memory', 'storage_location' => 'HQ B2',
            'cost' => 1100, 'price' => 1650, 'minimum' => 3, 'vendor' => $vendor->hashed_id, 'opening_stock' => 10,
        ])->assertSessionHasNoErrors();
        $item = Product::where('company_id', $this->company->id)->where('product_key', 'RAM-DDR4-8')->first();
        $this->assertTrue((bool) $item->track_stock);
        $this->assertSame(10, (int) $item->in_stock_quantity);
        $this->assertSame($vendor->id, $item->vendor_id);

        $this->console($inv)->post(route('ops.inventory.store'), ['product_key' => 'RAM-DDR4-8', 'cost' => 1, 'price' => 1, 'minimum' => 1])->assertSessionHasErrors('product_key');

        // The edit form posts every field (prefilled with current values).
        $this->console($inv)->put(route('ops.inventory.update', $item->hashed_id), [
            'product_key' => 'RAM-DDR4-8', 'notes' => '8GB DDR4', 'category' => 'Memory', 'storage_location' => 'HQ B2',
            'cost' => 1150, 'price' => 1700, 'minimum' => 4, 'vendor' => $vendor->hashed_id,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Memory', $item->fresh()->category);
        $this->assertSame($vendor->id, $item->fresh()->vendor_id);
        $this->console($inv)->post(route('ops.inventory.adjust', $item->hashed_id), ['mode' => 'stock_out', 'quantity' => 3, 'notes' => 'Damaged in transit'])->assertSessionHasNoErrors();
        $this->console($inv)->post(route('ops.inventory.adjust', $item->hashed_id), ['mode' => 'count', 'quantity' => 6, 'notes' => 'Monthly count'])->assertSessionHasNoErrors();
        $this->assertSame(6, (int) $item->fresh()->in_stock_quantity);
        $this->assertSame(3, InventoryMovement::where('product_id', $item->id)->count(), 'opening, stock out, count');

        // Purchase request -> approval -> PO -> receive -> expense.
        $this->console($inv)->post(route('ops.purchase_requests.store'), [
            'type' => 'spare_parts', 'vendor' => $vendor->hashed_id, 'justification' => 'Restock RAM',
            'items' => [
                ['product' => $item->hashed_id, 'quantity' => 5, 'unit_cost' => 1000],
                ['description' => 'Thermal paste', 'quantity' => 2, 'unit_cost' => 150],
                ['description' => '', 'quantity' => ''],
            ],
        ])->assertSessionHasNoErrors();
        $pr = PurchaseRequest::where('company_id', $this->company->id)->where('source', 'manual')->latest('id')->first();
        $this->assertCount(2, $pr->items);

        $this->console($inv)->post(route('ops.purchase_requests.decide', $pr->hashed_id), ['decision' => 'approve'])->assertForbidden();
        $this->console($mgmt)->post(route('ops.purchase_requests.decide', $pr->hashed_id), ['decision' => 'reject'])->assertSessionHasErrors('notes');
        $this->console($mgmt)->post(route('ops.purchase_requests.decide', $pr->hashed_id), ['decision' => 'approve', 'notes' => 'Go'])->assertSessionHasNoErrors();

        $this->console($inv)->post(route('ops.purchase_requests.order', $pr->hashed_id))->assertSessionHasNoErrors();
        $this->assertNotNull($pr->fresh()->purchase_order_id);
        $this->console($inv)->get(route('ops.purchase_orders.index'))->assertOk();

        $items = $pr->items()->get();
        $this->console($inv)->post(route('ops.purchase_requests.receive', $pr->hashed_id), ['received' => [$items[0]->id => 5, $items[1]->id => 2]])->assertSessionHasNoErrors();
        $this->assertSame('received', $pr->fresh()->status);
        $this->assertSame(11, (int) $item->fresh()->in_stock_quantity);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, (int) $pr->fresh()->purchaseOrder->status_id);

        $this->console($acct)->post(route('ops.purchase_requests.expense', $pr->hashed_id), ['amount' => 5300, 'date' => now()->toDateString(), 'reference' => 'SI-0091'])->assertSessionHasNoErrors();
        $this->assertEquals(5300.0, (float) Expense::find($pr->fresh()->expense_id)->amount);

        // Inventory reports (CSV).
        $stock = $this->console($inv)->get(route('ops.inventory.export'));
        $stock->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $stock->streamedContent();
        $this->assertStringContainsString('RAM-DDR4-8', $csv);
        $this->assertStringContainsString('"Mindoro PC Parts Inc."', $csv);
        $this->assertMatchesRegularExpression('/RAM-DDR4-8,.*,11,4,OK,1000/', $csv, 'qty, minimum, status, latest cost');

        $moves = $this->console($inv)->get(route('ops.inventory.export', ['report' => 'movements', 'type' => 'purchase_receipt']))->streamedContent();
        $this->assertStringContainsString('Purchase Receipt', $moves);
        $this->assertStringNotContainsString('Opening stock', $moves, 'type filter applied');

        // Formula-injection guard on text cells.
        Product::whereKey($item->id)->update(['notes' => '=HYPERLINK("http://evil")']);
        $this->assertStringContainsString("'=HYPERLINK", $this->console($inv)->get(route('ops.inventory.export'))->streamedContent());

        // Archive from stock tracking keeps the product.
        $this->console($inv)->post(route('ops.inventory.archive', $item->hashed_id))->assertSessionHasNoErrors();
        $this->assertFalse((bool) $item->fresh()->track_stock);
        $this->assertNotNull(Product::find($item->id));
    }

    public function test_admin_role_and_technician_forms(): void
    {
        $staff = $this->makeStaff('none');
        $cu = CompanyUser::where('user_id', $staff->id)->where('company_id', $this->company->id)->first();

        $this->console($this->user)->put(route('ops.admin.users.update', $staff->id), ['ops_role' => 'technician', 'apply_native' => 1])->assertSessionHasNoErrors();
        $cu->refresh();
        $this->assertSame('technician', $cu->ops_role);
        $this->assertStringContainsString('view_product', $cu->permissions);
        $this->assertTrue(TechnicianProfile::where('user_id', $staff->id)->exists(), 'technician profile created');

        $this->console($this->user)->put(route('ops.technicians.update', $staff->id), [
            'skills' => ['network', 'printer'], 'branch' => 'Calapan City', 'is_available' => 1, 'max_active_jobs' => 3,
        ])->assertSessionHasNoErrors();
        $p = TechnicianProfile::where('user_id', $staff->id)->first();
        $this->assertSame(['network', 'printer'], $p->skills);
        $this->assertSame(3, (int) $p->max_active_jobs);

        // An owner's role cannot be changed; users cannot change their own role.
        $this->console($this->user)->put(route('ops.admin.users.update', $this->user->id), ['ops_role' => 'technician'])->assertSessionHasErrors('role');
        $this->console($staff)->put(route('ops.admin.users.update', $staff->id), ['ops_role' => 'administrator'])->assertForbidden();
    }
}
