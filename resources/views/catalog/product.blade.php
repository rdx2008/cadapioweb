@extends('layout')
@section('page-label','Cardápio')
@section('content')
<link rel="stylesheet" href="/catalog.css?v=1">
<div class="heading">
<div class="eyebrow">Catálogo da loja</div>
<h1>{{ $product->name }}</h1>
<a href="/catalog">← Voltar ao catálogo</a>
</div>
<nav class="catalog-editor-steps" aria-label="Etapas do produto"><a href="#product-basics">Dados e foto</a><a href="#variants">Tamanhos e preços</a>@if($product->product_type==='pizza')<a href="#pizza">Sabores de pizza</a>@endif<a href="#product-addons">Adicionais e revisão</a></nav><p class="muted">Confira a apresentação, os tamanhos e os adicionais antes de divulgar. Os botões de cada seção salvam suas próprias alterações.</p><form id="product-basics" action="/products/{{ $product->id }}/edit" method="post" enctype="multipart/form-data" class="card">@csrf
<div class="fields">
<label>Nome<input name="name" value="{{ old('name', $product->name) }}" required>
</label>
<label>Tipo de produto<select name="product_type"><option value="common" @selected(old('product_type',$product->product_type)==='common')>Produto comum</option><option value="pizza" @selected(old('product_type',$product->product_type)==='pizza')>Pizza</option></select><small>Salve para carregar as configurações do tipo escolhido. Ao mudar, o produto fica pausado para revisão.</small></label>
<input type="hidden" name="category" value="{{ $product->category }}"><label>Categoria<select name="category_id"><option value="">{{ $product->category }} (atual)</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id',$product->category_id)==$category->id)>{{ $category->name }}</option>@endforeach</select><small><a href="{{ route('catalog.index',['store_id'=>$product->store_id]) }}#categories">Gerenciar categorias</a></small></label>
<label>Destaque no cardápio<select name="featured"><option value="0" @selected(old('featured',$product->featured)==0)>Não</option><option value="1" @selected(old('featured',$product->featured)==1)>Sim, mostrar na vitrine</option></select></label><label>Ordem dentro da categoria<input name="sort_order" type="number" min="0" max="9999" value="{{ old('sort_order',$product->sort_order) }}"><small>Menor número aparece primeiro.</small></label>
<label>Preço (R$)<input name="price" type="text" inputmode="decimal" value="{{ old('price', number_format($product->price_cents/100,2,',','')) }}" required>
</label>
<label>Disponibilidade<select name="available">
<option value="1" @selected(old('available', $product->available)==1)>Disponível</option>
<option value="0" @selected(old('available', $product->available)==0)>Pausado</option>
</select>
</label>
<label class="full">Descrição<textarea name="description">{{ old('description', $product->description) }}</textarea>
</label>
<label>Foto (JPG, PNG ou WebP; até 2 MB)<input name="photo" type="file" accept="image/jpeg,image/png,image/webp">
</label>@if($product->photo_path)<img src="/media/products/{{ $product->id }}?v={{ urlencode($product->updated_at) }}" alt="Foto de {{ $product->name }}" class="product-photo">@endif</div>
<div class="actions">
<button>Salvar produto</button>
</div>
</form>
@include('catalog.variants')
@if($product->product_type==='pizza')@include('catalog.pizza')@endif
<section id="product-addons">
<h2>Grupos e adicionais</h2>
<p class="muted">As escolhas valem para cada unidade do produto. Exemplo: 2 lanches com queijo cobram o queijo duas vezes. Para combinações diferentes, o cliente pode adicionar outro item do mesmo produto na sacola.</p>
@foreach($groups as $group)<article class="card" style="margin-bottom:20px">
<form action="/products/{{ $product->id }}/groups" method="post" class="fields">@csrf<input type="hidden" name="group_id" value="{{ $group->id }}">
<label>Nome do grupo<input name="name" value="{{ $group->name }}" required>
</label>
@if($product->product_type==='pizza')<label>Tipo<select name="kind"><option value="addon" @selected($group->kind==='addon')>Adicionais</option><option value="border" @selected($group->kind==='border')>Borda (uma escolha)</option></select></label>@else<input type="hidden" name="kind" value="addon">@endif<label>Mínimo de escolhas<input name="minimum" type="number" min="0" max="20" value="{{ $group->minimum }}" required>
</label>
<label>Máximo de escolhas<input name="maximum" type="number" min="1" max="20" value="{{ $group->maximum }}" required>
</label>
<div class="actions">
<button class="secondary">Salvar grupo</button>
</div>
</form>
<h3 style="margin-top:20px">Opções</h3>@foreach($options->get($group->id, collect()) as $option)<form action="/products/{{ $product->id }}/options" method="post" class="fields option-edit">@csrf<input type="hidden" name="option_group_id" value="{{ $group->id }}">
<input type="hidden" name="option_id" value="{{ $option->id }}">
<label>Nome<input name="name" value="{{ $option->name }}" required>
</label>
<label>Preço (R$)<input name="price" type="text" inputmode="decimal" value="{{ number_format($option->price_cents/100,2,',','') }}" required>
</label>
<label>Disponibilidade<select name="available">
<option value="1" @selected($option->available)>Disponível</option>
<option value="0" @selected(!$option->available)>Pausado</option>
</select>
</label>
<div class="actions">
<button class="secondary">Salvar opção</button>
</div>
</form>@endforeach
<details>
<summary>+ Adicionar opção a {{ $group->name }}</summary>
<form action="/products/{{ $product->id }}/options" method="post" class="fields">@csrf<input type="hidden" name="option_group_id" value="{{ $group->id }}">
<input type="hidden" name="available" value="1">
<label>Nome<input name="name" required>
</label>
<label>Preço (R$; 0 para grátis)<input name="price" type="text" inputmode="decimal" value="0,00" required>
</label>
<button>Adicionar opção</button>
</form>
</details>
</article>@endforeach
<details>
<summary>+ Criar grupo de escolhas</summary>
<form action="/products/{{ $product->id }}/groups" method="post" class="fields">@csrf<label>Nome do grupo<input name="name" placeholder="Escolha o molho" required>
</label>
@if($product->product_type==='pizza')<label>Tipo<select name="kind"><option value="addon">Adicionais</option><option value="border">Borda (uma escolha)</option></select></label>@else<input type="hidden" name="kind" value="addon">@endif<label>Mínimo (0 = opcional)<input name="minimum" type="number" min="0" max="20" value="0" required>
</label>
<label>Máximo<input name="maximum" type="number" min="1" max="20" value="1" required>
</label>
<button>Criar grupo</button>
</form>
</details>
</section>
@endsection
