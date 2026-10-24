@extends('layout')
@section('content')
<div class="auth" data-tracking-url="/pedido/{{ $order->public_id }}/status">
<div class="heading">
<div class="eyebrow">Acompanhe seu pedido</div>
<h1>Pedido #{{ $order->id }}</h1>
<p class="muted">Guarde este link. O andamento é atualizado automaticamente enquanto esta página estiver aberta.</p>
</div>
<div class="card">
<span class="badge" data-tracking-status>{{ ['pending'=>'Aguardando aceite','accepted'=>'Aceito','preparing'=>'Em preparo','ready'=>'Pronto','completed'=>'Concluído','cancelled'=>'Cancelado'][$order->status]??$order->status }}</span>
@foreach($items as $item)<div class="review-line">
<strong>{{ $item->quantity }} × {{ $item->name }}</strong>
<span>R$ {{ number_format($item->quantity*$item->unit_cents/100,2,',','.') }}</span>
</div>@foreach(json_decode($item->options??'[]',true) as $option)<p class="compact muted">{{ $option['group'] }}: {{ $option['name'] }}</p>@endforeach
@endforeach<hr>
<p>Entrega: R$ {{ number_format($order->fee_cents/100,2,',','.') }}</p>
<h2>Total: R$ {{ number_format($order->total_cents/100,2,',','.') }}</h2>
<p>Pagamento: <span data-tracking-payment>{{ $order->payment_status==='paid'?'Recebido':'Na entrega ou retirada' }}</span>
</p>
<p>Entrega: <span data-tracking-ride>{{ $ride->status??'Aguardando despacho ou retirada' }}</span>
</p>
<p data-cancel-reason>{{ $order->cancellation_reason }}</p>
<p class="muted compact" data-tracking-connection role="status">Consultando andamento…</p>
</div>
</div>
@endsection
