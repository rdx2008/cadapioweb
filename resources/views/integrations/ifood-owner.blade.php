@extends('layout')
@section('page-label','Integração iFood')
@section('content')
<div class="page-heading"><div><h1>Seu restaurante no iFood</h1><p>Acompanhe os vínculos e a última consulta de cada loja.</p></div><span class="badge pending">Em implantação · teste</span></div>
<div class="notice">Pedidos e catálogo ainda não são sincronizados. O superadmin precisa vincular sua loja de teste antes da validação da integração.</div>
<div class="grid">@forelse($connections as $connection)@include('integrations.ifood-connection',['isAdmin'=>false])@empty<section class="card full"><h2>Nenhuma loja vinculada</h2><p class="muted">Solicite ao administrador da plataforma o vínculo da sua loja de teste. As credenciais do aplicativo são administradas centralmente.</p><a class="button secondary" href="/dashboard#lojas">Ver minhas lojas</a></section>@endforelse</div>
@endsection
