@extends('layout')
@section('page-label','Detalhes do pedido')
@section('content')<div class="heading"><h1>Pedido #{{ $order->id }}</h1><a href="/operation">← Voltar à operação</a></div><section class="card"><h2>{{ $order->mode==='delivery'?'Pedido para entrega':'Pedido para retirada' }}</h2><p>{{ ['pending'=>'Novo','accepted'=>'Aceito','preparing'=>'Em preparo','ready'=>'Pronto','completed'=>'Concluído','cancelled'=>'Cancelado'][$order->status]??$order->status }}</p>
@if($staff->staff_profile==='operator')<p>{{ $order->customer_name }}<br>{{ $order->phone }}<br>{{ $order->address }}</p><p>Total R$ {{ number_format($order->total_cents/100,2,',','.') }} · {{ $order->payment_status==='paid'?'Recebido':'A receber' }}</p>@endif
@foreach($items as $item)<div class="card"><strong>{{ $item->quantity }} × {{ $item->name }}</strong>@foreach(json_decode($item->options??'[]',true) as $option)<p>{{ $option['group'] }}: {{ $option['name'] }}</p>@endforeach</div>@endforeach</section>@endsection
