<?php

namespace App\Services\Ops;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\User;
use App\Models\Ops\Device;
use App\Models\Ops\OpsSequence;
use App\Models\Ops\ServiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Module 1: standardised intake.
 *
 * Submit -> validate (FormRequest) -> duplicate-submission guard -> duplicate
 * customer check -> request created -> staff review / link customer ->
 * repair job + quotation.
 */
class ServiceRequestService
{
    public function __construct(private CustomerMatcher $matcher) {}

    /**
     * @return array{0: ServiceRequest, 1: bool} [request, wasDuplicateSubmission]
     */
    public function submit(Company $company, array $data, string $channel, ?ClientContact $contact = null, ?User $by = null, ?string $ip = null): array
    {
        $hash = $this->submissionHash($company->id, $data, $contact);

        $existing = ServiceRequest::query()
            ->forCompany($company->id)
            ->where('submission_hash', $hash)
            ->where('created_at', '>=', now()->subMinutes(config('pcfix.defaults.duplicate_submission_minutes')))
            ->first();

        if ($existing) {
            return [$existing, true];
        }

        $sr = DB::transaction(function () use ($company, $data, $channel, $contact, $by, $ip, $hash) {
            $sr = new ServiceRequest();
            $sr->fill($data);
            $sr->company_id = $company->id;
            $sr->user_id = $by?->id;
            $sr->channel = $channel;
            $sr->status = 'new';
            $sr->priority = $data['priority'] ?? 'normal';
            $sr->request_number = OpsSequence::next($company->id, 'SR');
            $sr->submission_hash = $hash;
            $sr->ip_address = $ip;

            if ($contact) {
                // Logged-in portal customer: identity is already known.
                $sr->client_id = $contact->client_id;
                $sr->client_contact_id = $contact->id;
            } else {
                $candidates = $this->matcher->findCandidates($company->id, $data['email'] ?? null, $data['phone'] ?? null, $data['customer_name'] ?? null, $data['address'] ?? null);
                $match = $this->matcher->autoMatch($candidates);

                if ($match) {
                    $sr->client_id = $match['client_id'];
                    $sr->client_contact_id = $match['contact_id'];
                }

                // Flag for review when there is anything other than one clean strong match.
                $others = array_values(array_filter($candidates, fn ($c) => ! $match || $c['client_id'] !== $match['client_id']));
                $sr->possible_duplicate = count($others) > 0;
                $sr->duplicate_candidates = $candidates ?: null;
            }

            $sr->save();

            if ($sr->client_id) {
                $sr->device_id = $this->resolveDevice($sr)->id;
                $sr->save();
            }

            AuditLogger::log($company->id, 'service_request.submitted', $sr, ['channel' => $channel, 'auto_linked' => (bool) $sr->client_id], $by?->id, $contact?->id);

            return $sr;
        });

        OpsNotifier::staff(
            $company,
            'service_requests.manage',
            'service_request',
            's' . $sr->id,
            "New service request {$sr->request_number}",
            "{$sr->customer_name} - {$sr->device_type} {$sr->device_brand} {$sr->device_model}: " . Str::limit($sr->reported_issue, 160) . ($sr->possible_duplicate ? ' [possible duplicate customer]' : ''),
            route('ops.service_requests.show', $sr->hashed_id),
        );

        if ($sr->contact) {
            OpsNotifier::contact($company, $sr->contact, 'service_request_received', 's' . $sr->id, "We received your request {$sr->request_number}", 'Thank you. Our team will review your ' . strtolower($sr->device_type) . ' issue and send you a quotation.', route('client.ops.service_requests.show', $sr->hashed_id));
        }

        return [$sr, false];
    }

