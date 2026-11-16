<?php

namespace App\Models\Ops;

use App\Models\Client;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends OpsModel
{
    use SoftDeletes;

    protected $table = 'devices';

    protected $fillable = ['device_type', 'brand', 'model', 'serial_number', 'notes'];

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function repairJobs()
    {
        return $this->hasMany(RepairJob::class);
    }

    public function warranties()
    {
        return $this->hasMany(Warranty::class);
    }

    public function label(): string
    {
        return trim(implode(' ', array_filter([$this->brand, $this->model]))) ?: $this->device_type;
    }
}
