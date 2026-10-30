@php
    $deliveryMode = old('delivery_mode', app(App\Services\DeliveryCoverage::class)->mode($store));
    $deliveryPricing = old('delivery_pricing', $store->delivery_pricing);
@endphp
<section id="delivery-settings" data-delivery-settings>
<h2>Entrega e frete do cliente</h2>
<p class="muted">O cliente informa o endereço. O sistema identifica a área atendida e calcula o frete automaticamente, sem pedir que ele escolha bairro ou distância.</p>
@if(!config('services.geoapify.key'))<p class="notice">A consulta automática de endereços ainda não foi ativada pela plataforma. Solicite a ativação ao suporte. A retirada continua disponível.</p>@endif
<form action="/stores/{{ $store->id }}/delivery-settings" method="post" class="card" data-delivery-settings-form>@csrf
<label>Como definir a área de atendimento?<select name="delivery_mode" data-delivery-setting-mode><option value="neighborhood" @selected($deliveryMode==='neighborhood')>Por bairros/regiões</option><option value="radius" @selected($deliveryMode==='radius')>Por raio de distância</option></select></label>
<div data-setting-area="neighborhood"><p class="muted">Cadastre o nome exato do bairro, a cidade, a UF e o frete. Usamos o bairro identificado no endereço para encontrar a tarifa. Nomes parecidos não são aceitos por aproximação.</p></div>
<div data-setting-area="radius"><p class="muted">A distância é calculada automaticamente entre a localização salva da loja e o endereço do cliente. Não precisa cadastrar bairros. O raio é em linha reta, não o caminho pelas ruas.</p>
<label>Como cobrar o frete?<select name="delivery_pricing" data-delivery-setting-pricing><option value="fixed" @selected($deliveryPricing==='fixed')>Frete fixo dentro do raio</option><option value="bands" @selected($deliveryPricing==='bands')>Por faixas de distância</option><option value="per_km" @selected($deliveryPricing==='per_km')>Base + valor por quilômetro</option></select></label>
<div class="fields" data-setting-rule="fixed,per_km"><label>Alcance máximo de entrega (km)<input name="delivery_max_km" type="number" min="0.1" max="100" step="0.1" value="{{ old('delivery_max_km',$store->delivery_max_meters/1000) }}" required></label></div>
<div class="fields" data-setting-rule="fixed"><label>Frete cobrado do cliente (R$)<input name="delivery_fixed" inputmode="decimal" value="{{ old('delivery_fixed',number_format($store->delivery_fixed_cents/100,2,',','')) }}" required></label></div>
<div data-setting-rule="bands"><p class="muted">Cadastre as faixas abaixo. Exemplo: até 3 km por R$ 5; acima de 3 até 5 km por R$ 8. O limite da maior faixa ativa será o alcance máximo.</p></div>
<div class="fields" data-setting-rule="per_km"><label>Valor base (R$)<input name="delivery_base" inputmode="decimal" value="{{ old('delivery_base',number_format($store->delivery_base_cents/100,2,',','')) }}" required></label><label>Valor por km (R$)<input name="delivery_per_km" inputmode="decimal" value="{{ old('delivery_per_km',number_format($store->delivery_per_km_cents/100,2,',','')) }}" required></label><label>Frete mínimo (R$)<input name="delivery_minimum" inputmode="decimal" value="{{ old('delivery_minimum',number_format($store->delivery_minimum_cents/100,2,',','')) }}" required></label><p class="muted">Usa a distância proporcional, sem arredondar para quilômetros inteiros. O valor final é arredondado para centavos e respeita o frete mínimo.</p></div>
</div><button>Salvar entrega e frete</button>
</form>
@foreach(['neighborhood'=>'Bairros/regiões atendidos','radius'=>'Faixas de distância e frete'] as $kind=>$heading)
<div data-area-editor="{{ $kind }}"><h3>{{ $heading }}</h3>
@foreach($zones->filter(fn($zone)=>$kind==='radius'?$zone->radius_meters!==null:$zone->radius_meters===null) as $zone)
<form action="/stores/{{ $store->id }}/zones" method="post" class="card fields" style="margin-bottom:12px">@csrf<input type="hidden" name="zone_id" value="{{ $zone->id }}"><input type="hidden" name="zone_kind" value="{{ $kind }}">
@if($kind==='neighborhood')<label>Bairro/região<input name="name" value="{{ $zone->name }}" required maxlength="120"></label><label>Cidade<input name="city" value="{{ $zone->city }}" required maxlength="120"></label><label>UF<input name="state_code" value="{{ $zone->state_code }}" required maxlength="2" placeholder="SP"></label>@if(!$zone->city||!$zone->state_code)<p class="notice full">Complete cidade e UF para este bairro participar do cálculo automático.</p>@endif
@else<input type="hidden" name="name" value="{{ $zone->name }}"><label>Atender até (km)<input name="radius_km" type="number" min="0.1" max="100" step="0.1" value="{{ $zone->radius_meters/1000 }}" required></label>@endif
<label>Frete do cliente (R$)<input name="fee" value="{{ number_format($zone->fee_cents/100,2,',','') }}" inputmode="decimal" required></label><label>Disponibilidade<select name="enabled"><option value="1" @selected($zone->enabled)>Ativa</option><option value="0" @selected(!$zone->enabled)>Pausada</option></select></label><div class="actions"><button class="secondary">Salvar {{ $kind==='radius'?'faixa':'bairro' }}</button></div></form>
@endforeach
<details><summary>+ Adicionar {{ $kind==='radius'?'faixa de distância':'bairro/região' }}</summary><form action="/stores/{{ $store->id }}/zones" method="post" class="fields">@csrf<input type="hidden" name="enabled" value="1"><input type="hidden" name="zone_kind" value="{{ $kind }}">
@if($kind==='neighborhood')<label>Bairro/região<input name="name" required maxlength="120"></label><label>Cidade<input name="city" required maxlength="120"></label><label>UF<input name="state_code" required maxlength="2" placeholder="SP"></label>@else<input type="hidden" name="name" value="Faixa de distância"><label>Atender até (km)<input name="radius_km" type="number" min="0.1" max="100" step="0.1" required placeholder="Ex.: 3"></label>@endif
<label>Frete do cliente (R$)<input name="fee" value="5,00" inputmode="decimal" required></label><button>Cadastrar {{ $kind==='radius'?'faixa':'bairro' }}</button></form></details>
</div>@endforeach
</section>
