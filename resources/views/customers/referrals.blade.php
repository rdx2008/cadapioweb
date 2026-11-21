@extends('layout')
@section('page-label', 'Indicações')
@push('styles')
    <link rel="stylesheet" href="/inventory.css?v=4">
@endpush
@section('content')
<div class="inventory-page">
    <div class="heading">
        <h1>Indicações da loja</h1>
        <p>Recompense clientes quando um convidado concluir e pagar a primeira compra elegível.</p>
    </div>
    <a href="{{ route('customers.index', ['store_id' => $store?->id]) }}">← Clientes</a>
    @if($store)
        <form class="card fields" method="get" action="{{ route('referrals.index') }}">
            <label>Loja
                <select name="store_id">
                    @foreach($stores as $unit)
                        <option value="{{ $unit->id }}" @selected($unit->id === $store->id)>{{ $unit->name }}</option>
                    @endforeach
                </select>
            </label>
            <button>Selecionar loja</button>
        </form>
        <section class="card">
            <h2>Programa de indicações</h2>
            <form class="fields" method="post" action="{{ route('referrals.configure', $store->id) }}">
                @csrf
                <label>Situação
                    <select name="enabled">
                        <option value="0">Pausado</option>
                        <option value="1" @selected(old('enabled', $campaign?->enabled))>Ativo</option>
                    </select>
                </label>
                <label>Recompensa de quem indica
                    <input name="reward" value="{{ old('reward', $campaign?->reward) }}" maxlength="140" required placeholder="Ex.: uma sobremesa da casa">
                </label>
                <label>Valor mínimo em produtos (R$)
                    <input name="minimum" inputmode="decimal" value="{{ old('minimum', number_format(($campaign?->minimum_cents ?? 0) / 100, 2, ',', '')) }}" required>
                </label>
                <button>Salvar programa</button>
            </form>
        </section>
        <section class="card">
            <h2>Links de indicação</h2>
            <p>Gere o link no histórico do cliente e compartilhe com ele.</p>
            @forelse($invites as $invite)
                <label>{{ $invite->customer_name }}
                    <input readonly value="{{ url('/cardapio/'.$store->slug).'?indicacao='.$invite->code }}">
                </label>
            @empty
                <p>Nenhum link gerado nesta loja.</p>
            @endforelse
            {{ $invites->links() }}
        </section>
        <section class="card">
            <h2>Recompensas</h2>
            <p>Combine a entrega com quem indicou e registre quando a recompensa for entregue.</p>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Quem indicou</th><th>Pedido do convidado</th><th>Recompensa</th><th>Situação</th></tr></thead>
                    <tbody>
                        @forelse($conversions as $conversion)
                            <tr>
                                <td>{{ $conversion->inviter_name }}</td>
                                <td><a href="{{ url('/orders/'.$conversion->order_id) }}">#{{ $conversion->order_id }}</a></td>
                                <td>{{ $conversion->reward }}</td>
                                <td>
                                    @if($conversion->delivered_at)
                                        Entregue
                                    @elseif($conversion->status === 'cancelled')
                                        Pedido cancelado
                                    @elseif($conversion->status === 'completed' && $conversion->payment_status === 'paid')
                                        <form method="post" action="{{ route('referrals.deliver', $conversion->id) }}">
                                            @csrf
                                            <label><input type="checkbox" name="confirmed" value="1" required> Recompensa entregue</label>
                                            <button>Registrar entrega</button>
                                        </form>
                                    @else
                                        Aguardando conclusão e pagamento
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4">Nenhuma indicação registrada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $conversions->links() }}
        </section>
    @else
        <p>Cadastre uma loja para configurar indicações.</p>
    @endif
</div>
@endsection
