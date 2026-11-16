<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ops\ServiceRequestSubmission;
use App\Libraries\MultiDB;
use App\Models\Company;
use App\Models\Ops\ServiceRequest;
use App\Services\Ops\ServiceRequestService;

/**
 * Public online service request form (no login), identified by the
 * company's public company_key. Protected by throttling, a honeypot field,
 * CSRF and a duplicate-submission guard.
 */
class PublicServiceRequestController extends Controller
{
    private function company(string $key): Company
    {
        MultiDB::findAndSetDbByCompanyKey($key);

        $company = Company::query()->where('company_key', $key)->where('is_disabled', false)->first();

        abort_unless($company, 404);

        return $company;
    }

    public function create(string $company_key)
    {
        return view('ops::public.request', ['company' => $this->company($company_key)]);
    }

    public function store(ServiceRequestSubmission $request, string $company_key, ServiceRequestService $service)
    {
        $company = $this->company($company_key);

        [$sr] = $service->submit($company, $request->payload(), 'online', null, null, $request->ip());

        return redirect()->route('ops.public.thanks', [$company_key, $sr->request_number]);
    }

    public function thanks(string $company_key, string $reference)
    {
        $company = $this->company($company_key);

        // Only confirm the reference number; never echo customer data publicly.
        $exists = ServiceRequest::query()->forCompany($company->id)->where('request_number', $reference)->exists();

        abort_unless($exists, 404);

        return view('ops::public.thanks', ['company' => $company, 'reference' => $reference]);
    }
}
