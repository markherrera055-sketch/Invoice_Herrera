<div class="line">
    <select name="lines[{{ $i }}][kind]" onchange="pcfixKind(this)" aria-label="Line type">
        <option value="part" @selected($kind === 'part')>Part</option>
        <option value="labor" @selected($kind === 'labor')>Labor</option>
        <option value="fee" @selected($kind === 'fee')>Fee</option>
    </select>
    <div class="desc">
        <select class="p-part" name="lines[{{ $i }}][product]" style="{{ $kind === 'part' ? '' : 'display:none' }}" aria-label="Inventory item">
            <option value="">Select inventory item…</option>
            @foreach ($products as $p)
                <option value="{{ $p->hashed_id }}">{{ $p->product_key }} — {{ \Illuminate\Support\Str::limit($p->notes, 40) }} · {{ number_format((float) $p->price, 2) }}{{ $p->track_stock ? ' · stock ' . $p->in_stock_quantity : '' }}</option>
            @endforeach
        </select>
        <input class="p-desc" type="text" name="lines[{{ $i }}][description]" value="{{ $desc }}" placeholder="Description" style="{{ $kind === 'part' ? 'display:none' : '' }}" maxlength="255" aria-label="Description">
    </div>
    <input type="number" name="lines[{{ $i }}][quantity]" value="{{ $qty }}" min="0" step="0.25" aria-label="Quantity">
    <input type="number" name="lines[{{ $i }}][price]" value="{{ $price }}" min="0" step="0.01" placeholder="auto" aria-label="Unit price">
    <button type="button" class="btn btn-sm" onclick="this.closest('.line').remove()" aria-label="Remove line">✕</button>
</div>
