@if($ride->status==='assigned'&&$isOwner)
<section class="card"><h2>Trocar motoboy antes da coleta</h2><p>Use quando o pedido continua na loja. Registre a remuneração combinada por esta tentativa; a nova busca será feita separadamente.</p>
<form class="fields" method="post" action="{{ route('deliveries.exceptions',[$ride->id,'release']) }}">@csrf
<input type="hidden" name="request_key" value="{{ Illuminate\Support\Str::uuid() }}"><input type="hidden" name="expected_round" value="{{ $ride->dispatch_round }}">
<label>Motivo<textarea name="reason" minlength="5" maxlength="1000" required></textarea></label>
<label>Remuneração a acertar por esta tentativa (R$)<input name="compensation" inputmode="decimal" placeholder="Informe o valor, inclusive se for zero" required><small>Proposta original: R$ {{ number_format($ride->fare_cents/100,2,',','.') }}. Confirme o valor com o motoboy.</small></label>
<label class="full"><span><input type="checkbox" name="physical_confirmation" value="1" required> Confirmo que o pedido ainda está na loja e não foi coletado.</span></label><div><button class="secondary">Liberar motoboy</button></div></form></section>
@endif
@if($ride->status==='collected')
<section class="card"><h2>Retornar pedido à loja</h2><p>O motoboy permanece responsável pela corrida até a loja confirmar o recebimento físico do pedido.</p><form class="stack-form" method="post" action="{{ route('deliveries.return',$ride->id) }}">@csrf
<input type="hidden" name="request_key" value="{{ Illuminate\Support\Str::uuid() }}"><input type="hidden" name="expected_round" value="{{ $ride->dispatch_round }}">
<label>Por que precisa retornar?<textarea name="reason" minlength="5" maxlength="1000" required></textarea></label><button class="secondary">Registrar retorno à loja</button></form></section>
@endif
@if($ride->status==='returning')
<section class="card"><h2>Pedido em retorno à loja</h2><p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $ride->return_reason }}</p>
@if($isOwner)<form class="fields" method="post" action="{{ route('deliveries.exceptions',[$ride->id,'receive']) }}">@csrf
<input type="hidden" name="request_key" value="{{ Illuminate\Support\Str::uuid() }}"><input type="hidden" name="expected_round" value="{{ $ride->dispatch_round }}">
<label class="full">Conferência da devolução<textarea name="reason" minlength="5" maxlength="1000" required placeholder="Condição do pedido e o que foi combinado"></textarea></label>
<label>Remuneração a acertar por esta tentativa (R$)<input name="compensation" inputmode="decimal" required><small>Proposta original: R$ {{ number_format($ride->fare_cents/100,2,',','.') }}. Confirme o valor com o motoboy.</small></label>
<label>Dinheiro recebido do cliente nesta tentativa (R$)<input name="customer_cash" inputmode="decimal" required><small>Informe zero se não houve recebimento. Valores positivos deixam o pagamento em conferência; não confirmam quitação nem repasse.</small></label>
<label class="full"><span><input type="checkbox" name="physical_confirmation" value="1" required> Recebi fisicamente o pedido de volta na loja.</span></label><div><button>Confirmar devolução recebida</button></div></form>
@else<p>Leve o pedido à loja e aguarde a confirmação do recebimento. A corrida continua vinculada a você.</p>@endif</section>
@endif
@if($isOwner&&in_array($ride->status,['unassigned','returned'],true))
<section class="card"><h2>Buscar outro motoboy</h2><p>A nova tentativa mantém a proposta de R$ {{ number_format($ride->fare_cents/100,2,',','.') }}. Os acertos anteriores ficam no histórico abaixo.</p>
@if($order->payment_status==='review')<p>Pagamento em conferência. Confira o dinheiro recebido e o total do pedido antes de registrar a quitação na tela do pedido e iniciar uma nova busca.</p><a class="button secondary" href="/orders/{{ $order->id }}">Conferir pagamento</a>
@elseif($order->status==='ready')<form method="post" action="{{ route('deliveries.exceptions',[$ride->id,'retry']) }}">@csrf<input type="hidden" name="request_key" value="{{ Illuminate\Support\Str::uuid() }}"><input type="hidden" name="expected_round" value="{{ $ride->dispatch_round }}"><button>Iniciar nova busca</button></form>@else<p>O pedido precisa estar pronto para uma nova entrega.</p>@endif</section>
@endif
@if($attempts->isNotEmpty())<section class="card"><h2>Histórico de tentativas encerradas</h2><p>Consulte cada tentativa e seu acerto individual.</p>@foreach($attempts as $attempt)<p><a class="button secondary" href="{{ route('delivery-attempts.show',$attempt->id) }}">Tentativa #{{ $attempt->id }} · {{ $attempt->outcome==='returned'?'Devolvida à loja':'Liberada antes da coleta' }} · R$ {{ number_format($attempt->compensation_cents/100,2,',','.') }}</a></p>@endforeach</section>@endif
