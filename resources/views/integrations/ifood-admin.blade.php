@extends('layout')
@section('page-label','Integração iFood')
@section('content')
<div class="page-heading"><div><h1>Integração iFood</h1><p>Configure o aplicativo e vincule cada loja ao restaurante correto.</p></div><span class="badge pending">Ambiente de teste</span></div>
<div class="notice">Primeira etapa: autenticação e consulta de lojas. Recebimento de pedidos e sincronização de catálogo ainda não estão ativos. Produção depende de implementação e homologação.</div>
<div class="two">
    <section class="card"><h2>Aplicativo de teste</h2><p class="muted">Use as credenciais do aplicativo centralizado de teste criado no portal iFood Developer. Somente o superadmin administra essas chaves.</p>
        <form action="{{ route('ifood.configure') }}" method="post" class="stack-form">@csrf
            <label>Client ID<input name="client_id" value="{{ old('client_id',$application->client_id??'') }}" required maxlength="255" autocomplete="off"></label>
            <label>Client Secret<input name="client_secret" type="password" maxlength="4000" autocomplete="new-password" placeholder="{{ $application?'Deixe vazio para manter a chave atual':'Informe a chave do aplicativo' }}" @required(!$application)></label>
            <button>Salvar aplicativo de teste</button>
        </form>
        @if($application)<form action="{{ route('ifood.test') }}" method="post" class="actions">@csrf<button class="secondary">Testar autenticação</button></form>@endif
        <p class="compact muted">A chave e o token são criptografados e nunca exibidos. Trocar credenciais invalida as verificações anteriores.</p>
    </section>
    <section class="card"><h2>Vincular loja de teste</h2><p class="muted">Confira a empresa e a unidade antes de vincular. O acesso do aplicativo ao Merchant ID será consultado na API.</p>
        <form action="{{ route('ifood.connect') }}" method="post" class="stack-form">@csrf
            <label>Empresa / loja local<select name="store_id" required><option value="">Selecione uma loja</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected(old('store_id')==$store->id)>{{ $store->tenant_name }} / {{ $store->name }}</option>@endforeach</select></label>
            <label>Merchant ID da loja de teste<input name="merchant_id" value="{{ old('merchant_id') }}" required maxlength="36" placeholder="UUID da loja no iFood"></label>
            <label class="option-choice"><input type="checkbox" name="test_merchant_confirmed" value="1" required>Confirmo que o Merchant ID é de teste e corresponde à empresa e loja selecionadas.</label>
            <button @disabled(!$application||$stores->isEmpty())>Verificar e vincular</button>
        </form>
        <p class="compact muted">Use exclusivamente o aplicativo e a loja de teste do portal iFood. Esta etapa não autoriza a operação de restaurantes reais.</p>
    </section>
</div>
<section><h2>Lojas vinculadas</h2><div class="grid">@forelse($connections as $connection)@include('integrations.ifood-connection',['isAdmin'=>true])@empty<div class="empty full">Nenhuma loja vinculada. Configure o aplicativo e informe uma loja de teste.</div>@endforelse</div></section>
@endsection
