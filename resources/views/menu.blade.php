@extends('layout')
@section('content')
<div class="hero">
<div>
<div class="eyebrow">Feito para o seu momento</div>
<h1>{{ $store->name }}</h1>
<p class="muted">{{ $store->address }}</p>
<span class="badge {{ $isOpen?'':'disabled' }}">{{ $isOpen?'Recebendo pedidos':'Fechado ou pausado no momento' }}</span>
</div>
<div class="orb">✦</div>
</div>
<form method="post" action="/cardapio/{{ $store->slug }}/review" data-cart>@csrf
<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) Illuminate\Support\Str::uuid()) }}">
<div class="section-head">
<h2>Escolha seus favoritos</h2>
<span class="muted">Pagamento na retirada ou entrega</span>
</div>
@foreach($products->groupBy('category') as $category=>$categoryProducts)<section>
<h2>{{ $category }}</h2>
<div class="grid">
@foreach($categoryProducts as $product)<article class="card product" data-cart-product data-price="{{ $product->price_cents }}">
@if($product->photo_path)<img class="product-photo" src="/media/products/{{ $product->id }}?v={{ urlencode($product->updated_at) }}" alt="{{ $product->name }}" loading="lazy">@else<div class="art">✦</div>@endif
<h2>{{ $product->name }}</h2>
<p class="muted">{{ $product->description }}</p>
<div class="price">R$ {{ number_format($product->price_cents/100,2,',','.') }}</div>
<label>Quantidade<input data-quantity name="items[{{ $product->id }}]" type="number" min="0" max="50" value="{{ old('items.'.$product->id,0) }}">
</label>
@foreach($groups->get($product->id,collect()) as $group)<fieldset class="option-group">
<legend>{{ $group->name }} <small>Escolha {{ $group->minimum }}–{{ $group->maximum }}</small>
</legend>
@forelse($options->get($group->id,collect()) as $option)<label class="option-choice">
<input type="checkbox" data-option-price="{{ $option->price_cents }}" name="choices[{{ $product->id }}][{{ $group->id }}][]" value="{{ $option->id }}" @checked(in_array($option->id,old('choices.'.$product->id.'.'.$group->id,[])))>
<span>{{ $option->name }} · + R$ {{ number_format($option->price_cents/100,2,',','.') }}</span>
</label>@empty<p class="muted">Sem opções disponíveis neste grupo.</p>@endforelse</fieldset>@endforeach
</article>@endforeach</div>
</section>@endforeach
@if($products->isEmpty())<div class="empty">O cardápio está sendo preparado. Volte em breve.</div>@endif
<section class="card">
<h2>Como vamos entregar seu pedido?</h2>
<div class="fields">
<label>Seu nome<input name="customer_name" value="{{ old('customer_name') }}" required maxlength="120" autocomplete="name">
</label>
<label>Telefone<input name="phone" value="{{ old('phone') }}" required minlength="8" maxlength="25" autocomplete="tel">
</label>
<label>Modalidade<select name="mode" data-mode>
<option value="pickup" @selected(old('mode')!=='delivery')>Retirada · sem frete</option>@if($zones->isNotEmpty())<option value="delivery" @selected(old('mode')==='delivery')>Entrega</option>@endif</select>
</label>
<label>Região de entrega<select name="delivery_zone_id" data-zone>
<option value="">Selecione para entrega</option>@foreach($zones as $zone)<option value="{{ $zone->id }}" data-fee="{{ $zone->fee_cents }}" @selected(old('delivery_zone_id')==$zone->id)>{{ $zone->name }} · R$ {{ number_format($zone->fee_cents/100,2,',','.') }}</option>@endforeach</select>
</label>
<label class="full">Endereço completo (para entrega)<input name="address" value="{{ old('address') }}" maxlength="500" autocomplete="street-address" data-delivery-address>
</label>
</div>
@if($zones->isEmpty())<p class="muted">Esta loja oferece somente retirada enquanto não houver regiões de entrega ativas.</p>@endif
<p class="muted">Pedido mínimo em produtos: R$ {{ number_format($store->minimum_order_cents/100,2,',','.') }}. O frete é separado da remuneração do entregador.</p>
<div class="cart-summary">
<strong>Total estimado: <span data-cart-total>—</span>
</strong>
<span class="muted">Você revisará o total calculado pelo servidor antes de confirmar.</span>
</div>
<button class="orange" @disabled(!$isOpen || $products->isEmpty())>Revisar pedido →</button>
</section>
</form>
@endsection
