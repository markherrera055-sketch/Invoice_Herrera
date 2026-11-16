<?php

namespace App\Services\Ops;

use App\Events\Quote\QuoteWasCreated;
use App\Factory\InvoiceItemFactory;
use App\Factory\QuoteFactory;
use App\Models\Client;
use App\Models\Product;
use App\Models\Quote;
use App\Repositories\QuoteRepository;
use App\Utils\Ninja;
use Illuminate\Support\Facades\Log;

/**
 * Creates quotations through the existing Invoice Ninja quote pipeline
 * (QuoteRepository -> calc -> MarkSent -> SendEmail), so totals, taxes,
 * numbering, invitations and client-portal approval behave exactly as for
 * quotes created in the admin app.
 */
class QuoteBuilder
{
    public static function line(string $product_key, string $notes, float $cost, float $quantity = 1, string $type_id = '1'): array
    {
        $item = (array) InvoiceItemFactory::create();
        $item['product_key'] = $product_key;
        $item['notes'] = $notes;
        $item['cost'] = round($cost, 2);
        $item['quantity'] = $quantity;
        $item['type_id'] = $type_id;

        return $item;
    }

    /** Build a line from an inventory product, using its selling price. */
    public static function productLine(Product $product, float $quantity, ?float $price = null): array
    {
        return self::line($product->product_key, $product->notes ?: $product->product_key, $price ?? (float) $product->price, $quantity, '1');
    }

    public function issue(Client $client, int $user_id, array $lines, string $public_notes = '', bool $send = true): Quote
    {
        $quote = app(QuoteRepository::class)->save([
            'client_id' => $client->id,
            'line_items' => array_values($lines),
            'public_notes' => $public_notes,
            'date' => now()->format('Y-m-d'),
        ], QuoteFactory::create($client->company_id, $user_id));

        $quote = $quote->service()->fillDefaults()->save();

        event(new QuoteWasCreated($quote, $quote->company, Ninja::eventVars($user_id)));

        if ($send) {
            $this->send($quote);
        }

        return $quote->fresh();
    }

    /** Mark sent (assigns number) and email it to the client's contacts. */
    public function send(Quote $quote): Quote
    {
        $quote = $quote->service()->markSent()->save();

        try {
            $quote->service()->sendEmail();
        } catch (\Throwable $e) {
            // Quote stays "sent" and is still approvable in the client portal.
            Log::warning('PCFix quote email failed: ' . $e->getMessage());
        }

        return $quote;
    }
}
