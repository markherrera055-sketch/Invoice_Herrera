@extends('ops::layouts.app')
@section('title', 'New Supplier')

@section('content')
<div class="page-head"><div><h1>New supplier</h1><p>Saved as an Invoice Ninja vendor, so it is also available for purchase orders and expenses there.</p></div></div>
<form method="POST" action="{{ route('ops.suppliers.store') }}" class="card">
    @csrf
    <div class="card-body">
        @include('ops::procurement.supplier_fields', ['vendor' => null, 'contact' => null])
        <button class="btn btn-primary">Create supplier</button>
        <a class="btn" href="{{ route('ops.suppliers.index') }}">Cancel</a>
    </div>
</form>
@endsection
