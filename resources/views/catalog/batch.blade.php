@extends('layout')
@section('page-label', 'Edição em lote')
@push('styles')
    <link rel="stylesheet" href="/inventory.css?v=4">
@endpush
@section('content')
<div class="inventory-page">
    <a href="{{ route('catalog.index', ['store_id' => $store->id]) }}">← Cardápio</a>
    <div class="heading">
        <h1>Alterações em lote</h1>
        <p>{{ $store->name }} · Prepare até 20 produtos por vez.</p>
    </div>
    @if($draft)
        <section class="card">
            <h2>Prévia da publicação</h2>
            <p>Confira as mudanças. A prévia fica nesta sessão até publicar, descartar ou preparar outro lote.</p>
            <ul>
                @foreach($draft['changes'] as $change)
                    <li>
                        <strong>{{ $change['name'] }}</strong>
                        <ul>
                            @if(isset($change['after']['price_cents']))
                                <li>Preço: R$ {{ number_format($change['before']['price_cents']/100, 2, ',', '.') }} → R$ {{ number_format($change['after']['price_cents']/100, 2, ',', '.') }}</li>
                            @endif
                            @if(isset($change['after']['available']))
                                <li>Disponibilidade: {{ $change['before']['available'] ? 'Habilitado' : 'Pausado' }} → {{ $change['after']['available'] ? 'Habilitado' : 'Pausado' }}</li>
                            @endif
                            @if(isset($change['after']['category']))
                                <li>Categoria: {{ $change['before']['category'] }} → {{ $change['after']['category'] }}</li>
                            @endif
                        </ul>
                    </li>
                @endforeach
            </ul>
            <form method="post" action="{{ route('catalog.batch.publish', $store->id) }}">
                @csrf
                <input type="hidden" name="key" value="{{ $draft['key'] }}">
                <label class="option-choice"><input type="checkbox" name="confirmed" value="1" required> Conferi as alterações acima.</label>
                <button>Publicar alterações</button>
            </form>
            <form method="post" action="{{ route('catalog.batch.discard', $store->id) }}">
                @csrf
                <button class="secondary">Descartar prévia</button>
            </form>
        </section>
    @endif
    <form class="card fields" method="get" action="{{ route('catalog.batch', $store->id) }}">
        <label>Buscar produto<input name="q" value="{{ request('q') }}" maxlength="120"></label>
        <button>Buscar</button>
    </form>
    <section class="card">
        <h2>Preparar alterações</h2>
        <p>Preencha apenas o que deseja alterar. Use o editor de cada produto para preços de pizzas, combos e variações.</p>
        <form method="post" action="{{ route('catalog.batch.review', $store->id) }}">
            @csrf
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Produto</th><th>Novo preço</th><th>Nova categoria</th><th>Disponibilidade</th></tr></thead>
                    <tbody>
                        @forelse($products as $product)
                            @php($row = 'rows['.$loop->index.']')
                            <tr>
                                <td>
                                    <a href="{{ url('/products/'.$product->id.'/edit') }}">{{ $product->name }}</a>
                                    <br><small>{{ $product->category }}</small>
                                    <input type="hidden" name="{{ $row }}[id]" value="{{ $product->id }}">
                                    <input type="hidden" name="{{ $row }}[version]" value="{{ App\Http\Controllers\CatalogBatchController::version($product) }}">
                                </td>
                                <td>
                                    @if(App\Http\Controllers\CatalogBatchController::allowsPrice($product))
                                        <input aria-label="Novo preço de {{ $product->name }}" name="{{ $row }}[price]" inputmode="decimal" maxlength="12" placeholder="{{ number_format($product->price_cents/100, 2, ',', '') }}">
                                    @else
                                        No editor do produto
                                    @endif
                                </td>
                                <td>
                                    <select aria-label="Nova categoria de {{ $product->name }}" name="{{ $row }}[category_id]">
                                        <option value="">Manter atual</option>
                                        @foreach($categories as $category)
                                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select aria-label="Disponibilidade de {{ $product->name }}" name="{{ $row }}[available]">
                                        <option value="">Manter {{ $product->available ? 'habilitado' : 'pausado' }}</option>
                                        <option value="1">Habilitar</option>
                                        <option value="0">Pausar</option>
                                    </select>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4">Nenhum produto encontrado.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($products->isNotEmpty())
                <button>Revisar lote</button>
            @endif
        </form>
        {{ $products->links() }}
    </section>
</div>
@endsection
