<?php

namespace App\Http\Controllers\Ops;

use App\Models\Ops\OpsNotification;
use App\Models\Ops\PurchaseRequest;
use App\Models\Ops\RepairJob;
use App\Models\Ops\ServiceRequest;
use App\Models\Ops\WarrantyClaim;
use App\Services\Ops\DashboardMetrics;
use Illuminate\Http\Request;

class DashboardController extends OpsController
{
    public function index(Request $request)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        $cid = $this->companyId();
        $metrics = DashboardMetrics::forRange($this->company(), $request->input('from'), $request->input('to') ?: null);

        $data = [
            'metrics' => $metrics,
            'repairs' => $metrics->repairs(),
            'financial' => $this->can('reports.financial') ? $metrics->financial() : null,
            'trend' => $this->can('reports.financial') ? $metrics->salesTrend('month') : null,
            'inventory' => $this->can('inventory.view') ? $metrics->inventory() : null,
            'queues' => [
                'new_requests' => $this->can('service_requests.view') ? ServiceRequest::query()->forCompany($cid)->whereIn('status', ['new', 'under_review'])->count() : null,
                'unassigned' => $this->can('repair_jobs.view_all') ? RepairJob::query()->forCompany($cid)->where('status', 'approved')->whereNull('assigned_user_id')->count() : null,
                'awaiting_approval' => $this->can('repair_jobs.view_all') ? RepairJob::query()->forCompany($cid)->where('status', 'awaiting_approval')->count() : null,
                'claims' => $this->can('warranty.view') ? WarrantyClaim::query()->forCompany($cid)->where('status', 'submitted')->count() : null,
                'purchase_pending' => $this->can('purchase_requests.approve') ? PurchaseRequest::query()->forCompany($cid)->where('status', 'pending')->count() : null,
            ],
            'myJobs' => $this->can('repair_jobs.work')
                ? RepairJob::query()->forCompany($cid)->where('assigned_user_id', $this->user()->id)->whereIn('status', RepairJob::OPEN_STATUSES)
                    ->with(['client', 'device'])->orderByRaw("FIELD(priority,'urgent','high','normal','low')")->limit(10)->get()
                : collect(),
        ];

        return view('ops::dashboard', $data);
    }

    public function notifications()
    {
        $items = OpsNotification::query()
            ->forCompany($this->companyId())
            ->where('channel', 'database')
            ->where('recipient_type', 'user')
            ->where('recipient_id', $this->user()->id)
            ->orderByDesc('id')
            ->paginate(30);

        return view('ops::notifications', ['items' => $items]);
    }

    public function markRead()
    {
        OpsNotification::query()
            ->forCompany($this->companyId())
            ->where('channel', 'database')
            ->where('recipient_type', 'user')
            ->where('recipient_id', $this->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->done('All notifications marked as read.');
    }
}
