@extends('ops::layouts.app')
@use('App\Services\Ops\OpsAccess')
@section('title', 'Users & Roles')

@section('content')
<div class="page-head">
    <div><h1>Users &amp; roles</h1><p>Operations roles are enforced on the server for every page, action and API call. Invoice Ninja owners and admins always have full access. Create users in the Invoice Ninja admin app (Settings → User Management).</p></div>
</div>
<div class="card mb">
    <div class="table-wrap"><table class="t">
        <thead><tr><th>User</th><th>Current access</th><th>Assign operations role</th></tr></thead>
        <tbody>
        @foreach ($users as $cu)
            <tr>
                <td><strong>{{ $cu->user->present()->name() }}</strong><br><small>{{ $cu->user->email }}</small>@if ($cu->is_locked)<br><span class="badge b-bad">Locked</span>@endif</td>
                <td>{{ OpsAccess::roleLabel($cu) }}@if ($profiles->has($cu->user_id))<br><small>Technician profile ✓</small>@endif</td>
                <td>
                    @if ($cu->is_owner)
                        <small class="muted">Account owner — full access</small>
                    @elseif ($cu->user_id === $opsUser->id)
                        <small class="muted">You cannot change your own role</small>
                    @else
                    <form method="POST" action="{{ route('ops.admin.users.update', $cu->user_id) }}" class="actions">
                        @csrf @method('PUT')
                        <select name="ops_role" aria-label="Role" style="width:auto">
                            <option value="">No operations access</option>
                            @foreach (config('pcfix.roles') as $k => $r)<option value="{{ $k }}" @selected($cu->ops_role === $k)>{{ $r['label'] }}</option>@endforeach
                        </select>
                        @unless ($cu->is_admin)<label class="check" style="font-weight:400" data-tip="Adds the matching Invoice Ninja permissions (e.g. invoices for Accounting). Existing permissions are never removed."><input type="checkbox" name="apply_native" value="1"> Also grant matching Invoice Ninja permissions</label>@endunless
                        <button class="btn btn-sm">Save</button>
                    </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>

<div class="card">
    <div class="card-head"><h2>Role permissions matrix</h2></div>
    <div class="table-wrap"><table class="t" style="font-size:12.5px">
        <thead><tr><th>Ability</th>@foreach (config('pcfix.roles') as $r)<th style="text-align:center">{{ $r['label'] }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach (config('pcfix.abilities') as $ability => $label)
            <tr><td>{{ $label }}<br><small class="mono">{{ $ability }}</small></td>
                @foreach (config('pcfix.roles') as $r)<td style="text-align:center">{!! in_array('*', $r['abilities']) || in_array($ability, $r['abilities']) ? '<span aria-label="Allowed">✓</span>' : '<span class="muted" aria-label="Not allowed">—</span>' !!}</td>@endforeach
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>
@endsection