    public function linkClient(ServiceRequest $sr, int $client_id, User $by): ServiceRequest
    {
        $client = Client::query()->where('company_id', $sr->company_id)->where('is_deleted', false)->whereKey($client_id)->first();

        if (! $client) {
            throw ValidationException::withMessages(['client_id' => 'Customer not found.']);
        }

        if ($sr->repairJob && (int) $sr->repairJob->client_id !== (int) $client->id) {
            throw ValidationException::withMessages(['client_id' => 'This request already has a repair job for a different customer.']);
        }

        DB::transaction(function () use ($sr, $client, $by) {
            $contact = $sr->email
                ? $client->contacts()->whereRaw('LOWER(email) = ?', [Str::lower($sr->email)])->first()
                : null;

            $sr->client_id = $client->id;
            $sr->client_contact_id = ($contact ?? $client->contacts()->first())?->id;
            $sr->possible_duplicate = false;
            $sr->status = $sr->status === 'new' ? 'under_review' : $sr->status;
            $sr->reviewed_by = $by->id;
            $sr->reviewed_at = now();
            $sr->device_id = $this->resolveDevice($sr)->id;
            $sr->save();

            AuditLogger::log($sr->company_id, 'service_request.linked_client', $sr, ['client_id' => $client->id], $by->id);
        });

        return $sr->fresh();
    }

    public function createClientFor(ServiceRequest $sr, User $by): ServiceRequest
    {
        if ($sr->client_id) {
            throw ValidationException::withMessages(['client' => 'This request is already linked to a customer.']);
        }

        $client = $this->matcher->createClient($sr->company, $by->id, $sr->only(['customer_name', 'email', 'phone', 'address']));

        AuditLogger::log($sr->company_id, 'client.created_from_request', $client, ['service_request' => $sr->request_number], $by->id);

        return $this->linkClient($sr, $client->id, $by);
    }

    public function update(ServiceRequest $sr, array $data, User $by): ServiceRequest
    {
        $before = $sr->only(array_keys($data));
        $sr->fill($data);
        $sr->status = $sr->status === 'new' ? 'under_review' : $sr->status;
        $sr->reviewed_by = $by->id;
        $sr->reviewed_at = now();
        $sr->save();

        if ($sr->device) {
            $sr->device->fill([
                'device_type' => $sr->device_type,
                'brand' => $sr->device_brand,
                'model' => $sr->device_model,
                'serial_number' => $sr->serial_number,
            ])->save();
        }

        AuditLogger::log($sr->company_id, 'service_request.updated', $sr, ['before' => $before, 'after' => $sr->only(array_keys($data))], $by->id);

        return $sr;
    }

    public function cancel(ServiceRequest $sr, User $by, string $reason): void
    {
        if (! $sr->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Only open requests can be cancelled.']);
        }

        $sr->status = 'cancelled';
        $sr->notes = trim(($sr->notes ? $sr->notes . "\n" : '') . 'Cancelled: ' . $reason);
        $sr->save();

        AuditLogger::log($sr->company_id, 'service_request.cancelled', $sr, ['reason' => $reason], $by->id);
    }

    /** Find the customer's device by serial number, or register it. */
    public function resolveDevice(ServiceRequest $sr): Device
    {
        $serial = $sr->serial_number ? trim($sr->serial_number) : null;

        $device = $serial
            ? Device::query()->forCompany($sr->company_id)->where('client_id', $sr->client_id)->where('serial_number', $serial)->first()
            : ($sr->device_id ? Device::query()->forCompany($sr->company_id)->where('client_id', $sr->client_id)->whereKey($sr->device_id)->first() : null);

        if (! $device) {
            $device = new Device();
            $device->company_id = $sr->company_id;
            $device->client_id = $sr->client_id;
        }

        $device->fill([
            'device_type' => $sr->device_type,
            'brand' => $sr->device_brand ?: $device->brand,
            'model' => $sr->device_model ?: $device->model,
            'serial_number' => $serial ?: $device->serial_number,
        ])->save();

        return $device;
    }

    private function submissionHash(int $company_id, array $data, ?ClientContact $contact): string
    {
        return hash('sha256', implode('|', [
            $company_id,
            $contact?->id ?? Str::lower(trim($data['email'] ?? '')),
            Str::lower(trim($data['serial_number'] ?? '')),
            Str::lower(preg_replace('/\s+/', ' ', trim($data['reported_issue'] ?? ''))),
        ]));
    }
}
