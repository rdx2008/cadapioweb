@extends('pos.layout')
@section('page-label','Revisar venda no PDV')
@section('content')
@php($quote=$draft['quote'])
<div class="pos-result"><h1>Confira antes de confirmar</h1><p>{{ $store->name }} · {{ App\Support\OrderMode::label($draft['data']['mode'],$draft['data']['dining_label']??null) }}</p></div>
<section class="card"><h2>{{ $draft['data']['customer_name'] }}</h2>
<p>Origem: {{ $draft['data']['mode']==='dine_in'?'Mesa / salão':['counter'=>'Balcão','phone'=>'Telefone','whatsapp'=>'WhatsApp'][$draft['data']['pos_channel']] }}</p>
@if($draft['data']['mode']==='delivery')<p>{{ empty($draft['data']['preferred_driver_id'])?'Motoboy: solicitar fila automática após ficar pronto':'Motoboy específico escolhido. Será necessário solicitar o despacho e aguardar o aceite.' }}</p>@endif
@foreach($quote['items'] as $item)<p><strong>{{ $item['quantity'] }} × {{ $item['name'] }}</strong> · R$ {{ number_format($item['quantity']*$item['unit_cents']/100,2,',','.') }}@foreach($item['options'] as $option)<br><small>{{ $option['group'] }}: {{ $option['name'] }}</small>@endforeach</p>@endforeach
<p>Frete: R$ {{ number_format($quote['fee_cents']/100,2,',','.') }}</p>@if($draft['data']['mode']==='delivery')<p>{{ $quote['delivery_address'] }}</p>@endif
<h2>Total: R$ {{ number_format($quote['total_cents']/100,2,',','.') }}</h2>
<p>Forma: {{ $draft['data']['mode']==='dine_in'?['cash'=>'Dinheiro','pix'=>'Pix conferido','card'=>'Maquininha'][$quote['payment_method']]:(App\Services\CheckoutPayment::LABELS+App\Services\OnlinePayment::LABELS)[$quote['payment_method']] }}</p>
<p><strong>{{ str_starts_with($quote['payment_method'],'online_')?'Pagamento por link · aguardar confirmação do provedor':($draft['data']['received']==='yes'?'Recebimento manual confirmado pelo operador':'Pagamento pendente · cobrar ao finalizar o atendimento') }}</strong></p>
@if($quote['change_for_cents'])<p>Dinheiro informado: R$ {{ number_format($quote['change_for_cents']/100,2,',','.') }} · Troco: R$ {{ number_format(($quote['change_for_cents']-$quote['total_cents'])/100,2,',','.') }}</p>@endif
@if($draft['data']['mode']==='dine_in')<p>Confirmar envia este lançamento à cozinha e acrescenta o consumo à comanda da mesa. Confira o recebimento ao finalizar o atendimento.</p>@else
<p>Confirmar registra o pedido. Para pagamento por link, compartilhe o link na próxima tela e aguarde a confirmação antes do preparo. O motoboy só recebe a oferta no despacho, quando estiver pronto.</p>
@endif
<form method="post" action="{{ route('pos.confirm',[$store->id,$key]) }}">@csrf<input type="hidden" name="review_hash" value="{{ hash('sha256',json_encode($draft,JSON_THROW_ON_ERROR)) }}"><button>Confirmar pedido</button></form>
<form method="post" action="{{ route('pos.edit',[$store->id,$key]) }}">@csrf<button class="secondary">Voltar e editar</button></form></section>
@endsection
