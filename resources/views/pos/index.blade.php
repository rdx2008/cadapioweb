@extends('layout')
@section('page-label','PDV')
@section('content')
<div class="heading"><h1>PDV · Frente de caixa</h1><p>Escolha a loja para iniciar uma venda de balcão ou retirada.</p></div>
<div class="grid">@forelse($stores as $store)<section class="card"><h2>{{ $store->name }}</h2><a class="button" href="{{ route('pos.sale',$store->id) }}">Iniciar venda →</a></section>@empty<p class="empty">Nenhuma loja liberada para seu acesso.</p>@endforelse</div>
<p class="muted">Registre somente recebimentos conferidos no balcão. Abertura e fechamento de caixa, sangrias e suprimentos ainda não estão disponíveis.</p>
@endsection
