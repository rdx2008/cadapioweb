<nav class="inventory-nav" aria-label="Estoque e compras">
@foreach(['inventory.index'=>'Estoque','purchases.index'=>'Compras','suppliers.index'=>'Fornecedores','costs.index'=>'Custos e margens'] as $name=>$label)
<a href="{{ route($name,['store_id'=>$store?->id]) }}" @if(request()->routeIs($name)) aria-current="page" @endif>{{ $label }}</a>
@endforeach
</nav>
