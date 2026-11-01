@extends('layout')
@section('page-label','PDV')
@push('styles')<link rel="stylesheet" href="/menu.css?v=3"><link rel="stylesheet" href="/pos.css?v=1">@endpush
@section('content')
<div class="heading"><h1>Frente de caixa</h1><p>{{ $store->name }} · Balcão / retirada</p><a href="{{ route('pos.index') }}">Trocar loja</a></div>
<div class="pos-page menu-page"><form method="post" action="{{ route('pos.prepare',$store->id) }}" data-menu-cart>@csrf
<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',(string)Illuminate\Support\Str::uuid()) }}">
<input type="hidden" name="mode" value="pickup" data-mode><input type="hidden" data-delivery-address>
@php($cartSeed=['cart_lines'=>old('cart_lines'),'items'=>[],'choices'=>[],'variants'=>[]])
<div data-cart-payload></div><script type="application/json" data-cart-seed>@json($cartSeed)</script><p data-cart-message role="status" aria-live="polite"></p>
<div class="menu-layout"><div class="menu-catalog"><div class="catalog-tools"><nav class="category-pills" aria-label="Categorias">@foreach($products->groupBy('category') as $category=>$rows)<a href="#category-{{ $loop->index }}">{{ $category }}</a>@endforeach</nav><label class="menu-search">Buscar produto<input type="search" data-menu-search placeholder="Nome do produto"></label></div><p data-search-empty hidden class="empty">Nenhum produto encontrado.</p>
@include('partials.menu-products')
</div><aside class="menu-bag"><h2>Venda atual <span data-menu-count>0</span></h2><div data-menu-lines class="bag-lines"></div><div class="bag-totals"><div><span>Produtos</span><strong data-menu-subtotal>R$ 0,00</strong></div><div><span>Frete</span><strong data-menu-freight>Sem frete</strong></div><div class="bag-total"><span>Total estimado</span><strong data-menu-total>R$ 0,00</strong></div></div><span data-mobile-total hidden></span>
<section id="checkout"><label>Nome / identificação (opcional)<input name="customer_name" maxlength="120" value="{{ old('customer_name') }}" placeholder="Ex.: João · retirada"></label><label>Telefone (opcional)<input name="phone" minlength="8" maxlength="25" value="{{ old('phone') }}" inputmode="tel"></label>
<label>Forma de pagamento<select name="payment_method" data-payment-method required>@foreach($methods as $value=>$label)<option value="{{ $value }}" @selected(old('payment_method')===$value)>{{ ['cash'=>'Dinheiro','pix'=>'Pix conferido no balcão','card'=>'Cartão na maquininha'][$value] }}</option>@endforeach</select></label>
<label data-cash-change>Valor em dinheiro / troco para (opcional)<input name="change_for" inputmode="decimal" maxlength="10" value="{{ old('change_for',old('change_for_cents')?number_format(old('change_for_cents')/100,2,',',''):'') }}" placeholder="Ex.: 50,00"></label>
<label>Recebimento<select name="received" required><option value="no" @selected(old('received','no')==='no')>Ainda não recebi · cobrar na retirada</option><option value="yes" @selected(old('received')==='yes')>Já recebi e conferi o pagamento</option></select></label><p class="muted">Pix e cartão são registros manuais, sem cobrança automática ou consulta ao banco/maquininha. Confira o pagamento antes de registrar como recebido.</p>
<button @disabled(!$methods||$products->isEmpty())>Revisar venda →</button></section></aside></div></form></div>
@endsection
