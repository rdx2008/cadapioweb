@if($settlements->isNotEmpty())
<section class="card"><h2>Histórico de recebimentos</h2><p>Valores conferidos no caixa. Cancelamentos e devoluções permanecem registrados nos movimentos do caixa.</p>
@foreach($settlements as $settlement)<p><strong>{{ $money($settlement->amount_cents) }} · {{ ['cash'=>'Dinheiro','pix'=>'Pix conferido','card'=>'Maquininha'][$settlement->payment_method] }}</strong><br>{{ Illuminate\Support\Carbon::parse($settlement->created_at)->timezone($store->timezone)->format('d/m/Y H:i') }} · {{ $settlement->operator_name }}</p>@endforeach
</section>
@endif
