<?php

namespace App\Models\Ops;

use App\Models\User;

class TechnicianProfile extends OpsModel
{
    protected $table = 'technician_profiles';

    protected $fillable = ['skills', 'branch', 'is_available', 'max_active_jobs'];

    protected $casts = [
        'skills' => 'array',
        'is_available' => 'boolean',
        'last_assigned_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function hasSkill(?string $skill): bool
    {
        return ! $skill || in_array($skill, $this->skills ?? [], true);
    }
}
