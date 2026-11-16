<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Ops\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

/**
 * Session login for the operations console, using existing Invoice Ninja
 * user accounts (same password hashes, same two-factor secret).
 */
class AuthController extends Controller
{
    public function showLogin()
    {
        if (auth()->guard('user')->check()) {
            return redirect()->route('ops.dashboard');
        }

        return view('ops::auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:191',
            'password' => 'required|string|max:255',
            'one_time_password' => 'nullable|string|max:10',
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $data['email'])->first();

        // Constant response for unknown user vs wrong password.
        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if ($user->google_2fa_secret) {
            if (empty($data['one_time_password'])) {
                return back()->withInput($request->only('email'))->with('ops_need_otp', true)->withErrors(['one_time_password' => 'Enter the 6-digit code from your authenticator app.']);
            }

            if (! (new Google2FA())->verifyKey(decrypt($user->google_2fa_secret), $data['one_time_password'])) {
                return back()->withInput($request->only('email'))->with('ops_need_otp', true)->withErrors(['one_time_password' => 'Invalid authentication code.']);
            }
        }

        $hasCompany = CompanyUser::query()->where('user_id', $user->id)->where('is_locked', false)->whereNull('deleted_at')->exists();

        if (! $hasCompany) {
            throw ValidationException::withMessages(['email' => 'Your account has no active company access.']);
        }

        auth()->guard('user')->login($user, false);
        $request->session()->regenerate();

        $cu = CompanyUser::query()->where('user_id', $user->id)->where('is_locked', false)->whereNull('deleted_at')->first();
        AuditLogger::log($cu->company_id, 'auth.login', $user, [], $user->id);

        return redirect()->intended(route('ops.dashboard'));
    }

    public function logout(Request $request)
    {
        auth()->guard('user')->logout();
        $request->session()->forget('ops_company_id');
        $request->session()->regenerateToken();

        return redirect()->route('ops.login');
    }

    public function switchCompany(Request $request)
    {
        $data = $request->validate(['company_id' => 'required|integer']);

        $allowed = CompanyUser::query()
            ->where('user_id', auth()->guard('user')->id())
            ->where('company_id', $data['company_id'])
            ->where('is_locked', false)
            ->whereNull('deleted_at')
            ->exists();

        abort_unless($allowed, 403);

        $request->session()->put('ops_company_id', (int) $data['company_id']);

        return redirect()->route('ops.dashboard');
    }
}
