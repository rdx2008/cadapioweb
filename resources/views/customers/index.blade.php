@extends('layout')
@section('page-label', 'Clientes')
@push('styles')
    <link rel="stylesheet" href="/inventory.css?v=4">
@endpush
@section('content')
<div class="inventory-page">
    <div class="heading">
        <h1>Clientes e recorrência</h1>
        <p>Veja quem voltou a comprar, quando foi o último pedido e quanto cada cliente gastou na loja.</p>
    </div>
    @if($store)
        <div class="grid">
            <section class="card"><small>Clientes identificados</small><h2>{{ $metrics['identified'] }}</h2></section>
            <section class="card"><small>Recorrentes ativos</small><h2>{{ $metrics['returning'] }}</h2></section>
            <section class="card"><small>Sem comprar há 60 dias ou mais</small><h2>{{ $metrics['inactive'] }}</h2></section>
        </div>
        <form class="card fields" method="get" action="{{ route('customers.index') }}">
            <label>Loja
                <select name="store_id">
                    @foreach($stores as $unit)
                        <option value="{{ $unit->id }}" @selected($store->id === $unit->id)>{{ $unit->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Grupo
                <select name="segment">
                    <option value="">Todos</option>
                    @foreach(['new' => 'Uma compra recente', 'returning' => 'Recorrentes ativos', 'inactive' => 'Sem comprar há 60 dias ou mais'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('segment') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Ordenar por
                <select name="sort">
                    @foreach(['recent' => 'Última compra', 'frequency' => 'Mais compras', 'value' => 'Maior valor gasto'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('sort') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <button>Aplicar filtros</button>
        </form>
        <section class="card">
            <h2>Histórico de compras da loja</h2>
            <p>O painel agrupa pedidos concluídos e pagos do cardápio e do PDV pelo telefone. Há {{ $metrics['unidentified'] }} pedido(s) elegível(is) sem telefone identificável.</p>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Telefone</th><th>Última compra</th><th>Compras</th><th>Valor gasto</th><th>Ticket médio</th><th>Grupo</th><th></th></tr></thead>
                    <tbody>
                        @forelse($customers as $customer)
                            @php($lastPurchase = Illuminate\Support\Carbon::parse($customer->last_purchase, 'UTC'))
                            <tr>
                                <td>{{ explode(':', $customer->customer_key, 2)[1] }}</td>
                                <td>{{ $lastPurchase->copy()->timezone($store->timezone)->format('d/m/Y') }}</td>
                                <td>{{ $customer->visits }}</td>
                                <td>R$ {{ number_format($customer->total_cents / 100, 2, ',', '.') }}</td>
                                <td>R$ {{ number_format($customer->total_cents / $customer->visits / 100, 2, ',', '.') }}</td>
                                <td>{{ $lastPurchase->lte($cutoff) ? 'Inativo' : ($customer->visits > 1 ? 'Recorrente' : 'Uma compra') }}</td>
                                <td><a href="{{ route('customers.show', ['storeId' => $store->id, 'customer' => Illuminate\Support\Facades\Crypt::encryptString($customer->customer_key)]) }}">Ver histórico →</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7">Nenhum cliente neste filtro.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $customers->links() }}
        </section>
    @else
        <p class="notice">Cadastre uma loja para acompanhar seus clientes.</p>
    @endif
</div>
@endsection
