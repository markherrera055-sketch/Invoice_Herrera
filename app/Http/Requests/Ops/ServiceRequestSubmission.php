<?php

namespace App\Http\Requests\Ops;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for service requests from the public form, the client portal
 * and staff intake (phone / walk-in). Inputs are length-limited and trimmed;
 * HTML is escaped at output by Blade.
 */
class ServiceRequestSubmission extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is decided by the route (public / auth:contact / ops ability)
    }

    public function rules(): array
    {
        $portal = $this->routeIs('client.ops.*');

        return [
            'customer_name' => [$portal ? 'nullable' : 'required', 'string', 'max:120'],
            'email' => [$portal ? 'nullable' : 'required_without:phone', 'nullable', 'email:rfc', 'max:191'],
            'phone' => [$portal ? 'nullable' : 'required_without:email', 'nullable', 'string', 'max:40', 'regex:/^[0-9+()\-\s]{7,40}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'device_type' => ['required', Rule::in(config('pcfix.device_types'))],
            'device_brand' => ['required', 'string', 'max:100'],
            'device_model' => ['nullable', 'string', 'max:150'],
            'serial_number' => ['nullable', 'string', 'max:150', 'regex:/^[A-Za-z0-9\-\/\. ]*$/'],
            'reported_issue' => ['required', 'string', 'min:10', 'max:3000'],
            'preferred_service_date' => ['nullable', 'date', 'after_or_equal:today', 'before:+1 year'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'branch' => ['nullable', Rule::in(config('pcfix.branches'))],
            'priority' => ['nullable', Rule::in(array_keys(config('pcfix.priorities')))],
            'channel' => ['nullable', Rule::in(['phone', 'walk_in', 'email', 'online'])],
            'website' => ['nullable', 'max:0'], // honeypot: must stay empty
        ];
    }

    public function messages(): array
    {
        return [
            'email.required_without' => 'Provide an email address or a contact number.',
            'phone.required_without' => 'Provide a contact number or an email address.',
            'phone.regex' => 'Enter a valid contact number.',
            'serial_number.regex' => 'Serial numbers may contain letters, numbers, dashes, slashes, dots and spaces only.',
            'reported_issue.min' => 'Please describe the issue in at least 10 characters.',
            'website.max' => 'Submission rejected.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (['customer_name', 'email', 'phone', 'address', 'device_brand', 'device_model', 'serial_number', 'reported_issue', 'notes'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $clean[$field] = trim(strip_tags($this->input($field)));
            }
        }

        if (isset($clean['email'])) {
            $clean['email'] = strtolower($clean['email']);
        }

        if (isset($clean['serial_number'])) {
            $clean['serial_number'] = strtoupper($clean['serial_number']);
        }

        $this->merge($clean);
    }

    /** Only whitelisted service request attributes. */
    public function payload(): array
    {
        return collect($this->validated())->only([
            'customer_name', 'email', 'phone', 'address', 'device_type', 'device_brand', 'device_model',
            'serial_number', 'reported_issue', 'preferred_service_date', 'notes', 'branch', 'priority',
        ])->filter(fn ($v) => $v !== null && $v !== '')->all();
    }
}
