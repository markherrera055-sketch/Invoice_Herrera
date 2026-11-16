<?php

namespace Tests\Feature\PCFix;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Ops\OpsAuditLog;
use App\Models\Ops\PurchaseRequest;
use App\Models\Ops\RepairJob;
use App\Models\Ops\ServiceRequest;
use App\Services\Ops\DashboardMetrics;
use App\Services\Ops\PurchaseRequestService;
use App\Services\Ops\QuoteBuilder;
use App\Services\Ops\RepairJobService;
use App\Services\Ops\ServiceRequestService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * Role-based access control (backend enforced), IDOR protection, console pages,
 * client portal scoping, JSON API and dashboard metric correctness.
 */
class AccessControlAndUiTest extends TestCase
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

    private function jobFor(?int $techId, string $status = 'in_progress'): RepairJob
    {
        [$sr] = app(ServiceRequestService::class)->submit($this->company, $this->requestPayload(), 'portal', $this->client->contacts()->first());
        $job = app(RepairJobService::class)->createFromServiceRequest($sr, $this->user);
        $job->forceFill(['status' => $status, 'assigned_user_id' => $techId])->save();

        return $job;
    }

    public function test_login_uses_invoice_ninja_credentials_and_writes_audit_log(): void
    {
        auth()->guard('user')->logout(); // MockAccountData signs the user in; start as a guest.

        $this->get(route('ops.login'))->assertOk()->assertSee('Sign in');
        $this->get(route('ops.dashboard'))->assertRedirect(route('ops.login'));

        $this->post(route('ops.login.submit'), ['email' => $this->user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');

        // MockAccountData sets this password on its user.
        $this->post(route('ops.login.submit'), ['email' => $this->user->email, 'password' => 'ALongAndBriliantPassword'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('ops.dashboard'));

        $this->assertAuthenticatedAs($this->user, 'user');
        $this->assertTrue(OpsAuditLog::where('action', 'auth.login')->where('user_id', $this->user->id)->exists());
    }

    public function test_every_console_page_renders_for_an_administrator(): void
    {
        $tech = $this->makeStaff('technician');
        $job = $this->jobFor($tech->id);
        $sr = ServiceRequest::find($job->service_request_id);
        $part = $this->part('PAGE-1', 1, min: 3);
        $this->actAs($this->user);
        $pr = app(PurchaseRequestService::class)->create($this->company, $this->user, ['items' => [['product_id' => $part->id, 'description' => 'x', 'quantity' => 1]]]);

        $pages = [
            route('ops.dashboard'), route('ops.notifications'),
            route('ops.customers.index'), route('ops.customers.index', ['view' => 'duplicates']), route('ops.customers.show', $this->client->hashed_id),
            route('ops.service_requests.index'), route('ops.service_requests.create'), route('ops.service_requests.show', $sr->hashed_id),
            route('ops.repair_jobs.index'), route('ops.repair_jobs.show', $job->hashed_id), route('ops.repair_jobs.history'), route('ops.technicians.index'),
            route('ops.billing.index'),
            route('ops.warranty.index'), route('ops.warranty.claims.index'), route('ops.warranty.check', ['serial' => 'X']),
            route('ops.inventory.index'), route('ops.inventory.create'), route('ops.inventory.show', $part->hashed_id), route('ops.inventory.edit', $part->hashed_id),
            route('ops.inventory.movements'), route('ops.inventory.low'),
            route('ops.suppliers.index'), route('ops.suppliers.create'),
            route('ops.purchase_requests.index'), route('ops.purchase_requests.create'), route('ops.purchase_requests.show', $pr->hashed_id), route('ops.purchase_orders.index'),
            route('ops.finance.expenses'), route('ops.finance.reports'), route('ops.finance.reports', ['period' => 'this_month', 'grain' => 'day']),
            route('ops.admin.users'), route('ops.admin.settings'), route('ops.admin.audit'),
        ];

        foreach ($pages as $url) {
            $response = $this->console($this->user)->get($url);
            $e = $response->exception;
            $this->assertSame(200, $response->status(), "GET {$url}" . ($e ? ' - ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() : ''));
        }

        $this->console($this->user)->get(route('ops.finance.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_technician_sees_only_assigned_jobs_and_cannot_reach_finance(): void
    {
        $tech = $this->makeStaff('technician');
        $other = $this->makeStaff('technician');
        $mine = $this->jobFor($tech->id);
        $theirs = $this->jobFor($other->id);

        $this->console($tech)->get(route('ops.repair_jobs.show', $mine->hashed_id))->assertOk();
        $this->console($tech)->get(route('ops.repair_jobs.show', $theirs->hashed_id))->assertForbidden();
        $this->console($tech)->post(route('ops.repair_jobs.complete', $theirs->hashed_id), ['summary' => 'not mine'])->assertForbidden();
        $this->assertSame('in_progress', $theirs->fresh()->status);

        $this->console($tech)->get(route('ops.repair_jobs.index'))->assertOk()->assertSee($mine->job_number)->assertDontSee($theirs->job_number);

        foreach (['ops.billing.index', 'ops.finance.reports', 'ops.admin.users', 'ops.service_requests.index', 'ops.suppliers.index'] as $route) {
            $this->console($tech)->get(route($route))->assertForbidden();
        }

        // Cancelling requires the manager ability even on your own job.
        $this->console($tech)->post(route('ops.repair_jobs.status', $mine->hashed_id), ['status' => 'cancelled', 'note' => 'x'])->assertForbidden();
    }

    public function test_role_matrix_is_enforced_on_the_server(): void
    {
        $accounting = $this->makeStaff('accounting');
        $inventory = $this->makeStaff('inventory');
        $noRole = $this->makeStaff('none');

        $this->console($accounting)->get(route('ops.billing.index'))->assertOk();
        $this->console($accounting)->get(route('ops.finance.reports'))->assertOk();
        $this->console($accounting)->get(route('ops.admin.users'))->assertForbidden();
        $this->console($accounting)->post(route('ops.inventory.store'), [])->assertForbidden();

        $this->console($inventory)->get(route('ops.inventory.index'))->assertOk();
        $this->console($inventory)->get(route('ops.billing.index'))->assertForbidden();

        $this->console($noRole)->get(route('ops.dashboard'))->assertForbidden();
    }

    public function test_records_of_another_company_are_not_reachable(): void
    {
        $other = Company::factory()->create(['account_id' => $this->account->id]);
        $otherClient = Client::factory()->create(['company_id' => $other->id, 'user_id' => $this->user->id]);
        $foreign = new ServiceRequest();
        $foreign->fill($this->requestPayload());
        $foreign->company_id = $other->id;
        $foreign->client_id = $otherClient->id;
        $foreign->request_number = 'SR-X-1';
        $foreign->status = 'new';
        $foreign->save();

        $this->console($this->user)->get(route('ops.service_requests.show', $foreign->hashed_id))->assertNotFound();
        $this->console($this->user)->post(route('ops.service_requests.cancel', $foreign->hashed_id), ['reason' => 'x'])->assertNotFound();
        $this->console($this->user)->get(route('ops.service_requests.show', '12'))->assertNotFound();
    }

    public function test_portal_customer_only_sees_own_requests_and_can_submit(): void
    {
        $contact = $this->client->contacts()->first();
        $job = $this->jobFor(null, 'pending');
        $sr = ServiceRequest::find($job->service_request_id);

        $otherClient = Client::factory()->create(['company_id' => $this->company->id, 'user_id' => $this->user->id]);
        $otherContact = ClientContact::factory()->create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'client_id' => $otherClient->id, 'is_primary' => true]);

        $this->actingAs($contact, 'contact')->get(route('client.ops.service_requests.show', $sr->hashed_id))->assertOk()->assertSee($sr->request_number);
        $this->actingAs($contact, 'contact')->get(route('client.ops.repairs.show', $job->hashed_id))->assertOk();
        $this->actingAs($otherContact, 'contact')->get(route('client.ops.service_requests.show', $sr->hashed_id))->assertNotFound();
        $this->actingAs($otherContact, 'contact')->get(route('client.ops.repairs.show', $job->hashed_id))->assertNotFound();

        $this->actingAs($contact, 'contact')->get(route('client.ops.warranty.index'))->assertOk();
        $this->actingAs($contact, 'contact')->post(route('client.ops.service_requests.store'), [
            'device_type' => 'Desktop', 'device_brand' => 'Custom', 'reported_issue' => 'PC restarts randomly while gaming.',
        ])->assertRedirect();

        $created = ServiceRequest::where('client_id', $this->client->id)->latest('id')->first();
        $this->assertSame('portal', $created->channel);
        $this->assertSame($contact->id, $created->client_contact_id);
    }

    public function test_api_uses_token_auth_and_same_abilities(): void
    {
        $tech = $this->makeStaff('technician');
        $mine = $this->jobFor($tech->id);
        $this->jobFor(null, 'approved');

        $res = $this->withHeaders(['X-API-TOKEN' => $this->tokenFor($tech), 'X-Requested-With' => 'XMLHttpRequest'])
            ->getJson('/api/v1/pcfix/repair-jobs')->assertOk();
        $this->assertSame([$mine->job_number], collect($res->json('data'))->pluck('number')->all());

        // Invoice Ninja's Handler renders AuthorizationException as 401 for JSON requests.
        $this->withHeaders(['X-API-TOKEN' => $this->tokenFor($tech)])->getJson('/api/v1/pcfix/dashboard')->assertUnauthorized();
        $this->withHeaders(['X-API-TOKEN' => $this->tokenFor($tech)])->postJson('/api/v1/pcfix/purchase-requests/xyz/decide', ['decision' => 'approve'])->assertUnauthorized();
        $this->withHeaders(['X-API-TOKEN' => 'invalid'])->getJson('/api/v1/pcfix/repair-jobs')->assertForbidden();

        $this->withHeaders(['X-API-TOKEN' => $this->token])->getJson('/api/v1/pcfix/dashboard')->assertOk()->assertJsonStructure(['data' => ['financial' => ['revenue', 'expenses', 'net_income', 'outstanding'], 'repairs', 'inventory', 'customers', 'sales_trend']]);
    }

    public function test_dashboard_revenue_matches_database_and_moves_with_new_payments(): void
    {
        $from = now()->subYears(5)->toDateString();
        $to = now()->toDateString();
        $currency = (int) $this->company->settings->currency_id;

        $expected = (float) DB::table('payments')->join('clients', 'clients.id', '=', 'payments.client_id')
            ->where('payments.company_id', $this->company->id)->where('payments.is_deleted', 0)->where('clients.is_deleted', 0)
            ->whereIn('payments.status_id', [1, 4, 5, 6])->whereBetween('payments.date', [$from, $to])->where('payments.currency_id', $currency)
            ->sum(DB::raw('payments.amount - payments.refunded'));

        $before = DashboardMetrics::forRange($this->company, $from, $to)->financial();
        $this->assertEqualsWithDelta(round($expected, 2), $before['revenue'], 0.001);

        Payment::factory()->create([
            'company_id' => $this->company->id, 'user_id' => $this->user->id, 'client_id' => $this->client->id,
            'amount' => 777.25, 'refunded' => 0, 'date' => now()->toDateString(), 'currency_id' => $currency, 'status_id' => Payment::STATUS_COMPLETED,
        ]);

        $after = DashboardMetrics::forRange($this->company, $from, $to)->financial();
        $this->assertEqualsWithDelta(777.25, $after['revenue'] - $before['revenue'], 0.001);
        $this->assertEqualsWithDelta($after['revenue'] - $after['expenses'], $after['net_income'], 0.001);
    }
}
