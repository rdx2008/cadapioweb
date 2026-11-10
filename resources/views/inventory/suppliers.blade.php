@extends('layout')
@section('page-label','Fornecedores')
@push('styles')<link rel="stylesheet" href="/inventory.css?v=4">@endpush
@section('content')
<div class="inventory-page"><div class="heading"><h1>Fornecedores</h1><p>Organize seus parceiros de compra por loja.</p></div>@include('inventory.nav')
<form class="fields card" method="get"><label>Loja<select name="store_id">@foreach($stores as $unit)<option value="{{ $unit->id }}" @selected($store?->id===$unit->id)>{{ $unit->name }}</option>@endforeach</select></label><button>Ver fornecedores</button></form>
@if($store)
<details class="card" @if($suppliers->isEmpty()||($errors->any()&&!old('supplier_edit'))) open @endif><summary>+ Cadastrar fornecedor</summary>@include('inventory.supplier-form',['supplier'=>null])</details>
@forelse($suppliers as $supplier)<section class="card"><div class="section-head"><h2>{{ $supplier->name }}</h2><span class="badge">{{ $supplier->active?'Ativo':'Inativo' }}</span></div><p>{{ $supplier->document }} · {{ $supplier->phone }} · {{ $supplier->email }}</p><details @if(old('supplier_edit')==$supplier->id) open @endif><summary>Editar fornecedor</summary>@include('inventory.supplier-form')</details></section>@empty<p>Nenhum fornecedor cadastrado nesta loja.</p>@endforelse
{{ $suppliers->links() }}
@else<p>Cadastre uma loja para começar.</p>@endif</div>
@endsection
