@extends('layout')
@section('page-label', 'Cupons')
@push('styles')
    <link rel="stylesheet" href="/inventory.css?v=4">
@endpush
@section('content')
<div class="inventory-page">
    <a href="{{ route('catalog.index', ['store_id' => $store->id]) }}">← Cardápio</a>
    <div class="heading"><h1>Cupons do cardápio</h1><p>{{ $store->name }}</p></div>
    <section class="card">
        <h2>Criar cupom</h2>
        <p>O cliente usa um código por pedido. O desconto incide sobre os produtos e adicionais, com o frete cobrado à parte.</p>
        <form class="fields" method="post" action="{{ route('coupons.create', $store->id) }}">
            @csrf
            <label>Código<input name="code" required minlength="4" maxlength="24" placeholder="Ex.: PRIMEIRACOMPRA" value="{{ old('code') }}"></label>
            <label>Tipo de desconto
                <select name="kind"><option value="fixed">Valor fixo (R$)</option><option value="percent" @selected(old('kind') === 'percent')>Porcentagem (%)</option></select>
            </label>
            <label>Valor ou porcentagem<input name="amount" required maxlength="12" inputmode="decimal" placeholder="Ex.: 10,00 ou 10" value="{{ old('amount') }}"></label>
            <label>Mínimo em produtos (R$)<input name="minimum" required maxlength="12" inputmode="decimal" value="{{ old('minimum', '0,00') }}"></label>
            <label>Válido a partir de<input name="starts_on" type="date" required value="{{ old('starts_on', $today) }}"></label>
            <label>Válido até (inclusive)<input name="ends_on" type="date" required value="{{ old('ends_on', $today) }}"></label>
            <label>Limite total de usos<input name="maximum_uses" type="number" min="1" max="100000" required value="{{ old('maximum_uses', 100) }}"></label>
            <label>Limite por telefone<input name="per_customer" type="number" min="1" max="100000" required value="{{ old('per_customer', 1) }}"></label>
            <button>Criar cupom</button>
        </form>
    </section>
    <section class="card">
        <h2>Cupons da loja</h2>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Código</th><th>Desconto</th><th>Vigência</th><th>Usos</th><th>Situação</th><th></th></tr></thead>
                <tbody>
                    @forelse($coupons as $coupon)
                        <tr>
                            <td><strong>{{ $coupon->code }}</strong></td>
                            <td>{{ $coupon->kind === 'percent' ? $coupon->value.'%' : 'R$ '.number_format($coupon->value/100, 2, ',', '.') }}<br><small>Mínimo R$ {{ number_format($coupon->minimum_cents/100, 2, ',', '.') }}</small></td>
                            <td>{{ \Illuminate\Support\Carbon::parse($coupon->starts_on)->format('d/m/Y') }} a {{ \Illuminate\Support\Carbon::parse($coupon->ends_on)->format('d/m/Y') }}</td>
                            <td>{{ $coupon->uses }}/{{ $coupon->maximum_uses }} <small>· {{ $coupon->per_customer }} por telefone</small></td>
                            <td>{{ !$coupon->enabled ? 'Pausado' : ($coupon->ends_on < $today ? 'Expirado' : ($coupon->starts_on > $today ? 'Agendado' : ($coupon->uses >= $coupon->maximum_uses ? 'Esgotado' : 'Ativo'))) }}</td>
                            <td>
                                <form method="post" action="{{ route('coupons.availability', [$store->id, $coupon->id]) }}">
                                    @csrf
                                    <button class="secondary" name="enabled" value="{{ $coupon->enabled ? '0' : '1' }}">{{ $coupon->enabled ? 'Pausar' : 'Reativar' }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6">Nenhum cupom criado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $coupons->links() }}
    </section>
</div>
@endsection
