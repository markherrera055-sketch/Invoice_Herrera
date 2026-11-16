<?php

namespace App\Http\Controllers\Ops;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Ops\Device;
use App\Models\Ops\RepairJob;
use App\Models\Ops\ServiceRequest;
use App\Models\Ops\Warranty;
use App\Services\Ops\CustomerMatcher;
use App\Utils\Traits\MakesHash;
use Illuminate\Http\Request;

/**
 * Customer list (Invoice Ninja clients) with repair / warranty / billing
 * history and a duplicate-profile finder (P11).
 */
class CustomerController extends OpsController
{
    use MakesHash;

    public function index(Request $request, CustomerMatcher $matcher)
    {
        $request->validate(['q' => 'nullable|string|max:100', 'view' => 'nullable|in:all,duplicates']);

        $cid = $this->companyId();

        if ($request->input('view') === 'duplicates') {
            return view('ops::customers.duplicates', ['groups' => $this->duplicateGroups($cid, $matcher)]);
        }

        $query = Client::query()->where('company_id', $cid)->where('is_deleted', false)->with('contacts');

        if ($q = $request->input('q')) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")
                ->orWhereHas('contacts', fn ($c) => $c->where('email', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")));
        }

        $clients = $query->orderBy('name')->paginate(30)->withQueryString();

        $jobCounts = RepairJob::query()->forCompany($cid)->whereIn('client_id', $clients->pluck('id'))->selectRaw('client_id, COUNT(*) c')->groupBy('client_id')->pluck('c', 'client_id');

        return view('ops::customers.index', compact('clients', 'jobCounts'));
    }

    public function show(string $client)
    {
        $id = $this->decodePrimaryKey($client, true);
        abort_unless(is_numeric($id), 404);

        $cid = $this->companyId();
        $client = Client::query()->where('company_id', $cid)->whereKey($id)->with('contacts')->firstOrFail();

        $canBilling = $this->can('billing.view');

        return view('ops::customers.show', [
            'client' => $client,
            'devices' => Device::query()->forCompany($cid)->where('client_id', $client->id)->withCount('repairJobs')->get(),
            'requests' => ServiceRequest::query()->forCompany($cid)->where('client_id', $client->id)->orderByDesc('id')->limit(20)->get(),
            'jobs' => RepairJob::query()->forCompany($cid)->where('client_id', $client->id)->with(['device', 'technician', 'invoice'])->orderByDesc('id')->limit(30)->get(),
            'warranties' => Warranty::query()->forCompany($cid)->where('client_id', $client->id)->with('device')->orderByDesc('end_date')->get(),
            'invoices' => $canBilling ? Invoice::query()->where('company_id', $cid)->where('client_id', $client->id)->where('is_deleted', false)->orderByDesc('date')->limit(20)->get() : collect(),
            'canBilling' => $canBilling,
        ]);
    }

    /**
     * Groups of existing client profiles that look like the same customer.
     */
    private function duplicateGroups(int $cid, CustomerMatcher $matcher): array
    {
        $groups = [];
        $seen = [];

        $clients = Client::query()->where('company_id', $cid)->where('is_deleted', false)->with('contacts')->limit(2000)->get();

        // Exact email / phone collisions across different clients.
        $byKey = [];

        foreach ($clients as $client) {
            foreach ($client->contacts as $contact) {
                if ($contact->email) {
                    $byKey['email:' . strtolower(trim($contact->email))][$client->id] = $client;
                }

                if ($tail = $matcher->phoneTail($contact->phone)) {
                    $byKey['phone:' . $tail][$client->id] = $client;
                }
            }

            if ($tail = $matcher->phoneTail($client->phone)) {
                $byKey['phone:' . $tail][$client->id] = $client;
            }

            $name = $matcher->normaliseName($client->name);

            if (strlen($name) >= 4) {
                $byKey['name:' . $name][$client->id] = $client;
            }
        }

        foreach ($byKey as $key => $members) {
            if (count($members) < 2) {
                continue;
            }

            $ids = array_keys($members);
            sort($ids);
            $sig = implode(',', $ids);

            if (isset($seen[$sig])) {
                $groups[$seen[$sig]]['reasons'][] = $key;

                continue;
            }

            $seen[$sig] = count($groups);
            $groups[] = ['reasons' => [$key], 'clients' => array_values($members)];
        }

        return $groups;
    }
}
