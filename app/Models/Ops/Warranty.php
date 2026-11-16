<?php

namespace App\Models\Ops;

use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Warranty extends OpsModel
{
    use SoftDeletes;

    public const STATUSES = ['active' => 'Active', 'expiring_soon' => 'Expiring Soon', 'expired' => 'Expired', 'claimed' => 'Claimed', 'void' => 'Void'];

    public const COVERAGE = ['parts_and_labor' => 'Parts & Labor', 'parts' => 'Parts only', 'labor' => 'Labor only'];

    protected $table = 'warranties';

    protected $fillable = ['coverage_type', 'covered_items', 'exclusions', 'start_date', 'end_date'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'expiry_notified_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function device()
    {
        return $this->belongsTo(Device::class)->withTrashed();
    }

    public function repairJob()
    {
        return $this->belongsTo(RepairJob::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    public function claims()
    {
        return $this->hasMany(WarrantyClaim::class)->orderByDesc('created_at');
    }

    /**
     * Status derived from dates and open claims. "void" is sticky.
     */
    public function computeStatus(?Carbon $today = null): string
    {
        if ($this->status === 'void') {
            return 'void';
        }

        $today = ($today ?? now())->copy()->startOfDay();

        if ($this->end_date->lt($today)) {
            return 'expired';
        }

        if ($this->claims()->whereIn('status', ['approved', 'in_repair'])->exists()) {
            return 'claimed';
        }

        if ($this->end_date->lte($today->copy()->addDays(config('pcfix.defaults.warranty_expiring_days')))) {
            return 'expiring_soon';
        }

        return 'active';
    }

    public function isInCoverage(?Carbon $on = null): bool
    {
        $on = ($on ?? now())->copy()->startOfDay();

        return $this->status !== 'void' && $on->betweenIncluded($this->start_date, $this->end_date);
    }

    public function coversParts(): bool
    {
        return in_array($this->coverage_type, ['parts', 'parts_and_labor'], true);
    }

    public function coversLabor(): bool
    {
        return in_array($this->coverage_type, ['labor', 'parts_and_labor'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
