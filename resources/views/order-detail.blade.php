@extends('layout')
@section('content')
<div class="heading">
<div class="eyebrow">Operação da loja</div>
<h1>Pedido #{{ $order->id }}</h1>
@include('partials.payment-details',['payment'=>$order])
@if($scheduleLabel=app(App\Services\OrderScheduling::class)->label($order))<p class="badge">Agendado: {{ $scheduleLabel }}<br>Preparo a partir de {{ Illuminate\Support\Carbon::parse($order->prepare_at,'UTC')->timezone($order->scheduled_timezone)->format('d/m H:i') }}</p>@endif
<a href="/dashboard#pedidos">← Voltar aos pedidos</a>
</div>
<div class="two">
@include('partials.online-payment',['owner'=>true])
<section class="card">
<h2>Cliente e atendimento</h2>
<span class="badge">{{ $order->status }}</span>
<p>{{ $order->customer_name }}<br>{{ $order->phone }}</p>
<p>{{ $order->mode==='delivery'?'Entrega':'Retirada' }}</p>@if($order->mode==='delivery')<p>{{ $order->delivery_zone_name }}<br>{{ $order->address }}</p>@endif<p>Pagamento: {{ $order->payment_status }} · {{ $order->payment_method }}</p>@if($order->cancellation_reason)<p>Motivo do cancelamento: {{ $order->cancellation_reason }}</p>@endif</section>
<section class="card">
<h2>Itens da venda</h2>@foreach($items as $item)<div class="review-line">
<strong>{{ $item->quantity }} × {{ $item->name }}</strong>
<span>R$ {{ number_format($item->quantity*$item->unit_cents/100,2,',','.') }}</span>
</div>@foreach(json_decode($item->options??'[]',true) as $option)<p class="compact muted">{{ $option['group'] }}: {{ $option['name'] }} (+ R$ {{ number_format($option['price_cents']/100,2,',','.') }} por unidade)</p>@endforeach
@endforeach<hr>
<p>Frete: R$ {{ number_format($order->fee_cents/100,2,',','.') }}</p>
<h2>Total: R$ {{ number_format($order->total_cents/100,2,',','.') }}</h2>
</section>
</div>
@endsection
