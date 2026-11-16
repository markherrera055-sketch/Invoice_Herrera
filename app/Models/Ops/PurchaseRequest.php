<?php

namespace App\Models\Ops;

use App\Models\Expense;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseRequest extends OpsModel
{
    use SoftDeletes;

    public const STATUSES = [
        'pending' => 'Pending Approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'ordered' => 'Ordered',
        'received' => 'Received',
        'cancelled' => 'Cancelled',
    ];

    public const TYPES = ['spare_parts' => 'Spare parts / repair materials', 'software_license' => 'Software license', 'equipment' => 'Equipment'];

    protected $table = 'purchase_requests';

    protected $fillable = ['type', 'justification'];

    protected $casts = [
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class)->withTrashed();
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class)->withTrashed();
    }

    public function total(): float
    {
        return round($this->items->sum(fn ($i) => $i->quantity * $i->unit_cost), 2);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
