<?php

namespace Tests\Feature\PCFix;

use App\Factory\CompanyUserFactory;
use App\Models\CompanyToken;
use App\Models\CompanyUser;
use App\Models\Product;
use App\Models\User;
use App\Models\Ops\TechnicianProfile;
use App\Utils\TruthSource;
use Illuminate\Support\Str;

/**
 * Shared fixtures for the PCFix operations tests (built on MockAccountData).
 */
trait PCFixTestHelpers
{
    /** Create a company user with an operations role (and optional technician profile). */
    protected function makeStaff(string $role, array $skills = ['hardware', 'software'], bool $available = true, int $max = 5, ?int $company_id = null): User
    {
        $company_id ??= $this->company->id;

        $user = User::factory()->create([
            'account_id' => $this->account->id,
            'email' => Str::random(10) . '@pcfix.test',
            'confirmation_code' => Str::random(10),
        ]);

        $cu = CompanyUserFactory::create($user->id, $company_id, $this->account->id);
        $cu->is_owner = false;
        $cu->is_admin = false;
        $cu->is_locked = false;
        $cu->ops_role = $role;
        $cu->permissions = '';
        $cu->save();

        $token = new CompanyToken();
        $token->user_id = $user->id;
        $token->company_id = $company_id;
        $token->account_id = $this->account->id;
        $token->name = 'pcfix test';
        $token->token = Str::random(64);
        $token->is_system = true;
        $token->save();

        if ($role === 'technician') {
            $p = new TechnicianProfile();
            $p->company_id = $company_id;
            $p->user_id = $user->id;
            $p->skills = $skills;
            $p->is_available = $available;
            $p->max_active_jobs = $max;
            $p->save();
        }

        return $user;
    }

    protected function tokenFor(User $user): string
    {
        return CompanyToken::query()->where('user_id', $user->id)->where('company_id', $this->company->id)->value('token');
    }

    /** Put a user in the request context, as OpsAuthenticate / TokenAuth would. */
    protected function actAs(User $user): void
    {
        $cu = CompanyUser::query()->where('user_id', $user->id)->where('company_id', $this->company->id)->first();
        $token = CompanyToken::query()->where('user_id', $user->id)->where('company_id', $this->company->id)->first();
        $token->setRelation('cu', $cu);

        $truth = new TruthSource();
        app()->instance(TruthSource::class, $truth);
        $truth->setCompanyUser($cu);
        $truth->setUser($user);
        $truth->setCompany($this->company);
        $truth->setCompanyToken($token);

        $user->setCompany($this->company);
        auth()->guard('user')->setUser($user);
        auth()->setUser($user);
    }

    /** Hit the operations console as a user (session guard). */
    protected function console(User $user)
    {
        return $this->actingAs($user, 'user')->withSession(['ops_company_id' => $this->company->id]);
    }

    protected function part(string $sku, int $qty, int $min = 2, float $cost = 1000, float $price = 1500): Product
    {
        $p = Product::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'product_key' => $sku,
            'notes' => $sku . ' part',
            'cost' => $cost,
            'price' => $price,
            'in_stock_quantity' => $qty,
            'stock_notification' => true,
            'stock_notification_threshold' => $min,
            'tax_name1' => '', 'tax_rate1' => 0, 'tax_name2' => '', 'tax_rate2' => 0, 'tax_name3' => '', 'tax_rate3' => 0,
        ]);

        $p->forceFill(['track_stock' => true])->saveQuietly();

        return $p->fresh();
    }

    protected function requestPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Juan Dela Cruz',
            'email' => 'juan.' . Str::random(6) . '@example.com',
            'phone' => '0917 123 4567',
            'address' => 'Poblacion, Bongabong',
            'device_type' => 'Laptop',
            'device_brand' => 'Lenovo',
            'device_model' => 'ThinkPad E14',
            'serial_number' => 'SN-' . strtoupper(Str::random(8)),
            'reported_issue' => 'Laptop does not power on after a brownout.',
        ], $overrides);
    }
}
