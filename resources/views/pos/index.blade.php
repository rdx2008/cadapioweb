@extends('pos.layout')
@section('page-label','PDV')
@section('content')
<div class="pos-result"><h1>PDV · Frente de caixa</h1><p>Escolha a loja para iniciar uma venda de balcão ou retirada.</p></div>
<div class="grid">@forelse($stores as $store)<section class="card"><h2>{{ $store->name }}</h2><a class="button" href="{{ route('pos.sale',$store->id) }}">Iniciar venda →</a></section>@empty<p class="empty">Nenhuma loja liberada para seu acesso.</p>@endforelse</div>
<p class="muted">Abra o caixa da loja antes de registrar recebimentos presenciais. Você pode acompanhar movimentos e fechar o turno pelo atalho Caixa no topo.</p>
@endsection
