<?php

namespace App\Http\Middleware;

use App\Jobs\Company\CreateCompanyToken;
use App\Models\CompanyToken;
use App\Models\CompanyUser;
use App\Services\Ops\OpsAccess;
use App\Services\Ops\OpsNotifier;
use App\Utils\TruthSource;
use Closure;
use Illuminate\Http\Request;

/**
 * Session authentication for the PCFix operations console.
 *
 * Mirrors TokenAuth: it resolves the active company + company_user + token
 * and loads them into Invoice Ninja's TruthSource, so every existing
 * permission check, repository and policy behaves exactly as for API calls.
 */
class OpsAuthenticate
{
    public function handle(Request $request, Closure $next)
    {
        $guard = auth()->guard('user');

        if (! $guard->check()) {
            return redirect()->guest(route('ops.login'));
        }

        /** @var \App\Models\User $user */
        $user = $guard->user();

        $companyUsers = CompanyUser::query()
            ->where('user_id', $user->id)
            ->where('is_locked', false)
            ->whereNull('deleted_at')
            ->with('company')
            ->get()
            ->filter(fn ($cu) => $cu->company && ! $cu->company->is_disabled);

        $cu = $companyUsers->firstWhere('company_id', (int) $request->session()->get('ops_company_id'))
            ?? $companyUsers->first();

        if (! $cu || $user->trashed()) {
            $guard->logout();
            $request->session()->invalidate();

            return redirect()->route('ops.login')->withErrors(['email' => 'Your account has no active company access.']);
        }

        $request->session()->put('ops_company_id', $cu->company_id);

        $token = CompanyToken::query()
            ->where('company_id', $cu->company_id)
            ->where('user_id', $user->id)
            ->orderByDesc('is_system')
            ->first()
            ?? (new CreateCompanyToken($cu->company, $user, 'PCFix Operations'))->handle();

        $token->setRelation('cu', $cu);

        // Bind a populated TruthSource explicitly: the boot-time instance can be
        // forgotten by Queue::before (e.g. a sync-queue job earlier in the process).
        $truth = new TruthSource();
        $truth->setCompanyUser($cu);
        $truth->setUser($user);
        $truth->setCompany($cu->company);
        $truth->setCompanyToken($token);
        app()->instance(TruthSource::class, $truth);

        // Request-scoped copy that survives any later TruthSource reset.
        $request->attributes->set('ops_company_user', $cu);

        $user->setCompany($cu->company);
        auth()->setUser($user);

        $abilities = OpsAccess::abilitiesFor($cu);

        if (! $abilities) {
            abort(403, 'Your account does not have an operations role. Ask an administrator to assign one.');
        }

        view()->share([
            'opsUser' => $user,
            'opsCompany' => $cu->company,
            'opsCompanyUser' => $cu,
            'opsAbilities' => $abilities,
            'opsCompanies' => $companyUsers->map(fn ($c) => $c->company),
            'opsNotifications' => OpsNotifier::unreadFor($cu->company_id, $user->id, 8),
            'opsUnread' => \App\Models\Ops\OpsNotification::query()->where('company_id', $cu->company_id)->where('channel', 'database')->where('recipient_type', 'user')->where('recipient_id', $user->id)->whereNull('read_at')->count(),
        ]);

        $response = $next($request);

        // Sensitive operational data: never cache in shared proxies / browser history.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
