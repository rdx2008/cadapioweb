@extends('layout')
@section('content')
<div data-courier-workspace data-live-url="{{ route('courier.live') }}" data-heartbeat-url="{{ route('courier.heartbeat') }}" data-renew="{{ $presence['renew'] ? '1' : '0' }}" data-server-time="{{ now()->timestamp * 1000 }}">
<div class="page-heading"><div><span class="dashboard-eyebrow">MOTOBOY FREELANCER</span><h1>Seu próximo destino.</h1><p>Disponibilidade, ofertas e suas entregas no mesmo lugar.</p></div></div>
<div class="card"><div class="section-head"><h2 data-courier-presence>{{ $presence['label'] }}</h2><span class="pill" data-courier-queue>@if($presence['queue_position']){{ $presence['queue_position'] }}º de {{ $presence['queue_total'] }} na fila @else Sem posição na fila @endif</span></div>
<p class="muted">A localização é renovada a cada 30 segundos com esta página visível. Após 90 segundos sem presença válida, novas ofertas ficam suspensas. Com a tela bloqueada ou o navegador fechado, o recebimento de ofertas não é garantido.</p>
<p class="muted" data-courier-gps role="status">{{ $presence['renew'] ? 'Aguardando atualização da localização.' : 'Confirme sua disponibilidade para começar.' }}</p>
@if($driver->status==='approved')
<form action="/courier/presence" method="post" data-courier-start>@csrf<input type="hidden" name="latitude"><input type="hidden" name="longitude"><input type="hidden" name="accuracy"><label>Fila da loja (opcional)<select name="store_id"><option value="">Disponível para ofertas da região</option>@foreach($stores as $s)<option value="{{ $s->id }}" @selected($driver->checkin_store_id === $s->id)>{{ $s->name }} · {{ $s->address }}</option>@endforeach</select></label><p class="muted">Check-in na loja exige localização com precisão de até 50 m e distância de até 150 m. Ao sair desse raio, confirme novamente para entrar em outra fila ou receber ofertas da região.</p><div class="actions"><button type="button" data-location>Confirmar disponibilidade e localização</button><span data-location-message class="muted" role="status"></span></div></form>
<form action="/courier/offline" method="post" class="actions" data-courier-offline>@csrf<button class="secondary">Ficar offline / sair da fila</button></form>
@else<div class="notice">A plataforma precisa aprovar seu cadastro para liberar novas ofertas.</div>@endif
</div>
<div class="actions"><button type="button" class="secondary" data-courier-sound>Ativar aviso sonoro de oferta</button><span data-courier-connection role="status" class="muted">Atualização automática a cada 5 segundos.</span></div>
<p data-courier-alert role="status" aria-live="polite"></p>
<div data-courier-board>@include('partials.courier-board')</div>
</div>
<script src="/courier.js?v=1" defer></script>
@endsection