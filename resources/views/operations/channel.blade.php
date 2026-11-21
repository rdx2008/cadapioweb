<span class="ops-channel">{{ $order->source==='pos'?(['counter'=>'Balcão','phone'=>'Telefone','whatsapp'=>'WhatsApp'][$order->pos_channel??'counter']??'PDV'):'Cardápio' }}</span>
