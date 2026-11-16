<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Ops\OpsAccess;

/**
 * Base controller for the operations console and the PCFix API.
 *
 * The active company user is resolved per request (OpsAccess::currentCompanyUser):
 * console requests carry it as a request attribute set by OpsAuthenticate, API
 * requests resolve it from the X-API-TOKEN. This does not depend on the
 * TruthSource singleton surviving the whole request.
 */
abstract class OpsController extends Controller
{
    protected function companyUser(): CompanyUser
    {
        $cu = OpsAccess::currentCompanyUser(auth()->user());

        abort_unless($cu, 403, 'No active company context.');

        return $cu;
    }

    protected function company(): Company
    {
        return $this->companyUser()->company;
    }

    protected function companyId(): int
    {
        return (int) $this->companyUser()->company_id;
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    protected function can(string $ability): bool
    {
        return OpsAccess::can($this->companyUser(), $ability);
    }

    protected function ensure(string ...$anyOf): void
    {
        foreach ($anyOf as $ability) {
            if ($this->can($ability)) {
                return;
            }
        }

        abort(403, 'You do not have permission to perform this action.');
    }

    protected function done(string $message)
    {
        return back()->with('ops_success', $message);
    }

    /** Link into the existing Invoice Ninja admin application. */
    public static function adminUrl(string $path): string
    {
        return url('/#/' . ltrim($path, '/'));
    }
}
