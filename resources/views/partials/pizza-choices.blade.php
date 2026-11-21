@if($product->pizza_enabled)
<fieldset class="option-group" data-pizza-flavors><legend>Monte seus sabores <small data-flavor-limit></small></legend><p class="muted">{{ $product->pizza_pricing==='average'?'Preço pela média dos sabores, em partes iguais.':'Preço pelo maior valor entre os sabores escolhidos.' }} Bordas e adicionais são somados depois.</p>
@foreach($flavors->get($product->id,collect()) as $flavor)
@php($prices=json_decode($flavor->prices,true))
<label class="option-choice" data-flavor-choice><input type="checkbox" value="{{ $flavor->id }}" data-pizza-flavor data-flavor-name="{{ $flavor->name }}" data-flavor-prices='@json($prices)'><span><strong>{{ $flavor->name }}</strong><small>{{ $flavor->description }}</small><small data-flavor-price></small></span></label>
@endforeach
</fieldset>
@endif
