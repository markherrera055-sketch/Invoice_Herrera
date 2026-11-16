<?php

namespace App\Models\Ops;

use App\Models\Quote;

class RepairJobQuote extends OpsModel
{
    protected $table = 'repair_job_quotes';

    public function repairJob()
    {
        return $this->belongsTo(RepairJob::class);
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }
}
