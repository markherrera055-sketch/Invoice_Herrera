<?php

namespace App\Http\Controllers\Ops;

use App\Models\CompanyUser;
use App\Models\Ops\OpsAuditLog;
use App\Models\Ops\TechnicianProfile;
use App\Services\Ops\AuditLogger;
use App\Services\Ops\OpsAccess;
use App\Services\Ops\SmsGateway;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Role assignment (RBAC) built on Invoice Ninja's company_user records.
 * Invoice Ninja owners/admins are managed in the main admin app.
 */
class AdminController extends OpsController
{
    public function users()
    {
        $users = CompanyUser::query()->where('company_id', $this->companyId())->whereNull('deleted_at')->with('user')->get()->filter(fn ($cu) => $cu->user);

        return view('ops::admin.users', [
            'users' => $users,
            'profiles' => TechnicianProfile::query()->forCompany($this->companyId())->get()->keyBy('user_id'),
        ]);
    }

    public function updateUser(Request $request, int $user)
    {
        $cu = CompanyUser::query()->where('company_id', $this->companyId())->where('user_id', $user)->whereNull('deleted_at')->firstOrFail();

        if ($cu->is_owner) {
            return back()->withErrors(['role' => 'The account owner always has full access.']);
        }

        if ((int) $cu->user_id === (int) $this->user()->id) {
            return back()->withErrors(['role' => 'You cannot change your own role.']);
        }

        $data = $request->validate([
            'ops_role' => ['nullable', Rule::in(array_keys(config('pcfix.roles')))],
            'apply_native' => 'nullable|boolean',
        ]);

        $before = $cu->ops_role;
        $cu->ops_role = $data['ops_role'] ?? null;

        // Optionally merge matching Invoice Ninja native permissions (never removes existing ones).
        if ($request->boolean('apply_native') && $cu->ops_role && ! $cu->is_admin) {
            $native = config('pcfix.native_permissions.' . $cu->ops_role, []);
            $current = array_filter(array_map('trim', explode(',', (string) $cu->permissions)));
            $cu->permissions = implode(',', array_values(array_unique(array_merge($current, $native))));
            $cu->permissions_updated_at = now();
        }

        $cu->save();

        if ($cu->ops_role === 'technician' && ! TechnicianProfile::query()->forCompany($this->companyId())->where('user_id', $cu->user_id)->exists()) {
            $p = new TechnicianProfile();
            $p->company_id = $this->companyId();
            $p->user_id = $cu->user_id;
            $p->skills = ['hardware', 'software'];
            $p->is_available = true;
            $p->max_active_jobs = 5;
            $p->save();
        }

        AuditLogger::log($this->companyId(), 'user.role_changed', $cu, ['from' => $before, 'to' => $cu->ops_role, 'native' => $request->boolean('apply_native')], $this->user()->id);

        return $this->done("{$cu->user->present()->name()} is now: " . OpsAccess::roleLabel($cu));
    }

    public function settings()
    {
        return view('ops::admin.settings', [
            'publicUrl' => route('ops.public.create', $this->company()->company_key),
            'smsConfigured' => SmsGateway::isConfigured(),
            'settings' => $this->company()->settings,
            'trackInventory' => (bool) $this->company()->track_inventory,
        ]);
    }

    public function audit(Request $request)
    {
        $request->validate(['action' => 'nullable|string|max:60']);

        $query = OpsAuditLog::query()->forCompany($this->companyId())->with('user');

        if ($a = $request->input('action')) {
            $query->where('action', 'like', $a . '%');
        }

        return view('ops::admin.audit', ['logs' => $query->orderByDesc('id')->paginate(50)->withQueryString()]);
    }
}
