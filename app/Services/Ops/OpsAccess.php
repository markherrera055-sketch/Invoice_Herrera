<?php

namespace App\Services\Ops;

use App\Models\Company;
use App\Models\CompanyToken;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves PCFix operations abilities for a company user.
 *
 * Invoice Ninja owners/admins hold every ability. Everyone else gets the
 * abilities of their `company_user.ops_role`. Users without a role get none.
 */
class OpsAccess
{
    public static function abilitiesFor(?CompanyUser $cu): array
    {
        if (! $cu || $cu->is_locked) {
            return [];
        }

        if ($cu->is_owner || $cu->is_admin) {
            return array_keys(config('pcfix.abilities'));
        }

        $role = config('pcfix.roles.' . $cu->ops_role);

        if (! $role) {
            return [];
        }

        if (in_array('*', $role['abilities'], true)) {
            return array_keys(config('pcfix.abilities'));
        }

        return $role['abilities'];
    }

    public static function can(?CompanyUser $cu, string $ability): bool
    {
        return in_array($ability, self::abilitiesFor($cu), true);
    }

    /**
     * The company_user record for the user in the active request context.
     *
     * Resolution order (each candidate must belong to the same user):
     *  1. request attribute set by OpsAuthenticate (console session)
     *  2. the X-API-TOKEN header (API via token_auth)
     *  3. Invoice Ninja's TruthSource / the user's token
     */
    public static function currentCompanyUser(?User $user): ?CompanyUser
    {
        if (! $user) {
            return null;
        }

        $request = app()->bound('request') ? request() : null;
        $cu = $request?->attributes->get('ops_company_user');

        if ($cu instanceof CompanyUser && (int) $cu->user_id === (int) $user->id) {
            return $cu;
        }

        if ($request && ($header = $request->header('X-API-TOKEN'))) {
            $token = CompanyToken::with('cu.company')->where('token', $header)->first();

            if ($token && $token->cu && (int) $token->user_id === (int) $user->id) {
                $request->attributes->set('ops_company_user', $token->cu);

                return $token->cu;
            }
        }

        $cu = $user->token()?->cu;

        return $cu && (int) $cu->user_id === (int) $user->id ? $cu : null;
    }

    public static function userCan(?User $user, string $ability): bool
    {
        return self::can(self::currentCompanyUser($user), $ability);
    }

    public static function roleLabel(?CompanyUser $cu): string
    {
        if (! $cu) {
            return '-';
        }

        if ($cu->is_owner) {
            return 'Owner';
        }

        if ($cu->is_admin) {
            return 'Administrator (IN admin)';
        }

        return config('pcfix.roles.' . $cu->ops_role . '.label', 'No operations role');
    }

    /**
     * Active (non-locked, non-deleted) users of a company holding an ability.
     *
     * @return Collection<int, User>
     */
    public static function usersWithAbility(Company $company, string $ability): Collection
    {
        return CompanyUser::query()
            ->where('company_id', $company->id)
            ->where('is_locked', false)
            ->whereNull('deleted_at')
            ->with('user')
            ->get()
            ->filter(fn (CompanyUser $cu) => $cu->user && ! $cu->user->trashed() && self::can($cu, $ability))
            ->map(fn (CompanyUser $cu) => $cu->user)
            ->values();
    }

    /** Technician-only users may only see jobs assigned to them. */
    public static function isRestrictedToAssigned(?User $user): bool
    {
        return ! self::userCan($user, 'repair_jobs.view_all');
    }
}
