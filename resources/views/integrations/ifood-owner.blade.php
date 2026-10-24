@extends('layout')
@section('page-label','Integração iFood')
@section('content')
<div class="page-heading"><div><h1>Seu restaurante no iFood</h1><p>Acompanhe os vínculos e a última consulta de cada loja.</p></div><span class="badge pending">Em implantação · teste</span></div>
<div class="notice">Esta área recebe somente pedidos de teste quando habilitada pelo superadmin. Aceite, preparo, cancelamento e despacho pelo sistema ainda não estão disponíveis. Os valores não integram o financeiro nem geram corridas freelancer.</div>
<div class="grid">@forelse($connections as $connection)@include('integrations.ifood-connection',['isAdmin'=>false])@empty<section class="card full"><h2>Nenhuma loja vinculada</h2><p class="muted">Solicite ao administrador da plataforma o vínculo da sua loja de teste. As credenciais do aplicativo são administradas centralmente.</p><a class="button secondary" href="/dashboard#lojas">Ver minhas lojas</a></section>@endforelse</div>
<section class="card"><div class="section-head"><h2>Pedidos iFood de teste</h2><span class="badge pending">Recepção em validação</span></div><p class="live-status" data-ifood-connection role="status">Atualização da lista a cada 5 segundos com a página ativa.</p><div data-ifood-feed data-url="{{ route('ifood.orders.live').'?'.http_build_query(request()->only('page')) }}">@include('integrations.ifood-orders')</div></section>
@endsection
