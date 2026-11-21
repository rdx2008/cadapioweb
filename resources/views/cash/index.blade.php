@extends('pos.layout')
@section('content')
<div class="cash-page">
<div class="cash-heading"><div><span class="pill">FRENTE DE CAIXA</span><h1>Caixa da loja</h1><p>Abra o turno, acompanhe os recebimentos e confira o dinheiro ao encerrar.</p></div><a class="button outline" href="{{ route('pos.index') }}">Ir para o PDV →</a></div>
<p class="notice">Um caixa por loja, compartilhado pelos operadores autorizados. Entram os recebimentos manuais de pedidos do PDV. Links de pagamento, vendas do cardápio e acertos de motoboy são acompanhados nas respectivas áreas.</p>
<div class="cash-stores">@forelse($stores as $unit)<section class="card"><div class="cash-heading"><h2>{{ $unit->name }}</h2><span class="pill">{{ isset($active[$unit->id])?'Aberto':'Fechado' }}</span></div>
@if(isset($active[$unit->id]))<p>Turno #{{ $active[$unit->id]->id }} · aberto em {{ Illuminate\Support\Carbon::parse($active[$unit->id]->opened_at)->timezone('America/Sao_Paulo')->format('d/m H:i') }}</p><a class="button" href="{{ route('cash.show',$active[$unit->id]->id) }}">Acompanhar caixa →</a>
@else<form method="post" action="{{ route('cash.open') }}" class="stack-form">@csrf<input type="hidden" name="store_id" value="{{ $unit->id }}"><input type="hidden" name="request_key" value="{{ (string)Illuminate\Support\Str::uuid() }}"><label>Quanto dinheiro há na gaveta? (R$)<input name="opening" required inputmode="decimal" maxlength="10" placeholder="0,00" value="0,00"></label><small>Informe o fundo de troco. Pix e cartão não entram neste valor.</small><button>Abrir caixa</button></form>@endif
</section>@empty<p class="empty">Nenhuma loja liberada para seu acesso.</p>@endforelse</div>
<section class="card"><h2>Histórico de turnos</h2><div class="table-scroll"><table><thead><tr><th>Turno / loja</th><th>Abertura</th><th>Responsável</th><th>Situação</th><th></th></tr></thead><tbody>@forelse($history as $turn)<tr><td>#{{ $turn->id }} · {{ $turn->store_name }}</td><td>{{ Illuminate\Support\Carbon::parse($turn->opened_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td><td>{{ $turn->operator_name }}</td><td>{{ $turn->closed_at?'Fechado':'Aberto' }}</td><td><a href="{{ route('cash.show',$turn->id) }}">Ver turno →</a></td></tr>@empty<tr><td colspan="5">Seu primeiro turno aparecerá aqui.</td></tr>@endforelse</tbody></table></div>{{ $history->links() }}</section>
</div>
@endsection
