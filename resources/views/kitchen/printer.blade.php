@extends('layout')
@section('page-label', 'Impressão automática')
@push('styles')
    <link rel="stylesheet" href="/inventory.css?v=4">
@endpush
@section('content')
<div class="inventory-page">
    <a href="{{ route('kitchen.index', ['store_id' => $store->id]) }}">← Cozinha</a>
    <div class="heading">
        <h1>Impressão automática</h1>
        <p>{{ $store->name }} · {{ $store->kitchen_printer_token_hash ? 'Fila ativada' : 'Fila desativada' }}</p>
    </div>
    <section class="card">
        <h2>Computador da cozinha</h2>
        <p>Instale as impressoras no Windows e mantenha o agente aberto no computador da loja. As vias entram na fila ao iniciar o preparo.</p>
        <ol>
            <li><a href="{{ asset('kitchen-printer.ps1') }}" download>Baixar agente para Windows</a>.</li>
            <li>Gere a chave da loja e copie enquanto estiver visível. Gerar outra chave encerra o acesso da anterior.</li>
            <li>Na pasta do arquivo, rode no Windows PowerShell. Troque os valores pelo endereço do seu painel e pelo nome da impressora no Windows.</li>
        </ol>
        <pre style="white-space:pre-wrap">.\kitchen-printer.ps1 -Site 'https://seu-painel.exemplo' -DefaultPrinter 'Nome da impressora'</pre>
        <p>Para separar por estação, salve um arquivo <code>estacoes.json</code> em UTF-8 com os nomes exatos das estações e impressoras e acrescente <code>-StationMap '.\estacoes.json'</code> ao comando.</p>
        <pre style="white-space:pre-wrap">{"Pizza":"Impressora Pizza","Bebidas":"Impressora Balcao"}</pre>
        <p>Estações sem indicação e itens de preparo geral usam a impressora padrão.</p>
        @if(session('printer_token'))
            <label>Chave do agente<input readonly value="{{ session('printer_token') }}" autocomplete="off"></label>
        @endif
        <form method="post" action="{{ route('kitchen.printer.configure', $store->id) }}">
            @csrf
            <button name="action" value="activate">{{ $store->kitchen_printer_token_hash ? 'Gerar nova chave' : 'Ativar e gerar chave' }}</button>
            @if($store->kitchen_printer_token_hash)
                <button class="secondary" name="action" value="disable">Desativar e cancelar a fila pendente</button>
            @endif
        </form>
    </section>
    <section class="card">
        <h2>Fila da loja</h2>
        <p>“Enviado ao Windows” confirma o envio ao spool. Se a retirada ficar sem confirmação, confira a impressora antes de usar a via manual na Cozinha.</p>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Pedido</th><th>Estação</th><th>Situação</th><th>Entrada na fila</th></tr></thead>
                <tbody>
                    @forelse($jobs as $job)
                        <tr>
                            <td>#{{ $job->order_id }}</td>
                            <td>{{ $job->station ?? 'Preparo geral' }}</td>
                            <td>{{ ['pending' => 'Aguardando agente', 'claimed' => 'Retirado · aguardando confirmação', 'submitted' => 'Enviado ao Windows', 'failed' => 'Falha no envio', 'cancelled' => 'Cancelado'][$job->status] }}</td>
                            <td>{{ Illuminate\Support\Carbon::parse($job->created_at, 'UTC')->timezone($store->timezone)->format('d/m H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Nenhuma via na fila.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $jobs->links() }}
    </section>
</div>
@endsection
