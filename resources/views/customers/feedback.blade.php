@extends('layout')
@section('page-label','Avaliações de pedidos')
@push('styles')<link rel="stylesheet" href="/inventory.css?v=4">@endpush
@section('content')
<div class="inventory-page">
    <div class="heading"><h1>Avaliações dos clientes</h1><p>Ouça quem comprou e acompanhe os pontos que precisam de atenção.</p><a href="{{ route('customers.index',['store_id'=>$store?->id]) }}">← Clientes e recorrência</a></div>
    <form class="card fields" method="get" action="{{ route('feedback.index') }}">
        <label>Loja<select name="store_id">@foreach($stores as $unit)<option value="{{ $unit->id }}" @selected($unit->id===$store?->id)>{{ $unit->name }}</option>@endforeach</select></label>
        <label>De<input type="date" name="from" value="{{ $from }}" required></label><label>Até<input type="date" name="to" value="{{ $to }}" required></label>
        <label>Nota<select name="rating"><option value="">Todas</option>@foreach(range(1,5) as $score)<option value="{{ $score }}" @selected((int)request('rating')===$score)>{{ $score }} de 5 ★</option>@endforeach</select></label>
        <label>Situação<select name="status"><option value="">Todas</option><option value="pending" @selected(request('status')==='pending')>A conferir</option><option value="treated" @selected(request('status')==='treated')>Tratadas</option></select></label><button>Aplicar filtros</button>
    </form>
    <p>Indicadores da loja no período selecionado, considerando todas as notas e situações. Avaliações são privadas; não são publicadas no cardápio.</p>
    <div class="grid"><section class="card"><small>Avaliações</small><h2>{{ $metrics['count'] }}</h2></section><section class="card"><small>Nota média</small><h2>{{ $metrics['average']===null?'—':number_format($metrics['average'],1,',','').' / 5' }}</h2></section><section class="card"><small>Notas 1 e 2</small><h2>{{ $metrics['low'] }}</h2></section><section class="card"><small>A conferir</small><h2>{{ $metrics['pending'] }}</h2></section></div>
    @forelse($feedback as $entry)
        <article class="card"><div class="heading"><h2>{{ $entry->rating }} de 5 ★ · Pedido #{{ $entry->order_id }}</h2><p>{{ Illuminate\Support\Carbon::parse($entry->created_at,'UTC')->timezone($store->timezone)->format('d/m/Y H:i') }} · {{ $entry->treated_at?'Tratada':'A conferir' }}</p></div>
            <p>{{ $entry->comment ?: 'Cliente enviou somente a nota.' }}</p><p><a href="{{ url('/orders/'.$entry->order_id) }}">Ver pedido →</a></p>
            @if($entry->treated_at)<h3>Tratamento interno registrado</h3><p>{{ $entry->treatment_note }}</p><small>{{ Illuminate\Support\Carbon::parse($entry->treated_at,'UTC')->timezone($store->timezone)->format('d/m/Y H:i') }} · {{ $entry->operator_name }}</small>
            @else<details><summary>Registrar tratamento</summary><p>Anote o que foi conferido ou resolvido. Este registro é interno e não envia mensagem ao cliente.</p><form method="post" action="{{ route('feedback.treat',$entry->id) }}">@csrf<label>O que foi feito?<textarea name="note" rows="3" maxlength="1000" required></textarea></label><label class="option-choice"><input type="checkbox" name="confirmed" value="1" required>Conferi esta avaliação e o tratamento informado.</label><button>Marcar como tratada</button></form></details>@endif
        </article>
    @empty<section class="card"><h2>Nenhuma avaliação neste filtro</h2><p>O cliente poderá avaliar pelo acompanhamento quando o pedido estiver concluído e pago.</p></section>@endforelse
    {{ $feedback->links() }}
</div>
@endsection
