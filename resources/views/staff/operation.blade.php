@extends('layout')
@section('page-label','Operação da equipe')
@section('content')
<div class="heading"><span class="eyebrow">{{ $staff->staff_profile==='kitchen'?'Cozinha':'Atendimento e pedidos' }}</span><h1>Sua fila de pedidos</h1><p class="muted">Exibindo somente as lojas liberadas pelo proprietário para seu acesso.</p></div>
@if($stores->isEmpty())<p class="notice">Nenhuma loja vinculada. Solicite ao proprietário a liberação do seu acesso.</p>@endif
<form method="get" action="/operation" class="card fields"><label>Loja<select name="store_id"><option value="">Todas as minhas lojas</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected(($filters['store_id']??'')==$store->id)>{{ $store->name }}</option>@endforeach</select></label><label>Situação<select name="status">@foreach(['active'=>'Em andamento','all'=>'Todas','pending'=>'Novos','accepted'=>'Aceitos','preparing'=>'Em preparo','ready'=>'Prontos','completed'=>'Concluídos','cancelled'=>'Cancelados'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['status']??'active')===$value)>{{ $label }}</option>@endforeach</select></label><button>Filtrar pedidos</button></form>
<p data-staff-connection role="status">Atualização automática com a página ativa.</p>
<div data-staff-feed data-url="{{ url('/operation/live').'?'.http_build_query(request()->only(['store_id','status','page'])) }}">@include('staff.orders')</div>
@endsection
