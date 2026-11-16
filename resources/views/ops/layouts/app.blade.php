@php
    use App\Services\Ops\OpsAccess;
    $can = fn ($a) => in_array($a, $opsAbilities ?? [], true);
    $is = fn (...$patterns) => request()->routeIs(...$patterns) ? 'active' : '';
    $nav = [
        'Overview' => [
            ['Dashboard', 'ops.dashboard', 'ops.dashboard', 'dashboard.view'],
        ],
        'Customers' => [
            ['Customer List', 'ops.customers.index', 'ops.customers.*', 'service_requests.view'],
            ['Service Requests', 'ops.service_requests.index', 'ops.service_requests.*', 'service_requests.view'],
        ],
        'Repair Management' => [
            [$can('repair_jobs.view_all') ? 'Repair Jobs' : 'My Repair Jobs', 'ops.repair_jobs.index', ['ops.repair_jobs.index', 'ops.repair_jobs.show'], ['repair_jobs.view_all', 'repair_jobs.work']],
            ['Technician Assignments', 'ops.technicians.index', 'ops.technicians.*', 'repair_jobs.view_all'],
            ['Repair History', 'ops.repair_jobs.history', 'ops.repair_jobs.history', ['repair_jobs.view_all', 'repair_jobs.work']],
        ],
        'Sales & Billing' => [
            ['Billing & Payments', 'ops.billing.index', 'ops.billing.*', 'billing.view'],
        ],
        'Warranty' => [
            ['Warranty Records', 'ops.warranty.index', ['ops.warranty.index', 'ops.warranty.show', 'ops.warranty.check'], 'warranty.view'],
            ['Warranty Claims', 'ops.warranty.claims.index', 'ops.warranty.claims.*', 'warranty.view'],
        ],
        'Inventory' => [
            ['Inventory Items', 'ops.inventory.index', ['ops.inventory.index', 'ops.inventory.show', 'ops.inventory.create', 'ops.inventory.edit'], 'inventory.view'],
            ['Stock Movements', 'ops.inventory.movements', 'ops.inventory.movements', 'inventory.view'],
            ['Low Stock', 'ops.inventory.low', 'ops.inventory.low', 'inventory.view'],
        ],
        'Procurement' => [
            ['Suppliers', 'ops.suppliers.index', 'ops.suppliers.*', 'suppliers.manage'],
            ['Purchase Requests', 'ops.purchase_requests.index', 'ops.purchase_requests.*', ['purchase_requests.create', 'purchase_requests.approve', 'purchase_requests.receive']],
            ['Purchase Records', 'ops.purchase_orders.index', 'ops.purchase_orders.*', 'purchase_requests.receive'],
        ],
        'Finance' => [
            ['Expenses', 'ops.finance.expenses', 'ops.finance.expenses', 'expenses.manage'],
            ['Financial Reports', 'ops.finance.reports', 'ops.finance.reports', 'reports.financial'],
        ],
        'Administration' => [
            ['Users & Roles', 'ops.admin.users', 'ops.admin.users', 'admin.users'],
            ['Settings', 'ops.admin.settings', 'ops.admin.settings', 'admin.users'],
            ['Audit Log', 'ops.admin.audit', 'ops.admin.audit', 'audit.view'],
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Operations') · PCFix Hub</title>
    <link rel="stylesheet" href="{{ asset('css/pcfix-ops.css') }}?v=1">
</head>
<body>
<input type="checkbox" id="nav-toggle" hidden>
<div class="shell">
    <aside class="sidebar" aria-label="Main navigation">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">PC</span>
            <span>PCFix Hub<small>{{ $opsCompany->present()->name() }}</small></span>
        </div>
        <nav class="nav">
            @foreach ($nav as $group => $items)
                @php $visible = array_filter($items, fn ($i) => collect((array) $i[3])->contains(fn ($a) => $can($a))); @endphp
                @if ($visible)
                    <div class="nav-group">{{ $group }}</div>
                    @foreach ($visible as [$label, $route, $pattern])
                        <a href="{{ route($route) }}" class="{{ $is(...(array) $pattern) }}">{{ $label }}</a>
                    @endforeach
                @endif
            @endforeach
            <div class="nav-group">Invoice Ninja</div>
            <a href="{{ url('/') }}" target="_blank" rel="noopener">Open main admin app ↗</a>
        </nav>
        <div class="nav-foot">
            <div>{{ $opsUser->present()->name() }}</div>
            <div class="muted" style="color:#8b93a3">{{ OpsAccess::roleLabel($opsCompanyUser) }}</div>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <label for="nav-toggle" class="btn btn-sm menu-toggle" aria-label="Open menu">☰</label>
            <form method="GET" action="{{ route('ops.repair_jobs.history') }}" class="no-print" role="search" style="flex:0 1 320px">
                <input type="search" name="q" placeholder="Search job, customer, serial…" aria-label="Search repair history">
            </form>
            <div class="spacer"></div>
            @if (($opsCompanies ?? collect())->count() > 1)
                <form method="POST" action="{{ route('ops.company.switch') }}">
                    @csrf
                    <select name="company_id" onchange="this.form.submit()" aria-label="Switch company">
                        @foreach ($opsCompanies as $c)
                            <option value="{{ $c->id }}" @selected($c->id === $opsCompany->id)>{{ $c->present()->name() }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
            <details class="bell">
                <summary aria-label="Notifications">🔔 @if ($opsUnread)<span class="dot">{{ $opsUnread }}</span>@endif</summary>
                <div class="panel">
                    @forelse ($opsNotifications as $n)
                        <a class="item {{ $n->read_at ? '' : 'unread' }}" href="{{ $n->url ?: route('ops.notifications') }}">
                            <strong>{{ $n->title }}</strong><br><small>{{ \Illuminate\Support\Str::limit($n->body, 110) }} · {{ $n->created_at->diffForHumans() }}</small>
                        </a>
                    @empty
                        <div class="empty">No notifications yet.</div>
                    @endforelse
                    <div style="display:flex;justify-content:space-between;padding:8px 14px">
                        <a href="{{ route('ops.notifications') }}">View all</a>
                        <form method="POST" action="{{ route('ops.notifications.read') }}">@csrf<button class="btn btn-sm">Mark all read</button></form>
                    </div>
                </div>
            </details>
            <form method="POST" action="{{ route('ops.logout') }}">@csrf<button class="btn btn-sm">Sign out</button></form>
        </header>

        <main class="content">
            @if (session('ops_success'))
                <div class="alert alert-success" role="status">{{ session('ops_success') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert-error" role="alert">
                    <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            @yield('content')
        </main>
    </div>
</div>
<div class="chart-tip" id="chart-tip" role="tooltip"></div>
<script>
// Shared hover tooltip for SVG / bar charts (elements with data-tip).
(function () {
  var tip = document.getElementById('chart-tip');
  document.addEventListener('mousemove', function (e) {
    var el = e.target.closest('[data-tip]');
    if (!el) { tip.style.display = 'none'; return; }
    tip.textContent = el.getAttribute('data-tip');
    tip.style.display = 'block';
    var x = e.clientX + 14, y = e.clientY + 14;
    if (x + tip.offsetWidth > window.innerWidth - 8) x = e.clientX - tip.offsetWidth - 14;
    tip.style.left = x + 'px'; tip.style.top = y + 'px';
  });
  document.addEventListener('mouseleave', function () { tip.style.display = 'none'; });
  // Confirm destructive / irreversible actions.
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });
})();
</script>
@stack('scripts')
</body>
</html>
