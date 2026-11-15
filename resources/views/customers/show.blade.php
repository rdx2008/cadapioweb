@extends('layout')
@section('page-label', 'Clientes')
@push('styles')
    <link rel="stylesheet" href="/inventory.css?v=4">
@endpush
@section('content')
<div class="inventory-page">
    <a href="{{ route('customers.index', ['store_id' => $store->id]) }}">← Clientes da loja</a>
    <div class="heading">
        <h1>{{ $latest->customer_name ?: 'Cliente' }}</h1>
        <p>{{ $latest->phone }} · {{ $store->name }}</p>
    </div>
    <div class="grid">
        <section class="card"><small>Compras</small><h2>{{ $totals->visits }}</h2></section>
        <section class="card"><small>Total gasto</small><h2>R$ {{ number_format($totals->total_cents / 100, 2, ',', '.') }}</h2></section>
        <section class="card"><small>Ticket médio</small><h2>R$ {{ number_format($totals->total_cents / $totals->visits / 100, 2, ',', '.') }}</h2></section>
    </div>
    <section class="card">
        <h2>Pedidos concluídos e pagos</h2>
        <p>Primeira compra em {{ Illuminate\Support\Carbon::parse($totals->first_purchase, 'UTC')->timezone($store->timezone)->format('d/m/Y') }}.</p>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Pedido</th><th>Data</th><th>Canal</th><th>Total</th></tr></thead>
                <tbody>
                    @foreach($history as $order)
                        <tr>
                            <td><a href="{{ url('/orders/'.$order->id) }}">#{{ $order->id }}</a></td>
                            <td>{{ Illuminate\Support\Carbon::parse($order->created_at, 'UTC')->timezone($store->timezone)->format('d/m/Y H:i') }}</td>
                            <td>{{ $order->source === 'menu' ? 'Cardápio' : (['counter' => 'Balcão', 'phone' => 'Telefone', 'whatsapp' => 'WhatsApp'][$order->pos_channel] ?? 'PDV') }}</td>
                            <td>R$ {{ number_format($order->total_cents / 100, 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $history->links() }}
    </section>
</div>
@endsection
