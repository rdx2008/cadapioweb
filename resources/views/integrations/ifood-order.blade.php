@extends('layout')
@section('page-label','Pedido iFood de teste')
@section('content')
<div class="page-heading"><div><h1>Pedido iFood #{{ $order->display_id }}</h1><p>{{ $order->customer_name }} · {{ $order->order_type==='DELIVERY'?'Entrega':'Retirada' }}</p></div><span class="badge pending">Teste</span></div>
@php($labels=['pending'=>'Recebido','accepted'=>'Confirmado no iFood','preparing'=>'Em preparo','ready'=>'Pronto','dispatched'=>'Em entrega','completed'=>'Concluído','cancelled'=>'Cancelado'])
<p><span class="badge {{ $order->status }}">{{ $labels[$order->status] }}</span></p>
<a class="subtle-link" href="{{ route('ifood.owner') }}">← Voltar aos pedidos iFood</a>
<div class="notice">Pedido recebido para validar a integração. Nenhuma ação operacional está disponível nesta etapa. O status é atualizado pelos eventos recebidos do iFood.</div>
<div class="two"><section class="card"><h2>Itens do pedido</h2>@foreach($snapshot['items'] as $item)<div class="review-line"><strong>{{ $item['quantity'] }} × {{ $item['name'] }}</strong><span>R$ {{ number_format($item['total_cents']/100,2,',','.') }}</span></div>@foreach($item['options'] as $option)<p class="compact muted">{{ $option['quantity'] }} × {{ $option['name'] }}</p>@endforeach
@if($item['observations'])<p class="compact">Observação: {{ $item['observations'] }}</p>@endif
@endforeach</section><section class="card"><h2>Valores informados pelo iFood</h2><p>Itens: R$ {{ number_format($snapshot['totals']['subTotal']/100,2,',','.') }}</p><p>Entrega: R$ {{ number_format($snapshot['totals']['deliveryFee']/100,2,',','.') }}</p><p>Taxas adicionais: R$ {{ number_format($snapshot['totals']['additionalFees']/100,2,',','.') }}</p><p>Benefícios: R$ {{ number_format($snapshot['totals']['benefits']/100,2,',','.') }}</p><h3>Total: R$ {{ number_format($order->total_cents/100,2,',','.') }}</h3><p>Pago online: R$ {{ number_format($order->prepaid_cents/100,2,',','.') }}</p><p>Pendente na entrega/retirada: R$ {{ number_format($order->pending_cents/100,2,',','.') }}</p><p class="muted compact">Esses valores não comprovam repasse à loja e não foram registrados como recebimento manual.</p>@if($order->preparation_at)<p>Preparo previsto: {{ Illuminate\Support\Carbon::parse($order->preparation_at)->timezone($timezone)->format('d/m/Y H:i') }}</p>@endif</section></div>
@endsection
