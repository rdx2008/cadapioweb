@extends('layout')
@section('page-label','Revisar venda no PDV')
@section('content')
@php($quote=$draft['quote'])
<div class="heading"><h1>Confira antes de confirmar</h1><p>{{ $store->name }} · Balcão / retirada</p></div>
<section class="card"><h2>{{ $draft['data']['customer_name'] }}</h2>
@foreach($quote['items'] as $item)<p><strong>{{ $item['quantity'] }} × {{ $item['name'] }}</strong> · R$ {{ number_format($item['quantity']*$item['unit_cents']/100,2,',','.') }}@foreach($item['options'] as $option)<br><small>{{ $option['group'] }}: {{ $option['name'] }}</small>@endforeach</p>@endforeach
<h2>Total: R$ {{ number_format($quote['total_cents']/100,2,',','.') }}</h2>
<p>Forma: {{ ['cash'=>'Dinheiro','pix'=>'Pix','card'=>'Cartão na maquininha'][$quote['payment_method']] }}</p>
<p><strong>{{ $draft['data']['received']==='yes'?'Recebimento manual confirmado pelo operador':'Pagamento pendente · cobrar na retirada' }}</strong></p>
@if($quote['change_for_cents'])<p>Dinheiro informado: R$ {{ number_format($quote['change_for_cents']/100,2,',','.') }} · Troco: R$ {{ number_format(($quote['change_for_cents']-$quote['total_cents'])/100,2,',','.') }}</p>@endif
<p>Confirmar registra a venda e envia o pedido aceito à cozinha. Não efetua cobrança no banco ou na maquininha.</p>
<form method="post" action="{{ route('pos.confirm',[$store->id,$key]) }}">@csrf<input type="hidden" name="review_hash" value="{{ hash('sha256',json_encode($draft,JSON_THROW_ON_ERROR)) }}"><button>Confirmar venda e enviar à cozinha</button></form>
<form method="post" action="{{ route('pos.edit',[$store->id,$key]) }}">@csrf<button class="secondary">Voltar e editar</button></form></section>
@endsection
