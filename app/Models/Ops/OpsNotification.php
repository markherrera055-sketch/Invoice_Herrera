<?php

namespace App\Models\Ops;

class OpsNotification extends OpsModel
{
    protected $table = 'ops_notifications';

    protected $casts = ['read_at' => 'datetime'];
}
