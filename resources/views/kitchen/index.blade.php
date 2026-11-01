@extends('layout')
@section('page-label','Cozinha')
@section('content')
<div class="heading"><h1>Fila da cozinha</h1><p class="muted">Pedidos aceitos, preparo e prontos para conferência. A fila prioriza o horário previsto de preparo e, nos pedidos imediatos, o aceite mais antigo.</p></div>
<form method="get" action="{{ route('kitchen.index') }}" class="card fields">
<label>Loja<select name="store_id"><option value="">Todas as lojas autorizadas</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected(($filters['store_id']??'')==$store->id)>{{ $store->name }}</option>@endforeach</select></label>
<label>Etapa<select name="stage">@foreach(['accepted'=>'Aguardando preparo','preparing'=>'Em preparo','ready'=>'Prontos para conferência'] as $value=>$label)<option value="{{ $value }}" @selected($stage===$value)>{{ $label }}</option>@endforeach</select></label><button>Atualizar fila</button></form>
<p data-staff-connection role="status">Atualização automática com a página ativa.</p>
<div data-staff-feed data-url="{{ route('kitchen.live',request()->only(['store_id','stage','page'])) }}">@include('kitchen.orders')</div>
@endsection
