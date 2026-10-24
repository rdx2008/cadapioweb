@php
$labels=['pending'=>'Novos pedidos','accepted'=>'Aceitos','preparing'=>'Em preparo','ready'=>'Prontos','completed'=>'Concluídos','cancelled'=>'Cancelados'];
$states=match($filters['status']??'active'){'all'=>array_keys($labels),'active'=>['pending','accepted','preparing','ready'],default=>[$filters['status']]};
@endphp
<p class="compact muted">{{ $boardOrders->total() }} pedidos no filtro · página {{ $boardOrders->currentPage() }} de {{ max(1,$boardOrders->lastPage()) }}</p>
<div class="kanban" style="grid-template-columns:repeat({{ count($states) }},minmax(240px,1fr))">
@foreach($states as $state)<div class="column">
<h3>{{ $labels[$state] }}</h3>
@forelse($boardOrders->getCollection()->where('status',$state) as $order)<article class="order">
<div class="section-head">
<strong>#{{ $order->id }}</strong>
<span class="badge">{{ $order->mode==='delivery'?'Entrega':'Retirada' }}</span>
</div>
<p>{{ $order->customer_name }}<br>
<span class="muted">{{ $order->phone }}</span>
</p>
@foreach($boardItems->get($order->id,collect()) as $item)<p class="compact">
<strong>{{ $item->quantity }} × {{ $item->name }}</strong>@foreach(json_decode($item->options??'[]',true) as $option)<br>
<span class="muted">{{ $option['group'] }}: {{ $option['name'] }}</span>@endforeach</p>@endforeach
<div class="total">R$ {{ number_format($order->total_cents/100,2,',','.') }}</div>
<p class="compact muted">Pagamento: {{ $order->payment_status }}<br>{{ Illuminate\Support\Carbon::parse($order->created_at)->timezone('America/Sao_Paulo')->format('d/m H:i') }}</p>
<div class="actions">@if(isset(['pending'=>1,'accepted'=>1,'preparing'=>1,'ready'=>1][$state]))<form action="/orders/{{ $order->id }}/status" method="post">@csrf<input type="hidden" name="status" value="{{ ['pending'=>'accepted','accepted'=>'preparing','preparing'=>'ready','ready'=>'completed'][$state] }}">
<button>{{ ['pending'=>'Aceitar','accepted'=>'Preparar','preparing'=>'Marcar pronto','ready'=>'Concluir'][$state] }}</button>
</form>@endif<a class="button secondary" href="/orders/{{ $order->id }}">Detalhes</a>
</div>
@if(!in_array($state,['cancelled','completed']))<div class="actions">@if($order->payment_status!=='paid')<form action="/orders/{{ $order->id }}/paid" method="post">@csrf<button class="secondary">Registrar recebido</button>
</form>@endif</div>
<details>
<summary>Cancelar pedido</summary>
<form action="/orders/{{ $order->id }}/status" method="post">@csrf<input type="hidden" name="status" value="cancelled">
<label>Motivo<textarea name="cancellation_reason" required maxlength="500">
</textarea>
</label>
<button class="secondary">Confirmar cancelamento</button>
</form>
</details>@endif
@if($state==='ready'&&$order->mode==='delivery'&&$tenant->courier_module==='active')<details>
<summary>Solicitar freelancer</summary>
<form action="/orders/{{ $order->id }}/dispatch" method="post">@csrf<label>Distância de rota em metros (tarifa/km)<input name="route_meters" type="number" min="1">
</label>
<label>Cotação (R$) (por corrida)<input name="fare" type="text" inputmode="decimal">
</label>
<button>Despachar</button>
</form>
</details>@endif
</article>@empty<p class="compact muted">Sem pedidos nesta página.</p>@endforelse</div>@endforeach</div>
<div class="actions">@if($boardOrders->previousPageUrl())<a class="button secondary" href="{{ $boardOrders->previousPageUrl() }}#pedidos">← Página anterior</a>@endif @if($boardOrders->nextPageUrl())<a class="button secondary" href="{{ $boardOrders->nextPageUrl() }}#pedidos">Próxima página →</a>@endif</div>
