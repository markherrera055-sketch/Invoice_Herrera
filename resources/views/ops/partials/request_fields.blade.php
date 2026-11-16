{{-- Service request fields. Props: $sr (optional model), $withCustomer (bool), $staff (bool) --}}
@php $v = fn ($k, $d = '') => old($k, $sr->{$k} ?? $d); @endphp
@if ($withCustomer ?? true)
<div class="form-row">
    <div class="field"><label for="customer_name">Full name *</label><input id="customer_name" type="text" name="customer_name" value="{{ $v('customer_name') }}" required maxlength="120" autocomplete="name"></div>
    <div class="field"><label for="email">Email address</label><input id="email" type="email" name="email" value="{{ $v('email') }}" maxlength="191" autocomplete="email"><div class="hint">Email or contact number is required.</div></div>
    <div class="field"><label for="phone">Contact number</label><input id="phone" type="tel" name="phone" value="{{ $v('phone') }}" maxlength="40" placeholder="09XX XXX XXXX" autocomplete="tel"></div>
</div>
<div class="field"><label for="address">Address</label><input id="address" type="text" name="address" value="{{ $v('address') }}" maxlength="255" autocomplete="street-address"></div>
@endif
<div class="form-row">
    <div class="field"><label for="device_type">Device type *</label>
        <select id="device_type" name="device_type" required>
            <option value="">Select…</option>
            @foreach (config('pcfix.device_types') as $t)<option @selected($v('device_type') === $t)>{{ $t }}</option>@endforeach
        </select>
    </div>
    <div class="field"><label for="device_brand">Brand *</label><input id="device_brand" type="text" name="device_brand" value="{{ $v('device_brand') }}" required maxlength="100" placeholder="e.g. Lenovo"></div>
    <div class="field"><label for="device_model">Model</label><input id="device_model" type="text" name="device_model" value="{{ $v('device_model') }}" maxlength="150" placeholder="e.g. ThinkPad E14"></div>
    <div class="field"><label for="serial_number">Serial number</label><input id="serial_number" type="text" name="serial_number" value="{{ $v('serial_number') }}" maxlength="150"><div class="hint">Helps us find your warranty and repair history.</div></div>
</div>
<div class="field"><label for="reported_issue">Describe the problem *</label><textarea id="reported_issue" name="reported_issue" required minlength="10" maxlength="3000" placeholder="What happens, since when, any error messages…">{{ $v('reported_issue') }}</textarea></div>
<div class="form-row">
    <div class="field"><label for="preferred_service_date">Preferred service date</label><input id="preferred_service_date" type="date" name="preferred_service_date" value="{{ old('preferred_service_date', optional($sr->preferred_service_date ?? null)->toDateString()) }}" min="{{ now()->toDateString() }}"></div>
    <div class="field"><label for="branch">Preferred branch</label>
        <select id="branch" name="branch">
            <option value="">No preference</option>
            @foreach (config('pcfix.branches') as $b)<option @selected($v('branch') === $b)>{{ $b }}</option>@endforeach
        </select>
    </div>
    @if ($staff ?? false)
        <div class="field"><label for="priority">Priority</label>
            <select id="priority" name="priority">
                @foreach (array_keys(config('pcfix.priorities')) as $p)<option value="{{ $p }}" @selected($v('priority', 'normal') === $p)>{{ ucfirst($p) }}</option>@endforeach
            </select>
        </div>
    @endif
</div>
<div class="field"><label for="notes">Additional notes</label><textarea id="notes" name="notes" maxlength="2000" style="min-height:60px">{{ $v('notes') }}</textarea></div>
<div class="hp" aria-hidden="true"><label for="website">Leave this empty</label><input id="website" type="text" name="website" tabindex="-1" autocomplete="off"></div>
