<?php

namespace App\Models\Ops;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Quote;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceRequest extends OpsModel
{
    use SoftDeletes;

    public const STATUSES = [
        'new' => 'New',
        'under_review' => 'Under Review',
        'quoted' => 'Quoted',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'converted' => 'Converted to Job',
        'cancelled' => 'Cancelled',
    ];

    public const CHANNELS = ['online' => 'Online form', 'portal' => 'Client portal', 'phone' => 'Phone', 'walk_in' => 'Walk-in', 'email' => 'Email'];

    protected $table = 'service_requests';

    protected $fillable = [
        'channel', 'customer_name', 'email', 'phone', 'address', 'device_type', 'device_brand',
        'device_model', 'serial_number', 'reported_issue', 'preferred_service_date', 'notes', 'branch', 'priority',
    ];

    protected $casts = [
        'preferred_service_date' => 'date',
        'possible_duplicate' => 'boolean',
        'duplicate_candidates' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function contact()
    {
        return $this->belongsTo(ClientContact::class, 'client_contact_id')->withTrashed();
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function repairJob()
    {
        return $this->belongsTo(RepairJob::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['new', 'under_review', 'quoted'], true);
    }
}
