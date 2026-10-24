<!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title','CadapioWeb · Sua operação conectada')</title><link rel="stylesheet" href="/app.css?v=3"><script src="/app.js?v=3" defer></script></head>
<body><div class="shell">
<aside class="sidebar">
    <a class="brand" href="/app">cadapio<span>web</span></a>
    <div class="workspace-card"><span class="workspace-avatar">@include('partials.icon',['name'=>auth()->user()?->role==='admin'?'users':'store'])</span><div><strong>{{ auth()->user()?->role==='admin'?'Administração':($tenant->name??auth()->user()?->name??'Bem-vindo') }}</strong><small>{{ auth()->user()?->role==='admin'?'Controle da plataforma':(auth()->check()?'Seu espaço de trabalho':'Sua operação conectada') }}</small></div></div>
    <div class="workspace">{{ auth()->user()?->role==='admin'?'PLATAFORMA':'OPERAÇÃO' }}</div>
    <nav aria-label="Navegação principal">
    @auth
        @php
        $nav = match(auth()->user()->role) {
            'admin' => [['/admin','home','Visão geral'],['/admin#empresas','users','Empresas'],['/admin#entregadores','truck','Entregadores'],['/admin#evolution','chat','WhatsApp central'],['/admin/ifood','store','Integração iFood']],
            'courier' => [['/courier','home','Meu painel'],['/courier#ofertas','truck','Ofertas de corrida'],['/courier#corridas','orders','Minhas corridas']],
            default => [['/dashboard','home','Visão geral'],['/dashboard#pedidos','orders','Pedidos'],['/dashboard#cardapio','menu','Cardápio'],['/dashboard#lojas','store','Lojas'],['/dashboard#logistica','truck','Entregas'],['/dashboard#whatsapp','chat','WhatsApp'],['/integrations/ifood','store','Integração iFood']],
        };
        @endphp
        @foreach($nav as [$href,$icon,$label])<a href="{{ $href }}" data-nav-link>@include('partials.icon',['name'=>$icon])<span>{{ $label }}</span>@if($label==='Pedidos'&&isset($summary))<span class="nav-count" data-summary="pending">{{ $summary['pending'] }}</span>@endif</a>@endforeach
    @else
        <a href="/">@include('partials.icon',['name'=>'home']) Conheça a plataforma</a><a href="/login">@include('partials.icon',['name'=>'users']) Entrar no sistema</a><a href="/entregadores/cadastro">@include('partials.icon',['name'=>'truck']) Sou entregador</a>
    @endauth
    </nav>
    <div class="sidebar-bottom">@auth<div class="account"><span class="account-avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name,0,2)) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>{{ ['admin'=>'Superadministrador','owner'=>'Proprietário','courier'=>'Entregador'][auth()->user()->role]??'Minha conta' }}</small></div></div><form action="/logout" method="post">@csrf<button class="ghost">Sair da conta →</button></form>@else<p>Seu negócio,<br>no seu ritmo.</p>@endauth</div>
</aside>
<main>
    <header class="topbar"><div class="breadcrumb"><span>{{ auth()->user()?->role==='admin'?'Plataforma':'Painel' }}</span><span>/</span><strong data-page-label>@yield('page-label','Visão geral')</strong></div><div class="topbar-actions">@if(isset($selectedStore)&&$selectedStore) @php($headerOpen=app(App\Services\StoreAvailability::class)->isOpen($selectedStore))<span class="store-status {{ $headerOpen?'':'is-closed' }}"><i></i>{{ $headerOpen?'Loja aberta':'Loja fechada' }}</span><a class="button outline" href="/cardapio/{{ $selectedStore->slug }}" target="_blank" rel="noopener">@include('partials.icon',['name'=>'external']) Ver cardápio</a>@else<span class="pill">{{ now()->timezone('America/Sao_Paulo')->format('d/m/Y') }}</span>@endif</div></header>
    <div class="content">@if(session('success'))<div class="notice" role="status">{{ session('success') }}</div>@endif @if($errors->any())<div class="notice error" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif @yield('content')</div>
    <footer>CadapioWeb · Canal próprio, operação conectada.</footer>
</main></div></body></html>
