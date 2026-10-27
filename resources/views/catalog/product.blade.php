@extends('layout')
@section('content')
<div class="heading">
<div class="eyebrow">Catálogo da loja</div>
<h1>{{ $product->name }}</h1>
<a href="/dashboard#cardapio">← Voltar ao catálogo</a>
</div>
<form action="/products/{{ $product->id }}/edit" method="post" enctype="multipart/form-data" class="card">@csrf
<div class="fields">
<label>Nome<input name="name" value="{{ old('name', $product->name) }}" required>
</label>
<label>Categoria<input name="category" value="{{ old('category', $product->category) }}" required>
</label>
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
<section>
<h2>Grupos e adicionais</h2>
<p class="muted">As escolhas valem para cada unidade do produto. Exemplo: 2 lanches com queijo cobram o queijo duas vezes. Para combinações diferentes, faça pedidos separados nesta versão.</p>
@foreach($groups as $group)<article class="card" style="margin-bottom:20px">
<form action="/products/{{ $product->id }}/groups" method="post" class="fields">@csrf<input type="hidden" name="group_id" value="{{ $group->id }}">
<label>Nome do grupo<input name="name" value="{{ $group->name }}" required>
</label>
<label>Mínimo de escolhas<input name="minimum" type="number" min="0" max="20" value="{{ $group->minimum }}" required>
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
<label>Mínimo (0 = opcional)<input name="minimum" type="number" min="0" max="20" value="0" required>
</label>
<label>Máximo<input name="maximum" type="number" min="1" max="20" value="1" required>
</label>
<button>Criar grupo</button>
</form>
</details>
</section>
@endsection
