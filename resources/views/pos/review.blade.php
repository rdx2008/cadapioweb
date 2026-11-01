@extends('pos.layout')
@section('page-label','Revisar venda no PDV')
@section('content')
@php($quote=$draft['quote'])
<div class="pos-result"><h1>Confira antes de confirmar</h1><p>{{ $store->name }} · {{ $draft['data']['mode']==='delivery'?'Entrega':'Retirada' }}</p></div>
<section class="card"><h2>{{ $draft['data']['customer_name'] }}</h2>
<p>Origem: {{ ['counter'=>'Balcão','phone'=>'Telefone','whatsapp'=>'WhatsApp'][$draft['data']['pos_channel']] }}</p>
@if($draft['data']['mode']==='delivery')<p>{{ empty($draft['data']['preferred_driver_id'])?'Motoboy: solicitar fila automática após ficar pronto':'Motoboy específico escolhido. Será necessário solicitar o despacho e aguardar o aceite.' }}</p>@endif
@foreach($quote['items'] as $item)<p><strong>{{ $item['quantity'] }} × {{ $item['name'] }}</strong> · R$ {{ number_format($item['quantity']*$item['unit_cents']/100,2,',','.') }}@foreach($item['options'] as $option)<br><small>{{ $option['group'] }}: {{ $option['name'] }}</small>@endforeach</p>@endforeach
<p>Frete: R$ {{ number_format($quote['fee_cents']/100,2,',','.') }}</p>@if($draft['data']['mode']==='delivery')<p>{{ $quote['delivery_address'] }}</p>@endif
<h2>Total: R$ {{ number_format($quote['total_cents']/100,2,',','.') }}</h2>
<p>Forma: {{ (App\Services\CheckoutPayment::LABELS+App\Services\OnlinePayment::LABELS)[$quote['payment_method']] }}</p>
<p><strong>{{ str_starts_with($quote['payment_method'],'online_')?'Pagamento por link · aguardar confirmação do provedor':($draft['data']['received']==='yes'?'Recebimento manual confirmado pelo operador':'Pagamento pendente · cobrar na entrega ou retirada') }}</strong></p>
@if($quote['change_for_cents'])<p>Dinheiro informado: R$ {{ number_format($quote['change_for_cents']/100,2,',','.') }} · Troco: R$ {{ number_format(($quote['change_for_cents']-$quote['total_cents'])/100,2,',','.') }}</p>@endif
<p>Confirmar registra o pedido. Para pagamento por link, compartilhe o link na próxima tela e aguarde a confirmação antes do preparo. O motoboy só recebe a oferta no despacho, quando estiver pronto.</p>
<form method="post" action="{{ route('pos.confirm',[$store->id,$key]) }}">@csrf<input type="hidden" name="review_hash" value="{{ hash('sha256',json_encode($draft,JSON_THROW_ON_ERROR)) }}"><button>Confirmar pedido</button></form>
<form method="post" action="{{ route('pos.edit',[$store->id,$key]) }}">@csrf<button class="secondary">Voltar e editar</button></form></section>
@endsection
