<?php

namespace App\Models\Ops;

use Illuminate\Support\Facades\DB;

class OpsSequence extends OpsModel
{
    protected $table = 'ops_sequences';

    /**
     * Atomically reserve the next number for a document type, e.g. SR-2026-00001.
     * Uses a row lock so concurrent submissions never receive the same number.
     */
    public static function next(int $company_id, string $prefix): string
    {
        $year = now()->format('Y');
        $key = $prefix . '-' . $year;

        $value = DB::transaction(function () use ($company_id, $key) {
            $row = static::query()->where('company_id', $company_id)->where('key', $key)->lockForUpdate()->first();

            if (! $row) {
                $row = new static();
                $row->company_id = $company_id;
                $row->key = $key;
                $row->next_value = 1;
            }

            $value = $row->next_value;
            $row->next_value = $value + 1;
            $row->save();

            return $value;
        });

        return sprintf('%s-%s-%05d', $prefix, $year, $value);
    }
}
