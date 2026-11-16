<?php

namespace App\Http\Controllers\Ops\Concerns;

use App\Models\Product;
use App\Services\Ops\QuoteBuilder;
use App\Utils\Traits\MakesHash;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Turns the quotation line editor input into Invoice Ninja line items.
 *
 * lines[n][kind]       labor | part | fee
 * lines[n][product]    hashed product id (parts)
 * lines[n][description]
 * lines[n][quantity]
 * lines[n][price]
 */
trait ParsesQuoteLines
{
    use MakesHash;

    protected function parseQuoteLines(Request $request, int $company_id): array
    {
        $request->validate([
            'lines' => 'required|array|min:1|max:40',
            'lines.*.kind' => 'required|in:labor,part,fee',
            'lines.*.product' => 'nullable|string|max:40',
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.quantity' => 'nullable|numeric|min:0|max:10000',
            'lines.*.price' => 'nullable|numeric|min:0|max:10000000',
        ]);

        $lines = [];

        foreach ($request->input('lines') as $row) {
            $qty = (float) ($row['quantity'] ?? 0);

            if ($qty <= 0) {
                continue;
            }

            if ($row['kind'] === 'part') {
                $id = ! empty($row['product']) ? $this->decodePrimaryKey($row['product'], true) : null;
                $product = is_numeric($id) ? Product::query()->where('company_id', $company_id)->where('is_deleted', false)->find($id) : null;

                if (! $product) {
                    throw ValidationException::withMessages(['lines' => 'Select a valid inventory item for every part line.']);
                }

                $price = isset($row['price']) && $row['price'] !== '' ? (float) $row['price'] : (float) $product->price;
                $lines[] = QuoteBuilder::productLine($product, $qty, $price);

                continue;
            }

            $description = trim((string) ($row['description'] ?? ''));

            if ($description === '') {
                throw ValidationException::withMessages(['lines' => 'Every labor / fee line needs a description.']);
            }

            $key = $row['kind'] === 'labor' ? config('pcfix.defaults.labor_product_key') : 'SERVICE-FEE';
            $lines[] = QuoteBuilder::line($key, $description, (float) ($row['price'] ?? 0), $qty, '2');
        }

        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line with a quantity.']);
        }

        return $lines;
    }
}
