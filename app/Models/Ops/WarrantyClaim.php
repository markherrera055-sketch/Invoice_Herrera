<?php

namespace App\Models\Ops;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarrantyClaim extends OpsModel
{
    use SoftDeletes;

    public const STATUSES = [
        'submitted' => 'Submitted',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'in_repair' => 'In Repair',
        'completed' => 'Completed',
    ];

    protected $table = 'warranty_claims';

    protected $fillable = ['serial_number', 'issue_description'];

    protected $casts = [
        'eligibility' => 'array',
        'verified_at' => 'datetime',
    ];

    public function warranty()
    {
        return $this->belongsTo(Warranty::class)->withTrashed();
    }

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
        return $this->belongsTo(Device::class)->withTrashed();
    }

    public function repairJob()
    {
        return $this->belongsTo(RepairJob::class);
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by')->withTrashed();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
