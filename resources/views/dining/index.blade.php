@extends('pos.layout')
@section('content')
<div class="cash-page"><div class="cash-heading"><div><span class="pill">ATENDIMENTO NO SALÃO</span><h1>Mesas e comandas</h1><p>Abra a mesa, lance os pedidos e acompanhe o consumo até o fechamento.</p></div>@if(!$waiter)<a class="button outline" href="{{ route('pos.index') }}">Ir para o PDV</a>@endif</div>
<form method="get" class="dining-filter"><label>Loja<select name="store_id">@foreach($stores as $unit)<option value="{{ $unit->id }}" @selected($unit->id===$store?->id)>{{ $unit->name }}</option>@endforeach</select></label><button>Ver mesas</button></form>
@include('dining.sound')
<p data-dining-connection role="status">Atualiza automaticamente com a página aberta.</p>
<div data-dining-live data-call-ids="{{ $calls->pluck('id')->values()->toJson() }}" data-audience="{{ $audience }}" data-url="{{ route('dining.live',['store_id'=>$store?->id,'page'=>request('page',1)]) }}">@include('dining.map')</div>
</div>
@endsection
