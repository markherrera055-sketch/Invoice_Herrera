<form method="GET" class="filters no-print">
    <div class="field"><label for="period">Period</label>
        <select id="period" name="period" onchange="this.form.querySelectorAll('.custom').forEach(e => e.style.display = this.value === 'custom' ? '' : 'none')">
            @foreach (['this_year' => 'This year', 'this_month' => 'This month', 'last_month' => 'Last month', 'last_year' => 'Last year', 'custom' => 'Custom range'] as $k => $l)
                <option value="{{ $k }}" @selected(request('period', 'this_year') === $k)>{{ $l }}</option>
            @endforeach
        </select></div>
    <div class="field custom" style="{{ request('period') === 'custom' ? '' : 'display:none' }}"><label for="pf-from">From</label><input id="pf-from" type="date" name="from" value="{{ $metrics->from()->toDateString() }}"></div>
    <div class="field custom" style="{{ request('period') === 'custom' ? '' : 'display:none' }}"><label for="pf-to">To</label><input id="pf-to" type="date" name="to" value="{{ $metrics->to()->toDateString() }}"></div>
    @if ($withGrain ?? false)
        <div class="field"><label for="grain">Group by</label><select id="grain" name="grain">@foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $k => $l)<option value="{{ $k }}" @selected(($grain ?? 'month') === $k)>{{ $l }}</option>@endforeach</select></div>
    @endif
    <button class="btn">Apply</button>
</form>
