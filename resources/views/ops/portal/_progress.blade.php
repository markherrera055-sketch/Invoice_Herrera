{{-- Customer-facing progress + quotations + timeline. Props: $job (nullable), $quotes, $timeline --}}
@use('App\Services\Ops\OpsUi')
@php
    $order = ['pending' => 1, 'awaiting_approval' => 1, 'approved' => 2, 'assigned' => 2, 'in_progress' => 3, 'waiting_for_parts' => 3, 'completed' => 4];
    $at = $job ? ($order[$job->status] ?? 0) : 0;
    $openQuote = $quotes->first(fn ($l) => $l->quote && (int) $l->quote->status_id === \App\Models\Quote::STATUS_SENT);
@endphp
@if ($job && $job->status !== 'cancelled')
<div class="card"><div class="card-body">
    <ol class="steps">
        @foreach (['Request received', 'Quotation', 'Approved & scheduled', 'Repair in progress', 'Completed'] as $i => $label)
            <li class="{{ $i <= $at ? 'done' : '' }}">{{ $label }}</li>
        @endforeach
    </ol>
</div></div>
@endif

@if ($openQuote)
    <div class="alert"><strong>Action needed:</strong> quotation {{ $openQuote->quote->number }} ({{ OpsUi::money($openQuote->quote->amount, $openQuote->quote->client->getSetting('currency_id')) }}) is waiting for your approval.
        <a class="btn btn-primary" href="{{ route('client.quote.show', $openQuote->quote->hashed_id) }}" style="margin-left:8px">Review &amp; approve</a></div>
@endif

@if ($quotes->count())
<div class="card">
    <div class="card-head"><h2>Quotations</h2></div>
    <div class="wrap"><table>
        <thead><tr><th>Quote</th><th>Type</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody>
        @foreach ($quotes as $l)
            @continue(! $l->quote || ! $l->quote->number)
            <tr>
                <td><a href="{{ route('client.quote.show', $l->quote->hashed_id) }}">{{ $l->quote->number }}</a></td>
                <td>{{ $l->type === 'revision' ? 'Additional work' : 'Repair' }}</td>
                <td>{{ OpsUi::money($l->quote->amount, $l->quote->client->getSetting('currency_id')) }}</td>
                <td>{!! OpsUi::quoteStatus($l->quote) !!}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>
@endif

@if ($timeline->count())
<div class="card">
    <div class="card-head"><h2>Repair updates</h2></div>
    <div class="card-body">
        <ul class="timeline">
            @foreach ($timeline as $u)
                <li><small>{{ $u->created_at->format('M j, Y g:i A') }}</small><br>
                    @if ($u->to_status)<strong>{{ config('pcfix.repair_statuses')[$u->to_status] ?? $u->to_status }}</strong><br>@endif
                    <span style="white-space:pre-line">{{ $u->note }}</span></li>
            @endforeach
        </ul>
    </div>
</div>
@endif
