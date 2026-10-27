@php($paymentMethod=$payment->payment_method??'cash')
<p><strong>Pagamento:</strong> {{ (App\Services\CheckoutPayment::LABELS+App\Services\OnlinePayment::LABELS)[$paymentMethod]??$paymentMethod }}
@if(($payment->payment_status??'pending')==='paid')<br>Recebimento registrado pela loja/entrega.@endif
@if($paymentMethod==='cash' && ($payment->payment_status??'pending')!=='paid' && ($payment->change_for_cents??null)!==null)<br>Troco para R$ {{ number_format($payment->change_for_cents/100,2,',','.') }} · devolver R$ {{ number_format(max(0,$payment->change_for_cents-$payment->total_cents)/100,2,',','.') }}@endif
@if(str_starts_with($paymentMethod,'online_'))<br><small>A confirmação depende da consulta ao provedor. @if(($payment->payment_status??'pending')==='review')Exige conferência da loja. @endif</small>
@elseif($paymentMethod!=='cash')<br><small>Recebimento confirmado pela loja após conferir o pagamento. @if($paymentMethod==='card')Para cartão na entrega, providenciar maquininha. @endif</small>@endif</p>
