@if(app(App\Services\OnlinePayment::class)->isOnline($order))
@php($attempt=Illuminate\Support\Facades\DB::table('payment_attempts')->where('order_id',$order->id)->select('state','environment','expires_at','needs_review','message')->first())
<section class="card" data-online-panel><h2>Pagamento online</h2>
@if($attempt)
@if($attempt->environment==='sandbox')<p class="notice">AMBIENTE SANDBOX · Não é pagamento real.</p>@endif
<p data-online-message>{{ $order->payment_status==='paid'?'Pagamento confirmado pelo provedor.':($order->payment_status==='review'?'Pagamento exige conferência da loja.':'Aguardando confirmação do provedor; o preparo só será liberado após confirmação.') }}</p>
@if($attempt->message)<p class="notice">{{ $attempt->message }}</p>@endif
<p>Prazo para confirmação automática: {{ Illuminate\Support\Carbon::parse($attempt->expires_at,'UTC')->timezone($store->timezone??'America/Sao_Paulo')->format('d/m H:i') }}.</p>
@if($order->payment_method==='online_infinitepay')<small>O link externo pode continuar ativo após esse prazo. Pagamentos posteriores dependem de conferência da loja. Não pague novamente enquanto houver pagamento pendente.</small>@endif
@if($order->status==='cancelled')<p>Pedido cancelado. Se você pagou, a loja precisa conferir e tratar o estorno com o provedor.</p>@endif
<div class="actions" data-online-actions @if($order->payment_status==='paid') hidden @endif>
@if(!($owner??false) && !$attempt->needs_review && $order->status!=='cancelled' && in_array($attempt->state,['reserved','waiting']) && Illuminate\Support\Carbon::parse($attempt->expires_at,'UTC')->isFuture())<form data-online-pay action="/pedido/{{ $order->public_id }}/pagar" method="post">@csrf<button>Ir para pagamento seguro</button></form>@endif
@if($attempt->state!=='reserved')<form action="{{ ($owner??false)?route('payments.check',$order->id):'/pedido/'.$order->public_id.'/consultar-pagamento' }}" method="post">@csrf<button class="secondary">Consultar pagamento</button></form>@endif
</div><small>A página de retorno não confirma pagamento. Consultas podem levar alguns instantes. Não faça outro pagamento enquanto a situação estiver pendente. Estornos são tratados pela loja no painel do provedor.</small>
@else<p>A loja precisa conferir a configuração deste pagamento.</p>@endif
</section>
@endif
