<?php

/**
 * PCFix Hub operations module.
 */

namespace App\Models\Ops;

use App\Utils\Traits\MakesHash;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Base model for PCFix operations entities.
 *
 * Every lookup coming from user input must go through forCompanyOrFail() so
 * a record can never be resolved outside the current company (IDOR guard).
 */
abstract class OpsModel extends Model
{
    use MakesHash;

    protected $guarded = ['id', 'company_id'];

    public function getHashedIdAttribute(): string
    {
        return $this->encodePrimaryKey($this->id);
    }

    public function scopeForCompany(Builder $query, int $company_id): Builder
    {
        return $query->where($this->getTable() . '.company_id', $company_id);
    }

    /**
     * Resolve a hashed id within a company or throw a 404.
     */
    public static function forCompanyOrFail(int $company_id, ?string $hashed_id): static
    {
        $instance = new static();
        $id = $hashed_id ? $instance->decodePrimaryKey($hashed_id, true) : null;

        if (! is_numeric($id)) {
            throw (new ModelNotFoundException())->setModel(static::class);
        }

        return static::query()->forCompany($company_id)->whereKey((int) $id)->firstOrFail();
    }

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class);
    }
}
