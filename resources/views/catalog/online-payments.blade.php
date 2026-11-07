<section class="card" id="online-payments"><h2>Pagamento online</h2><p>Receba diretamente na conta da sua loja. O cliente paga no checkout externo do provedor; o sistema consulta o pagamento antes de liberar a venda. CPF, e-mail e dados do cartão são preenchidos no ambiente do provedor. InfinitePay usa somente sua InfiniteTag.</p>
@foreach(['infinitepay'=>'InfinitePay','pagbank'=>'PagSeguro / PagBank'] as $provider=>$label)
@php($account=Illuminate\Support\Facades\DB::table('store_payment_accounts')->where('store_id',$store->id)->where('provider',$provider)->select('provider','environment','handle','enabled')->first())
@php($enabled=$account?->enabled && $account->environment==='production')
<details @if(old('payment_provider')===$provider) open @endif><summary>{{ $label }} · {{ $enabled?'Habilitado':'Desabilitado' }}</summary><form action="{{ route('payments.configure',[$store->id,$provider]) }}" method="post" class="fields">@csrf<input type="hidden" name="payment_provider" value="{{ $provider }}">
<label>Receber pagamentos<select name="enabled"><option value="0" @selected(!$enabled)>Desabilitado</option><option value="1" @selected($enabled)>Habilitado</option></select></label>
@if($provider==='infinitepay')<label>InfiniteTag (sem $)<input name="handle" value="{{ $account?->handle }}" maxlength="100" required autocomplete="off" placeholder="Ex.: minha-loja"></label>
@else
<label>Token da API Checkout<input type="password" name="credential" autocomplete="new-password" maxlength="4096" placeholder="{{ $account?'Token cadastrado; vazio para manter':'Cadastre o token desta loja' }}"></label>
<p class="muted full">Para obter o token, acesse sua conta PagBank pelo computador e vá em <strong>Vendas → Integrações → Gerar Token</strong>.</p>
@endif
<div class="actions"><button>Salvar {{ $label }}</button></div></form></details>
@endforeach
</section>
