<?php

namespace App\Models\Ops;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;

class RepairJob extends OpsModel
{
    use SoftDeletes;

    /** Statuses that count towards a technician's active workload. */
    public const ACTIVE_STATUSES = ['assigned', 'in_progress', 'waiting_for_parts', 'awaiting_approval'];

    public const OPEN_STATUSES = ['pending', 'awaiting_approval', 'approved', 'assigned', 'in_progress', 'waiting_for_parts'];

    protected $table = 'repair_jobs';

    protected $fillable = ['priority', 'required_skill', 'reported_issue', 'diagnosis', 'scheduled_at'];

    protected $casts = [
        'is_warranty_job' => 'boolean',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'completion_verified_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function device()
    {
        return $this->belongsTo(Device::class)->withTrashed();
    }

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function technician()
    {
        return $this->belongsTo(User::class, 'assigned_user_id')->withTrashed();
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    public function warrantyClaim()
    {
        return $this->belongsTo(WarrantyClaim::class);
    }

    public function quoteLinks()
    {
        return $this->hasMany(RepairJobQuote::class);
    }

    public function updates()
    {
        return $this->hasMany(RepairJobUpdate::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function parts()
    {
        return $this->hasMany(RepairJobPart::class);
    }

    public function warranty()
    {
        return $this->hasOne(Warranty::class);
    }

    public function statusLabel(): string
    {
        return config('pcfix.repair_statuses')[$this->status] ?? ucfirst($this->status);
    }

    public function priorityWeight(): int
    {
        return config('pcfix.priorities')[$this->priority] ?? 2;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Completed and not yet invoiced. */
    public function isReadyToInvoice(): bool
    {
        return $this->status === 'completed' && ! $this->invoice_id;
    }

    /** Linked quotes of a given type, newest first. */
    public function linkedQuotes(?string $type = null)
    {
        $links = $this->quoteLinks()->with('quote')->orderByDesc('id');

        if ($type) {
            $links->where('type', $type);
        }

        return $links->get()->pluck('quote')->filter();
    }

    /** A revision quote has been issued and the customer has not decided yet. */
    public function hasPendingRevision(): bool
    {
        return $this->linkedQuotes('revision')
            ->contains(fn ($q) => in_array($q->status_id, [Quote::STATUS_DRAFT, Quote::STATUS_SENT]));
    }
}
