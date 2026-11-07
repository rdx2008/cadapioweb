@extends('layout')
@section('page-label','Estoque e insumos')
@push('styles')<link rel="stylesheet" href="/inventory.css?v=1">@endpush
@section('content')
@php($quantity=fn($v)=>App\Services\Inventory::format($v))
<div class="inventory-page">
<div class="heading"><h1>Estoque e insumos</h1><p>Controle o que entra, o que sai e o que precisa repor.</p></div>
<form method="get" class="fields card"><label>Loja<select name="store_id">@foreach($stores as $unit)<option value="{{ $unit->id }}" @selected($store?->id===$unit->id)>{{ $unit->name }}</option>@endforeach</select></label><button>Ver estoque</button></form>
@if($store)
<p class="notice">Controle manual nesta etapa. Vendas e cancelamentos ainda não movimentam insumos automaticamente. Use a unidade cadastrada; não há conversão entre kg e g ou entre l e ml.</p>
<div class="grid"><section class="card"><small>Insumos cadastrados</small><h2>{{ $items->total() }}</h2></section><section class="card"><small>Ativos no mínimo ou abaixo</small><h2>{{ $lowCount }}</h2><p>Confira os itens sinalizados para reposição.</p></section></div>
<details class="card" @if($items->isEmpty()||($errors->any()&&!old('movement_item')&&!old('edit_item'))) open @endif><summary>+ Cadastrar insumo</summary><form class="fields" action="{{ route('inventory.create') }}" method="post">@csrf<input type="hidden" name="store_id" value="{{ $store->id }}"><input type="hidden" name="active" value="1"><label>Nome<input name="name" required maxlength="120" value="{{ old('name') }}" placeholder="Ex.: Farinha de trigo"></label><label>Unidade<select name="unit">@foreach(App\Services\Inventory::UNITS as $value=>$label)<option value="{{ $value }}" @selected(old('unit','kg')===$value)>{{ $label }}</option>@endforeach</select></label><label>Estoque mínimo<input name="minimum" required inputmode="decimal" maxlength="15" value="{{ old('minimum','0') }}" placeholder="Ex.: 5,000"></label><p>O saldo começa em zero. Registre uma entrada com a quantidade inicial depois de cadastrar.</p><button>Cadastrar insumo</button></form></details>
<h2>Insumos desta loja</h2><p>Até três casas decimais, sem separador de milhares. Ex.: 1,250 kg. Corrija contagens registrando a diferença como entrada ou saída, com motivo.</p>
@forelse($items as $item)
@include('inventory.item')
@empty<section class="card"><p>Cadastre seu primeiro insumo para começar.</p></section>@endforelse
{{ $items->links() }}
<section class="card" id="historico"><h2>Histórico de movimentações</h2>@if(request('item'))<p>Filtrando um insumo. <a href="{{ route('inventory.index',['store_id'=>$store->id]) }}#historico">Ver todos</a></p>@endif<div class="table-scroll"><table><thead><tr><th>Data / responsável</th><th>Insumo</th><th>Movimento</th><th>Saldo após</th><th>Motivo</th></tr></thead><tbody>@forelse($history as $movement)<tr><td>{{ Illuminate\Support\Carbon::parse($movement->created_at)->timezone($store->timezone)->format('d/m/Y H:i') }}<br>{{ $movement->operator_name }}</td><td>{{ $movement->item_name }}</td><td>{{ $movement->kind==='in'?'Entrada':'Saída' }} · {{ $quantity($movement->quantity) }} {{ $movement->unit }}</td><td>{{ $quantity($movement->balance_after) }} {{ $movement->unit }}</td><td>{{ $movement->reason }}</td></tr>@empty<tr><td colspan="5">Nenhuma movimentação registrada.</td></tr>@endforelse</tbody></table></div>{{ $history->fragment('historico')->links() }}<p>Movimentações são preservadas. Correções exigem um novo movimento com motivo.</p></section>
@else<section class="card"><p>Cadastre uma loja para começar a controlar os insumos.</p><a href="/dashboard#lojas">Minhas lojas</a></section>@endif
</div>
@endsection
