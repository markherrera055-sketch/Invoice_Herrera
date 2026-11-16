{{--
    Quotation line editor. Posts lines[n][kind|product|description|quantity|price].
    Props: $products (collection), $prefill (bool: add diagnostic fee + labor rows)
--}}
@php $uid = 'le' . \Illuminate\Support\Str::random(6); @endphp
<div class="lines" id="{{ $uid }}">
    <div class="line muted" style="font-size:12px;font-weight:600">
        <span>Type</span><span class="desc">Item / description</span><span>Qty</span><span>Unit price</span><span></span>
    </div>
    @if ($prefill ?? true)
        @include('ops::partials.line_row', ['i' => 0, 'kind' => 'fee', 'desc' => 'Diagnostic / service fee', 'qty' => 1, 'price' => config('pcfix.defaults.diagnostic_fee')])
        @include('ops::partials.line_row', ['i' => 1, 'kind' => 'labor', 'desc' => 'Repair labor (hours)', 'qty' => 1, 'price' => config('pcfix.defaults.labor_rate')])
    @else
        @include('ops::partials.line_row', ['i' => 0, 'kind' => 'part', 'desc' => '', 'qty' => 1, 'price' => ''])
    @endif
</div>
<button type="button" class="btn btn-sm" onclick="pcfixAddLine('{{ $uid }}')">+ Add line</button>
<small class="muted">Part prices default to the inventory selling price when left blank.</small>

<template id="{{ $uid }}-tpl">
    @include('ops::partials.line_row', ['i' => '__I__', 'kind' => 'part', 'desc' => '', 'qty' => 1, 'price' => ''])
</template>

@once
@push('scripts')
<script>
function pcfixAddLine(id) {
  var wrap = document.getElementById(id);
  var html = document.getElementById(id + '-tpl').innerHTML.replace(/__I__/g, 'n' + Date.now());
  wrap.insertAdjacentHTML('beforeend', html);
}
function pcfixKind(sel) {
  var row = sel.closest('.line');
  row.querySelector('.p-part').style.display = sel.value === 'part' ? '' : 'none';
  row.querySelector('.p-desc').style.display = sel.value === 'part' ? 'none' : '';
}
</script>
@endpush
@endonce

