@extends('ops::layouts.app')
@section('title', 'Technician Assignments')

@section('content')
@php $manage = in_array('technicians.manage', $opsAbilities); @endphp
<div class="page-head">
    <div><h1>Technicians</h1><p>Availability, skills and workload used by automatic assignment. Users appear here once they have the Technician role (Users &amp; Roles).</p></div>
</div>
<div class="card">
    <div class="table-wrap"><table class="t">
        <thead><tr><th>Technician</th><th class="num">Active jobs</th><th class="num">Completed (30d)</th><th>Availability · skills · capacity</th></tr></thead>
        <tbody>
        @forelse ($users as $cu)
            @php $p = $profiles->get($cu->user_id); $active = $workload[$cu->user_id] ?? 0; @endphp
            <tr>
                <td><strong>{{ $cu->user->present()->name() }}</strong><br><small>{{ $cu->user->email }}</small><br>
                    @if (! $p)<span class="badge b-warn">No profile — not auto-assigned</span>
                    @elseif (! $p->is_available)<span class="badge">Unavailable</span>
                    @elseif ($active >= $p->max_active_jobs)<span class="badge b-serious">At capacity</span>
                    @else<span class="badge b-good">Available</span>@endif
                </td>
                <td class="num">{{ $active }} / {{ $p->max_active_jobs ?? '—' }}</td>
                <td class="num">{{ $completed[$cu->user_id] ?? 0 }}</td>
                <td>
                    @if ($manage)
                    <form method="POST" action="{{ route('ops.technicians.update', $cu->user_id) }}">
                        @csrf @method('PUT')
                        <div class="actions" style="margin-bottom:6px">
                            <label class="check"><input type="checkbox" name="is_available" value="1" @checked($p?->is_available ?? true)> Available</label>
                            <select name="branch" aria-label="Branch" style="width:auto"><option value="">Branch…</option>@foreach (config('pcfix.branches') as $b)<option @selected($p?->branch === $b)>{{ $b }}</option>@endforeach</select>
                            <label class="check">Max jobs <input type="number" name="max_active_jobs" value="{{ $p->max_active_jobs ?? 5 }}" min="1" max="30" style="width:70px"></label>
                        </div>
                        <div class="actions">
                            @foreach (config('pcfix.skills') as $k => $label)
                                <label class="check"><input type="checkbox" name="skills[]" value="{{ $k }}" @checked(in_array($k, $p?->skills ?? []))> {{ $label }}</label>
                            @endforeach
                            <button class="btn btn-sm">Save</button>
                        </div>
                    </form>
                    @else
                        {{ $p ? collect($p->skills)->map(fn ($s) => config('pcfix.skills')[$s] ?? $s)->implode(', ') : '—' }}
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty">No users with the Technician role yet. Assign roles under Users &amp; Roles.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@endsection
