<?php

namespace App\Models\Ops;

use App\Models\User;

class OpsAuditLog extends OpsModel
{
    protected $table = 'ops_audit_logs';

    protected $casts = ['changes' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
