{{--
    Grouped bar chart: revenue (series 1) vs expenses (series 2) per period.
    Props: $trend = [['label','revenue','expenses'], ...]
    One y-axis, recessive grid, 2px gap between bars, 4px rounded data ends,
    per-column hover tooltip, legend + data table fallback.
--}}
@php
    use App\Services\Ops\OpsUi;
    $rows = $trend ?? [];
    $n = max(count($rows), 1);
    $max = max(1, collect($rows)->flatMap(fn ($r) => [$r['revenue'], $r['expenses']])->max() ?? 1);
    // nice ceiling
    $mag = pow(10, floor(log10($max)));
    $ceil = ceil($max / $mag) * $mag;
    $W = 760; $H = 260; $padL = 64; $padB = 30; $padT = 10; $padR = 8;
    $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;
    $colW = $plotW / $n;
    $barW = max(2, min(26, ($colW - 6) / 2 - 1));
    $labelEvery = max(1, (int) ceil($n / 12));
    $y = fn ($v) => $padT + $plotH - ($v / $ceil) * $plotH;
@endphp
<div class="legend" style="margin-bottom:8px">
    <span><i style="background:var(--series-1)"></i>Revenue (payments received)</span>
    <span><i style="background:var(--series-2)"></i>Expenses</span>
</div>
@if (! count($rows) || collect($rows)->every(fn ($r) => $r['revenue'] == 0 && $r['expenses'] == 0))
    <div class="empty">No payments or expenses in this period.</div>
@else
<svg class="chart" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="Revenue and expenses by period">
    @for ($i = 0; $i <= 4; $i++)
        @php $v = $ceil * $i / 4; $yy = $y($v); @endphp
        <line class="grid-line" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $yy }}" y2="{{ $yy }}"></line>
        <text x="{{ $padL - 8 }}" y="{{ $yy + 4 }}" text-anchor="end">{{ number_format($v >= 1000 ? $v / 1000 : $v, $v >= 1000 ? 1 : 0) }}{{ $v >= 1000 ? 'k' : '' }}</text>
    @endfor
    <line class="axis" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $padT + $plotH }}" y2="{{ $padT + $plotH }}"></line>
    @foreach ($rows as $i => $r)
        @php
            $cx = $padL + $colW * $i + $colW / 2;
            $x1 = $cx - $barW - 1; $x2 = $cx + 1;
            $h1 = $plotH * $r['revenue'] / $ceil; $h2 = $plotH * $r['expenses'] / $ceil;
            $tip = $r['label'] . ' — Revenue ' . OpsUi::money($r['revenue']) . ' · Expenses ' . OpsUi::money($r['expenses']) . ' · Net ' . OpsUi::money($r['revenue'] - $r['expenses']);
        @endphp
        <g class="col" data-tip="{{ $tip }}">
            <rect class="hit" x="{{ $padL + $colW * $i }}" y="{{ $padT }}" width="{{ $colW }}" height="{{ $plotH }}"></rect>
            @if ($h1 > 0)<path class="bar-1" d="M{{ $x1 }},{{ $padT + $plotH }} v{{ -max($h1 - 4, 0) }} q0,-{{ min(4, $h1) }} {{ min(4, $barW / 2) }},-{{ min(4, $h1) }} h{{ $barW - 2 * min(4, $barW / 2) }} q{{ min(4, $barW / 2) }},0 {{ min(4, $barW / 2) }},{{ min(4, $h1) }} v{{ max($h1 - 4, 0) }} z"></path>@endif
            @if ($h2 > 0)<path class="bar-2" d="M{{ $x2 }},{{ $padT + $plotH }} v{{ -max($h2 - 4, 0) }} q0,-{{ min(4, $h2) }} {{ min(4, $barW / 2) }},-{{ min(4, $h2) }} h{{ $barW - 2 * min(4, $barW / 2) }} q{{ min(4, $barW / 2) }},0 {{ min(4, $barW / 2) }},{{ min(4, $h2) }} v{{ max($h2 - 4, 0) }} z"></path>@endif
            @if ($i % $labelEvery === 0)
                @php
                    $short = match (true) {
                        (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['label']) => \Illuminate\Support\Carbon::parse($r['label'])->format('M j'),
                        (bool) preg_match('/^\d{4}-\d{2}$/', $r['label']) => \Illuminate\Support\Carbon::parse($r['label'] . '-01')->format('M y'),
                        default => $r['label'],
                    };
                @endphp
                <text x="{{ $cx }}" y="{{ $H - 10 }}" text-anchor="middle">{{ $short }}</text>
            @endif
        </g>
    @endforeach
</svg>
@endif
<details class="data-table">
    <summary>Show data table</summary>
    <div class="table-wrap">
        <table class="t">
            <thead><tr><th>Period</th><th class="num">Revenue</th><th class="num">Expenses</th><th class="num">Net</th></tr></thead>
            <tbody>
            @foreach ($rows as $r)
                <tr><td>{{ $r['label'] }}</td><td class="num">{{ OpsUi::money($r['revenue']) }}</td><td class="num">{{ OpsUi::money($r['expenses']) }}</td><td class="num">{{ OpsUi::money($r['revenue'] - $r['expenses']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
</details>
