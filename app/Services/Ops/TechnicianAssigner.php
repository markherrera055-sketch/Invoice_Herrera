<?php

namespace App\Services\Ops;

use App\Models\CompanyUser;
use App\Models\Ops\RepairJob;
use App\Models\Ops\TechnicianProfile;
use Illuminate\Support\Collection;

/**
 * Deterministic technician selection (never random).
 *
 * Eligible technicians: has a technician profile, marked available, account
 * active and not locked, holds the `repair_jobs.work` ability, has the job's
 * required skill (when set) and is below their max active job limit.
 *
 * Ranking (lowest first):
 *   1. weighted workload  = sum of priority weights of their active jobs
 *   2. number of active jobs
 *   3. least recently assigned
 *   4. user id (stable tie-break)
 *
 * Urgent jobs may exceed a technician's max_active_jobs by one when nobody
 * else is free, so urgent work is never left unassigned while a skilled
 * technician is available.
 */
class TechnicianAssigner
{
    /**
     * @return Collection<int, array{profile:TechnicianProfile, user:\App\Models\User, active:int, load:int, eligible:bool, reason:?string}>
     */
    public function rank(RepairJob $job): Collection
    {
        $weights = config('pcfix.priorities');

        $profiles = TechnicianProfile::query()
            ->forCompany($job->company_id)
            ->with('user')
            ->get();

        $cus = CompanyUser::query()
            ->where('company_id', $job->company_id)
            ->whereIn('user_id', $profiles->pluck('user_id'))
            ->get()
            ->keyBy('user_id');

        $workloads = RepairJob::query()
            ->forCompany($job->company_id)
            ->whereIn('status', RepairJob::ACTIVE_STATUSES)
            ->whereNotNull('assigned_user_id')
            ->where('id', '!=', $job->id ?? 0)
            ->get(['assigned_user_id', 'priority'])
            ->groupBy('assigned_user_id');

        return $profiles->map(function (TechnicianProfile $p) use ($cus, $workloads, $weights, $job) {
            $jobs = $workloads->get($p->user_id, collect());
            $active = $jobs->count();
            $load = $jobs->sum(fn ($j) => $weights[$j->priority] ?? 2);
            $cu = $cus->get($p->user_id);

            $reason = match (true) {
                ! $p->user || $p->user->trashed() => 'User removed',
                ! $cu || $cu->is_locked || $cu->deleted_at => 'Account locked',
                ! OpsAccess::can($cu, 'repair_jobs.work') => 'No technician role',
                ! $p->is_available => 'Unavailable',
                ! $p->hasSkill($job->required_skill) => 'Missing skill: ' . (config('pcfix.skills')[$job->required_skill] ?? $job->required_skill),
                $active >= $p->max_active_jobs && ! ($job->priority === 'urgent' && $active < $p->max_active_jobs + 1) => 'At capacity',
                default => null,
            };

            return [
                'profile' => $p,
                'user' => $p->user,
                'active' => $active,
                'load' => $load,
                'eligible' => $reason === null,
                'reason' => $reason,
            ];
        })->sort(function ($a, $b) {
            return [! $a['eligible'], $a['load'], $a['active'], optional($a['profile']->last_assigned_at)->timestamp ?? 0, $a['profile']->user_id]
                <=> [! $b['eligible'], $b['load'], $b['active'], optional($b['profile']->last_assigned_at)->timestamp ?? 0, $b['profile']->user_id];
        })->values();
    }

    /** Best eligible technician profile, or null when nobody qualifies. */
    public function pick(RepairJob $job): ?TechnicianProfile
    {
        $best = $this->rank($job)->first(fn ($row) => $row['eligible']);

        return $best['profile'] ?? null;
    }
}
