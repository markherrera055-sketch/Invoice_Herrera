<?php

namespace App\Models\Ops;

use App\Models\User;

class RepairJobUpdate extends OpsModel
{
    protected $table = 'repair_job_updates';

    protected $fillable = ['type', 'from_status', 'to_status', 'note', 'is_customer_visible'];

    protected $casts = ['is_customer_visible' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
