<?php

namespace App\Http\Controllers\ClientPortal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ops\ServiceRequestSubmission;
use App\Models\ClientContact;
use App\Models\Ops\RepairJob;
use App\Models\Ops\ServiceRequest;
use App\Models\Ops\Warranty;
use App\Models\Ops\WarrantyClaim;
use App\Services\Ops\ServiceRequestService;
use App\Services\Ops\WarrantyService;
use App\Utils\Traits\MakesHash;
use Illuminate\Http\Request;

/**
 * Customer self-service in the existing Invoice Ninja client portal:
 * submit service requests, track repairs, view warranties, file claims.
 * Quote approval, invoices and payments use the existing portal pages.
 *
 * IDOR protection: every query is constrained to the contact's own client.
 */
class OpsPortalController extends Controller
{
    use MakesHash;

    private function contact(): ClientContact
    {
        return auth()->guard('contact')->user();
    }

    private function scoped(string $model, string $id)
    {
        $contact = $this->contact();
        $pk = $this->decodePrimaryKey($id, true);
        abort_unless(is_numeric($pk), 404);

        return $model::query()
            ->where('company_id', $contact->company_id)
            ->where('client_id', $contact->client_id)
            ->whereKey($pk)
            ->firstOrFail();
    }

    public function requests()
    {
        $c = $this->contact();

        return view('ops::portal.requests', [
            'requests' => ServiceRequest::query()->where('company_id', $c->company_id)->where('client_id', $c->client_id)->with('repairJob')->orderByDesc('id')->paginate(15),
            'jobs' => RepairJob::query()->where('company_id', $c->company_id)->where('client_id', $c->client_id)->whereNull('service_request_id')->with('device')->orderByDesc('id')->limit(15)->get(),
        ]);
    }

    public function createRequest()
    {
        return view('ops::portal.request_form', ['contact' => $this->contact()]);
    }

    public function storeRequest(ServiceRequestSubmission $request, ServiceRequestService $service)
    {
        $contact = $this->contact();

        $payload = $request->payload() + [
            'customer_name' => $contact->client->present()->name(),
            'email' => $contact->email,
            'phone' => $contact->phone ?: $contact->client->phone,
            'address' => $contact->client->address1,
        ];

        [$sr, $dup] = $service->submit($contact->company, $payload, 'portal', $contact, null, $request->ip());

        return redirect()->route('client.ops.service_requests.show', $sr->hashed_id)
            ->with('message', $dup ? "We already have this request as {$sr->request_number}." : "Request {$sr->request_number} submitted. We will send you a quotation.");
    }

    public function showRequest(string $id)
    {
        $sr = $this->scoped(ServiceRequest::class, $id)->load(['repairJob', 'quote']);

        return view('ops::portal.request_show', [
            'sr' => $sr,
            'job' => $sr->repairJob,
            'timeline' => $sr->repairJob ? $sr->repairJob->updates()->where('is_customer_visible', true)->get() : collect(),
            'quotes' => $sr->repairJob ? $sr->repairJob->quoteLinks()->with('quote.invitations')->get() : collect(),
        ]);
    }

    public function showRepair(string $id)
    {
        $job = $this->scoped(RepairJob::class, $id)->load(['device', 'invoice', 'warranty', 'serviceRequest']);

        return view('ops::portal.repair_show', [
            'job' => $job,
            'timeline' => $job->updates()->where('is_customer_visible', true)->get(),
            'quotes' => $job->quoteLinks()->with('quote.invitations')->get(),
        ]);
    }

    public function warranty(WarrantyService $service)
    {
        $c = $this->contact();

        $warranties = Warranty::query()->where('company_id', $c->company_id)->where('client_id', $c->client_id)->with('device')->orderByDesc('end_date')->get();
        $warranties->each(fn ($w) => $service->refreshStatus($w));

        return view('ops::portal.warranty', [
            'warranties' => $warranties,
            'claims' => WarrantyClaim::query()->where('company_id', $c->company_id)->where('client_id', $c->client_id)->with(['warranty', 'repairJob'])->orderByDesc('id')->get(),
        ]);
    }

    public function storeClaim(Request $request, WarrantyService $service)
    {
        $contact = $this->contact();

        $data = $request->validate([
            'warranty' => 'nullable|string|max:40',
            'serial_number' => 'nullable|string|max:150',
            'issue_description' => 'required|string|min:10|max:3000',
        ]);

        $warrantyId = null;

        if (! empty($data['warranty'])) {
            $warrantyId = $this->scoped(Warranty::class, $data['warranty'])->id;
        }

        $claim = $service->submitClaim($contact->company, $contact->client, $contact, [
            'warranty_id' => $warrantyId,
            'serial_number' => isset($data['serial_number']) ? strtoupper(trim(strip_tags($data['serial_number']))) : null,
            'issue_description' => trim(strip_tags($data['issue_description'])),
        ], 'portal');

        return redirect()->route('client.ops.warranty.index')
            ->with('message', "Claim {$claim->claim_number} submitted. Automatic check: {$claim->eligibility['reason']} A technician will verify it.");
    }
}
