{{-- Horizontal single-series bars. Props: $items = [['label','value']], $unit (string), $empty (string) --}}
@php $max = max(1, collect($items)->max('value') ?? 1); @endphp
@forelse ($items as $item)
    <div class="hbar" data-tip="{{ $item['label'] }}: {{ number_format($item['value']) }} {{ $unit ?? '' }}">
        <span title="{{ $item['label'] }}" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $item['label'] }}</span>
        <span class="track"><span class="fill" style="display:block;width:{{ round($item['value'] / $max * 100, 1) }}%"></span></span>
        <span class="num">{{ number_format($item['value']) }}</span>
    </div>
@empty
    <div class="empty">{{ $empty ?? 'No data for this period.' }}</div>
@endforelse
