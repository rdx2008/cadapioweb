@extends('menu-layout')
@section('content')
<div class="auth" data-tracking-url="/pedido/{{ $order->public_id }}/status">
<div class="heading">
<div class="eyebrow">Acompanhe seu pedido</div>
<h1>Pedido #{{ $order->id }}</h1>
@include('partials.payment-details',['payment'=>$order])
@if($scheduleLabel=app(App\Services\OrderScheduling::class)->label($order))<p class="badge">Agendado: {{ $scheduleLabel }}<br>Preparo a partir de {{ Illuminate\Support\Carbon::parse($order->prepare_at,'UTC')->timezone($order->scheduled_timezone)->format('d/m H:i') }}</p>@endif
<p class="muted">Guarde este link. O andamento é atualizado automaticamente enquanto esta página estiver aberta.</p>
</div>
<div class="card">
@include('partials.online-payment')
<span class="badge" data-tracking-status>{{ ['pending'=>'Aguardando aceite','accepted'=>'Aceito','preparing'=>'Em preparo','ready'=>'Pronto','completed'=>'Concluído','cancelled'=>'Cancelado'][$order->status]??$order->status }}</span>
@foreach($items as $item)<div class="review-line">
<strong>{{ $item->quantity }} × {{ $item->name }}</strong>
<span>R$ {{ number_format($item->quantity*$item->unit_cents/100,2,',','.') }}</span>
</div>@foreach(json_decode($item->options??'[]',true) as $option)<p class="compact muted">{{ $option['group'] }}: {{ $option['name'] }}</p>@endforeach
@endforeach<hr>
<p class="freight-row">@include('partials.motoboy') Frete: R$ {{ number_format($order->fee_cents/100,2,',','.') }}</p>
<h2>Total: R$ {{ number_format($order->total_cents/100,2,',','.') }}</h2>
<p>Pagamento: <span data-tracking-payment>{{ $order->payment_status==='paid'?'Recebido':($order->payment_status==='review'?'Em conferência':(app(App\Services\OnlinePayment::class)->isOnline($order)?'Aguardando pagamento online':'Na entrega ou retirada')) }}</span>
</p>
<p>Entrega: <span data-tracking-ride>{{ $ride->status??'Aguardando despacho ou retirada' }}</span>
</p>
<p data-cancel-reason>{{ $order->cancellation_reason }}</p>
<p class="muted compact" data-tracking-connection role="status">Consultando andamento…</p>
</div>
</div>
@endsection
