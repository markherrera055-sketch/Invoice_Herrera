<?php

namespace App\Services\Ops;

use App\Factory\ClientFactory;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Company;
use App\Repositories\ClientRepository;
use Illuminate\Support\Str;

/**
 * Duplicate customer detection (addresses AS-IS pain points P2 / P11).
 *
 * Match strength:
 *  - email   : exact (case-insensitive) contact email          -> strong
 *  - phone   : same last 10 digits on contact or client phone   -> strong
 *  - name    : >= 85% similar client / contact name             -> weak
 *  - address : name match AND same street address               -> raises weak to strong
 */
class CustomerMatcher
{
    public const STRONG = 'strong';

    public const WEAK = 'weak';

    /**
     * @return array<int, array{client_id:int, contact_id:?int, name:string, email:?string, phone:?string, reasons:array<int,string>, strength:string}>
     */
    public function findCandidates(int $company_id, ?string $email, ?string $phone, ?string $name, ?string $address = null): array
    {
        $candidates = [];

        $add = function (Client $client, ?ClientContact $contact, string $reason, string $strength) use (&$candidates) {
            $key = $client->id;

            if (! isset($candidates[$key])) {
                $candidates[$key] = [
                    'client_id' => $client->id,
                    'contact_id' => $contact?->id,
                    'name' => $client->present()->name(),
                    'email' => $contact?->email ?: $client->contacts->first()?->email,
                    'phone' => $contact?->phone ?: $client->phone,
                    'reasons' => [],
                    'strength' => $strength,
                ];
            }

            $candidates[$key]['reasons'][] = $reason;

            if ($strength === self::STRONG) {
                $candidates[$key]['strength'] = self::STRONG;
                $candidates[$key]['contact_id'] ??= $contact?->id;
            }
        };

        $email = $email ? Str::lower(trim($email)) : null;

        if ($email) {
            ClientContact::query()
                ->where('company_id', $company_id)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->whereHas('client', fn ($q) => $q->where('is_deleted', false))
                ->with('client.contacts')
                ->limit(10)
                ->get()
                ->each(fn (ClientContact $c) => $add($c->client, $c, 'Same email address', self::STRONG));
        }

        $tail = $this->phoneTail($phone);

        if ($tail) {
            $like = '%' . substr($tail, -7) . '%'; // coarse SQL filter, refined in PHP below

            ClientContact::query()
                ->where('company_id', $company_id)
                ->where('phone', 'like', $like)
                ->whereHas('client', fn ($q) => $q->where('is_deleted', false))
                ->with('client.contacts')
                ->limit(25)
                ->get()
                ->filter(fn (ClientContact $c) => $this->phoneTail($c->phone) === $tail)
                ->each(fn (ClientContact $c) => $add($c->client, $c, 'Same phone number', self::STRONG));

            Client::query()
                ->where('company_id', $company_id)
                ->where('is_deleted', false)
                ->where('phone', 'like', $like)
                ->with('contacts')
                ->limit(25)
                ->get()
                ->filter(fn (Client $c) => $this->phoneTail($c->phone) === $tail)
                ->each(fn (Client $c) => $add($c, $c->contacts->first(), 'Same phone number', self::STRONG));
        }

        $normalisedName = $this->normaliseName($name);

        if (strlen($normalisedName) >= 4) {
            $firstToken = explode(' ', $normalisedName)[0];

            Client::query()
                ->where('company_id', $company_id)
                ->where('is_deleted', false)
                ->where(function ($q) use ($firstToken) {
                    $q->where('name', 'like', "%{$firstToken}%")
                        ->orWhereHas('contacts', fn ($c) => $c->where('first_name', 'like', "%{$firstToken}%")->orWhere('last_name', 'like', "%{$firstToken}%"));
                })
                ->with('contacts')
                ->limit(50)
                ->get()
                ->each(function (Client $client) use ($normalisedName, $address, $add) {
                    $names = [$client->name];

                    foreach ($client->contacts as $contact) {
                        $names[] = trim($contact->first_name . ' ' . $contact->last_name);
                    }

                    foreach (array_filter($names) as $candidateName) {
                        similar_text($normalisedName, $this->normaliseName($candidateName), $percent);

                        if ($percent >= 85) {
                            $sameAddress = $address && $client->address1
                                && $this->normaliseName($address) === $this->normaliseName($client->address1);

                            $add($client, $client->contacts->first(), $sameAddress ? 'Similar name and same address' : 'Similar name', $sameAddress ? self::STRONG : self::WEAK);

                            return;
                        }
                    }
                });
        }

        $candidates = array_values($candidates);

        foreach ($candidates as &$c) {
            $c['reasons'] = array_values(array_unique($c['reasons']));
        }

        usort($candidates, fn ($a, $b) => [$b['strength'] === self::STRONG, count($b['reasons'])] <=> [$a['strength'] === self::STRONG, count($a['reasons'])]);

        return $candidates;
    }

    /**
     * Returns the single client that can be linked automatically, or null when
     * there is no strong match or the match is ambiguous (left for staff review).
     */
    public function autoMatch(array $candidates): ?array
    {
        $strong = array_values(array_filter($candidates, fn ($c) => $c['strength'] === self::STRONG));

        return count($strong) === 1 ? $strong[0] : null;
    }

    /**
     * Creates a client + primary contact through the existing Invoice Ninja repository.
     */
    public function createClient(Company $company, int $user_id, array $data): Client
    {
        [$first, $last] = $this->splitName($data['customer_name']);

        $client = app(ClientRepository::class)->save([
            'name' => $data['customer_name'],
            'phone' => $data['phone'] ?? '',
            'address1' => $data['address'] ?? '',
            'contacts' => [[
                'first_name' => $first,
                'last_name' => $last,
                'email' => $data['email'] ?? '',
                'phone' => $data['phone'] ?? '',
                'send_email' => true,
            ]],
        ], ClientFactory::create($company->id, $user_id));

        return $client->fresh(['contacts']);
    }

    public function phoneTail(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= 7 ? substr($digits, -10) : null;
    }

    public function normaliseName(?string $value): string
    {
        $value = Str::lower(Str::ascii((string) $value));
        $value = preg_replace('/[^a-z0-9 ]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    public function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [$name];
        $last = count($parts) > 1 ? array_pop($parts) : '';

        return [implode(' ', $parts), $last];
    }
}
