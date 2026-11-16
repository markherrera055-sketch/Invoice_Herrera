<?php

namespace Tests\Feature\PCFix;

use App\Models\Client;
use App\Models\Ops\Device;
use App\Models\Ops\OpsNotification;
use App\Models\Ops\ServiceRequest;
use App\Services\Ops\ServiceRequestService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Session;
use Tests\MockAccountData;
use Tests\TestCase;

/**
 * Module 1 — Customer service request & job intake (P1, P2, P11).
 */
class ServiceRequestIntakeTest extends TestCase
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

    public function test_public_form_renders_and_rejects_unknown_company(): void
    {
        $this->get(route('ops.public.create', $this->company->company_key))->assertOk()->assertSee('Request a repair');
        $this->get('/service-request/not-a-real-company-key')->assertNotFound();
    }

    public function test_public_submission_auto_links_existing_customer_by_email_without_creating_duplicate(): void
    {
        $contact = $this->client->contacts()->first();
        $contact->email = 'existing.customer@example.com';
        $contact->save();

        $clientsBefore = Client::where('company_id', $this->company->id)->count();

        $response = $this->post(route('ops.public.store', $this->company->company_key), $this->requestPayload([
            'customer_name' => 'Someone Typed Differently',
            'email' => 'EXISTING.Customer@example.com',
        ]));

        $sr = ServiceRequest::where('company_id', $this->company->id)->latest('id')->first();

        $response->assertRedirect(route('ops.public.thanks', [$this->company->company_key, $sr->request_number]));
        $this->assertMatchesRegularExpression('/^SR-\d{4}-\d{5}$/', $sr->request_number);
        $this->assertSame($this->client->id, $sr->client_id, 'request must link to the existing client');
        $this->assertSame($contact->id, $sr->client_contact_id);
        $this->assertSame($clientsBefore, Client::where('company_id', $this->company->id)->count(), 'no duplicate client may be created');
        $this->assertNotNull($sr->device_id);
        $this->assertSame($sr->serial_number, Device::find($sr->device_id)->serial_number);
    }

    public function test_duplicate_submission_is_idempotent(): void
    {
        $payload = $this->requestPayload();

        $this->post(route('ops.public.store', $this->company->company_key), $payload)->assertRedirect();
        $this->post(route('ops.public.store', $this->company->company_key), $payload)->assertRedirect();

        $this->assertSame(1, ServiceRequest::where('company_id', $this->company->id)->where('email', $payload['email'])->count());
    }

    public function test_validation_and_honeypot(): void
    {
        $this->post(route('ops.public.store', $this->company->company_key), ['customer_name' => 'X'])
            ->assertSessionHasErrors(['device_type', 'device_brand', 'reported_issue', 'email']);

        $this->post(route('ops.public.store', $this->company->company_key), $this->requestPayload(['website' => 'spam']))
            ->assertSessionHasErrors('website');

        $this->post(route('ops.public.store', $this->company->company_key), $this->requestPayload(['phone' => 'call me maybe', 'email' => '']))
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, ServiceRequest::where('company_id', $this->company->id)->count());
    }

    public function test_new_customer_is_not_linked_and_staff_can_create_client_through_invoice_ninja(): void
    {
        $manager = $this->makeStaff('service_manager');
        $this->actAs($manager);

        [$sr] = app(ServiceRequestService::class)->submit($this->company, $this->requestPayload([
            'customer_name' => 'Brand New Person',
            'email' => 'brand.new@example.com',
            'phone' => '0999 000 1111',
        ]), 'online');

        $this->assertNull($sr->client_id);
        $this->assertFalse($sr->possible_duplicate);

        $sr = app(ServiceRequestService::class)->createClientFor($sr, $manager);

        $client = Client::find($sr->client_id);
        $this->assertSame('Brand New Person', $client->name);
        $this->assertSame('brand.new@example.com', $client->contacts()->first()->email);
        $this->assertNotEmpty($client->number, 'client number assigned by Invoice Ninja repository');
        $this->assertSame('under_review', $sr->status);
    }

    public function test_similar_name_is_flagged_for_review_not_auto_linked(): void
    {
        $this->client->name = 'Maria Clara Santos';
        $this->client->save();

        [$sr] = app(ServiceRequestService::class)->submit($this->company, $this->requestPayload([
            'customer_name' => 'Maria Clara Santoss',
            'email' => 'different@example.com',
            'phone' => '0911 222 3333',
        ]), 'online');

        $this->assertNull($sr->client_id);
        $this->assertTrue($sr->possible_duplicate);
        $this->assertSame($this->client->id, $sr->duplicate_candidates[0]['client_id']);
        $this->assertSame('weak', $sr->duplicate_candidates[0]['strength']);
    }

    public function test_service_managers_are_notified_once(): void
    {
        $manager = $this->makeStaff('service_manager');

        [$sr] = app(ServiceRequestService::class)->submit($this->company, $this->requestPayload(), 'online');

        $n = OpsNotification::where('company_id', $this->company->id)->where('recipient_id', $manager->id)->where('type', 'service_request')->get();
        $this->assertCount(2, $n, 'one in-app + one email record');
        $this->assertEqualsCanonicalizing(['database', 'mail'], $n->pluck('channel')->all());
    }
}
