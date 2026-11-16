@extends('ops::layouts.app')
@section('title', 'Notifications')

@section('content')
<div class="page-head">
    <div><h1>Notifications</h1><p>Workflow alerts sent to you. Each event notifies you once.</p></div>
    <form method="POST" action="{{ route('ops.notifications.read') }}">@csrf<button class="btn">Mark all read</button></form>
</div>
<div class="card">
    <div class="table-wrap"><table class="t">
        <thead><tr><th>When</th><th>Notification</th><th>Type</th></tr></thead>
        <tbody>
        @forelse ($items as $n)
            <tr>
                <td style="white-space:nowrap">{{ $n->created_at->format('M j, Y g:i A') }}<br><small>{{ $n->read_at ? 'Read' : 'Unread' }}</small></td>
                <td>@if ($n->url)<a class="row-link" href="{{ $n->url }}">{{ $n->title }}</a>@else<strong>{{ $n->title }}</strong>@endif<br><small>{{ $n->body }}</small></td>
                <td><span class="badge">{{ str_replace('_', ' ', $n->type) }}</span></td>
            </tr>
        @empty
            <tr><td colspan="3" class="empty">You have no notifications.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $items->links('ops::partials.pagination') }}
</div>
@endsection
