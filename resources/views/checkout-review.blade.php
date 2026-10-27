@extends('menu-layout')
@section('content')
<div class="auth">
<div class="heading">
<div class="eyebrow">{{ $store->name }}</div>
<h1>Confira seu pedido.</h1>
@if($scheduleLabel=app(App\Services\OrderScheduling::class)->label((object)$draft['quote']))<p class="badge">Agendado: {{ $scheduleLabel }}<br>Preparo a partir de {{ Illuminate\Support\Carbon::parse($draft['quote']['prepare_at'],'UTC')->timezone($draft['quote']['scheduled_timezone'])->format('d/m H:i') }}</p>@endif
<p class="muted">Seu pedido só será enviado após a confirmação abaixo.</p>
</div>
<div class="card">@foreach($draft['quote']['items'] as $item)<div class="review-line">
<strong>{{ $item['quantity'] }} × {{ $item['name'] }}</strong>
<span>R$ {{ number_format($item['quantity']*$item['unit_cents']/100,2,',','.') }}</span>
</div>@foreach($item['options'] as $option)<div class="compact muted">{{ $option['group'] }}: {{ $option['name'] }} @if(($option['kind']??'')!=='description')({{ ($option['kind']??'')==='variant' ? 'preço base R$ ' : '+ R$ ' }}{{ number_format($option['price_cents']/100,2,',','.') }} por unidade)@endif</div>@endforeach
@endforeach
<hr>
<div class="review-line">
<span>Produtos e adicionais</span>
<strong>R$ {{ number_format($draft['quote']['subtotal_cents']/100,2,',','.') }}</strong>
</div>
<div class="review-line">
<span class="freight-row">@include('partials.motoboy') Frete</span>
<strong>R$ {{ number_format($draft['quote']['fee_cents']/100,2,',','.') }}</strong>
</div>
<div class="review-line">
<h2>Total</h2>
<h2>R$ {{ number_format($draft['quote']['total_cents']/100,2,',','.') }}</h2>
</div>
<p>
<strong>{{ $draft['data']['customer_name'] }}</strong>
<br>{{ $draft['data']['phone'] }}</p>
@if($draft['data']['mode']==='delivery')<p>Entrega em {{ $draft['quote']['delivery_zone_name'] }}<br>{{ $draft['quote']['delivery_address'] ?? $draft['data']['address'] }}</p>@else<p>Retirada na loja: {{ $store->address }}</p>@endif
<p class="muted">Pagamento presencial, na entrega ou retirada. Revisão válida por 15 minutos.</p>
<form method="post" action="/cardapio/{{ $store->slug }}">@csrf<input type="hidden" name="draft_id" value="{{ $draftId }}">
<div class="actions">
<button class="orange">Confirmar e enviar pedido</button>
<a class="button secondary" href="/cardapio/{{ $store->slug }}?edit={{ $draftId }}">Editar carrinho</a>
</div>
</form>
</div>
</div>
@endsection
