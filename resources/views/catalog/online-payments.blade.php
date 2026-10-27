<section class="card" id="online-payments"><h2>Pagamento online</h2><p>Receba diretamente na conta da sua loja. O cliente paga no checkout externo do provedor; o sistema consulta o pagamento antes de liberar a venda. CPF, e-mail e dados do cartão são preenchidos no ambiente do provedor.</p><p class="muted">InfinitePay usa somente sua InfiniteTag. O token do PagBank fica criptografado e não é exibido novamente; deixe o campo vazio para manter o token atual. Teste o fluxo completo antes de habilitar vendas reais. Cancelar um pedido não estorna o pagamento: confira e faça o estorno no painel do provedor.</p>
@foreach(['infinitepay'=>'InfinitePay','pagbank'=>'PagSeguro / PagBank'] as $provider=>$label)
@php($account=Illuminate\Support\Facades\DB::table('store_payment_accounts')->where('store_id',$store->id)->where('provider',$provider)->select('provider','environment','handle','enabled')->first())
<details><summary>{{ $label }} · {{ $account?->enabled?'Habilitado':'Desabilitado' }}</summary><form action="{{ route('payments.configure',[$store->id,$provider]) }}" method="post" class="fields">@csrf
<label>Receber pagamentos<select name="enabled"><option value="0" @selected(!$account?->enabled)>Desabilitado</option><option value="1" @selected($account?->enabled)>Habilitado</option></select></label>
@if($provider==='infinitepay')<input type="hidden" name="environment" value="production"><label>InfiniteTag (sem $)<input name="handle" value="{{ $account?->handle }}" maxlength="100" required autocomplete="off" placeholder="Ex.: minha-loja"></label>
<p class="muted full">Informe somente a InfiniteTag da conta que receberá as vendas, sem o símbolo $. Não precisa de API Key. O cliente será direcionado ao checkout externo da InfinitePay.</p>
@else
<label>Ambiente<select name="environment"><option value="sandbox" @selected(($account?->environment??'sandbox')==='sandbox')>Sandbox (não aparece no cardápio em produção)</option><option value="production" @selected($account?->environment==='production')>Produção</option></select></label>
<label>Token da API Checkout<input type="password" name="credential" autocomplete="new-password" maxlength="4096" placeholder="{{ $account?'Token cadastrado; vazio para manter':'Cadastre o token desta loja' }}"></label>
<p class="muted full">O PagBank exige conta/token do ambiente correto e liberação/homologação da API Checkout para produção.</p>
@endif
<div class="actions"><button>Salvar {{ $label }}</button></div></form></details>
@endforeach
<small>Ative vendas reais após concluir um pedido de teste e conferir a confirmação automática. Pagamentos com resultado incerto, após o prazo ou em pedido cancelado exigem conferência manual; nenhuma cobrança é refeita automaticamente.</small></section>
