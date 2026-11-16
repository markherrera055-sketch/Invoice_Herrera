<?php

namespace App\Models\Ops;

use App\Models\Product;
use App\Models\User;

class RepairJobPart extends OpsModel
{
    protected $table = 'repair_job_parts';

    protected $casts = ['is_warranty_covered' => 'boolean'];

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function repairJob()
    {
        return $this->belongsTo(RepairJob::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
