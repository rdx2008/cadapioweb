@extends('operations.layout')
@section('page-label','Expedição e entregas')
@section('content')
<div class="ops-page-title"><div><h1>Expedição e entregas</h1><p>Do pedido pronto até a entrega, tudo em uma visão.</p></div><div class="ops-title-actions">@if($user->role==='owner')<a class="ops-button" href="{{ route('deliveries.index') }}">Histórico e acertos</a>@endif<button class="ops-button" type="button" data-ops-map-open>Ver mapa</button></div></div>
<form class="ops-filters" method="get" action="{{ route('expedition.index') }}"><label>Loja<select name="store_id">@foreach($stores as $unit)<option value="{{ $unit->id }}" @selected($store?->id===$unit->id)>{{ $unit->name }}</option>@endforeach</select></label><label class="ops-search">Buscar pedido, cliente ou motoboy<input name="q" value="{{ $filters['q']??'' }}" maxlength="100" placeholder="Número do pedido ou nome"></label><label>Ocorrências<select name="incidents"><option value="">Todas as entregas</option><option value="open" @selected(($filters['incidents']??'')==='open')>Precisam de atenção</option></select></label><button>Filtrar</button></form>
<div data-ops-root><p data-ops-message role="status">Atualização automática com a página ativa.</p><div data-ops-feed data-url="{{ route('expedition.live',request()->query()) }}">@include('operations.expedition-board')</div></div>
@endsection
@push('scripts')<script src="/operations-map.js?v=1" defer></script>@endpush
