@extends('layout')
@section('content')
<div class="heading">
<div class="eyebrow">Configuração da loja</div>
<h1>{{ $store->name }}</h1>
<a href="/dashboard#lojas">← Voltar ao painel</a>
</div>
<form action="/stores/{{ $store->id }}/edit" method="post" enctype="multipart/form-data" class="card">@csrf
    <h2>Identidade e operação</h2>
<div class="fields">
    <label>Nome<input name="name" value="{{ old('name', $store->name) }}" required maxlength="100">
</label>
    <label>Endereço do cardápio (slug)<input name="slug" value="{{ old('slug', $store->slug) }}" required>
<small>Alterar o slug muda o link público.</small>
</label>
    <label class="full">Endereço<input name="address" value="{{ old('address', $store->address) }}" required>
</label>
<div class="full"><button type="button" class="outline" data-store-locate data-url="/stores/{{ $store->id }}/locate">Localizar endereço da loja</button><div data-store-location-message role="status" aria-live="polite"></div><small>Use rua, número, bairro, cidade e estado. O ponto da loja será o centro do raio de entrega.</small><small>Powered by <a href="https://www.geoapify.com/">Geoapify</a> · <a href="https://www.openstreetmap.org/copyright">© OpenStreetMap contributors</a></small></div>
<label>Logo do cardápio<input type="file" name="logo" accept="image/jpeg,image/png,image/webp"><small>Formato quadrado. JPG, PNG ou WebP, até 2 MB.</small></label>
<label>Capa do cardápio<input type="file" name="cover" accept="image/jpeg,image/png,image/webp"><small>Imagem horizontal, idealmente 1600 × 500. Até 4 MB.</small></label>
    <label>Latitude<input name="latitude" type="number" step="any" value="{{ old('latitude', $store->latitude) }}" required>
</label>
    <label>Longitude<input name="longitude" type="number" step="any" value="{{ old('longitude', $store->longitude) }}" required>
</label>
    <label>Recebimento de pedidos<select name="open">
<option value="1" @selected(old('open', $store->open)==1)>Ativo, respeitando horários</option>
<option value="0" @selected(old('open', $store->open)==0)>Pausado manualmente</option>
</select>
</label>
    <label>Pedido mínimo em produtos (R$)<input name="minimum_order" type="text" inputmode="decimal" value="{{ old('minimum_order', number_format($store->minimum_order_cents/100,2,',','')) }}" required>
</label>
    <label>Fuso horário<input name="timezone" value="{{ old('timezone', $store->timezone) }}" required list="timezones">
<datalist id="timezones">
<option value="America/Sao_Paulo">
<option value="America/Manaus">
<option value="America/Fortaleza">
<option value="America/Rio_Branco">
</datalist>
</label>
    <label>Aplicar horários semanais<select name="use_hours">
<option value="0" @selected(old('use_hours', $hours!==null)==0)>Não — aberto enquanto não pausado</option>
<option value="1" @selected(old('use_hours', $hours!==null)==1)>Sim</option>
</select>
</label>
    </div>
<h2 style="margin-top:24px">Horários semanais</h2>
<p class="muted">Até dois períodos por dia, como almoço e jantar. Entre os períodos, novos pedidos ficam bloqueados automaticamente.</p>
<p class="muted">No celular, deslize a tabela para o lado para editar os horários.</p>
    <div class="table-wrap">
<table>
<tr>
<th>Dia</th>
<th>Atendimento</th>
<th>Abre</th>
<th>Fecha</th>
<th>2º período: abre</th>
<th>2º período: fecha</th>
</tr>
    @foreach(['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'] as $day=>$label)
    <tr>
<td>{{ $label }}</td>
<td>
<select name="hours[{{ $day }}][enabled]">
<option value="1" @selected(old('hours.'.$day.'.enabled', $hours[$day]['enabled']??1)==1)>Aberto</option>
<option value="0" @selected(old('hours.'.$day.'.enabled', $hours[$day]['enabled']??1)==0)>Fechado</option>
</select>
</td>
<td>
<input type="time" name="hours[{{ $day }}][opens_at]" value="{{ old('hours.'.$day.'.opens_at', $hours[$day]['opens_at']??'18:00') }}" required>
</td>
<td>
<input type="time" name="hours[{{ $day }}][closes_at]" value="{{ old('hours.'.$day.'.closes_at', $hours[$day]['closes_at']??'23:00') }}" required>
</td>
<td><input type="time" aria-label="{{ $label }}: abertura do segundo período" name="hours[{{ $day }}][second_opens_at]" value="{{ old('hours.'.$day.'.second_opens_at', $hours[$day]['second_opens_at']??'') }}"></td>
<td><input type="time" aria-label="{{ $label }}: fechamento do segundo período" name="hours[{{ $day }}][second_closes_at]" value="{{ old('hours.'.$day.'.second_closes_at', $hours[$day]['second_closes_at']??'') }}"></td>
</tr>
    @endforeach</table>
</div>
<p class="muted">O segundo período é opcional. Deixe os dois campos vazios para atender em um único período. Fechamento anterior à abertura indica atendimento até o dia seguinte; horários iguais representam 24 horas.</p>
<h2 id="delivery-mode" style="margin-top:24px">Como validar a entrega do cliente</h2>
<label>Área de atendimento<select name="delivery_by_radius"><option value="0" @selected(!old('delivery_by_radius',$store->delivery_by_radius))>Regiões manuais — conferência do endereço pela loja</option><option value="1" @selected(old('delivery_by_radius',$store->delivery_by_radius))>Raio automático — localizar endereço e calcular frete</option></select></label>
@if(!config('services.geoapify.key'))<p class="notice">A plataforma precisa configurar GEOAPIFY_API_KEY para ativar consultas reais. A entrega por raio não aprova endereços sem uma consulta válida.</p>@endif
    <h2 style="margin-top:24px">Remuneração do motoboy freelancer</h2>
<p class="muted">Valor que a loja paga ao motoboy freelancer por corrida. Esta configuração é interna e não aparece no cardápio do cliente. O frete cobrado do cliente é configurado separadamente, nas faixas de entrega abaixo.</p>
<div class="fields">
    <label>Modo<select name="rate_mode">@foreach(['fixed'=>'Taxa fixa','per_km'=>'Base + quilômetro','per_ride'=>'Cotação por corrida'] as $value=>$label)<option value="{{ $value }}" @selected(old('rate_mode', $store->rate_mode)===$value)>{{ $label }}</option>@endforeach</select>
</label>
    <label>Base/fixo (R$)<input name="base_fare" type="text" inputmode="decimal" value="{{ old('base_fare', number_format($store->base_cents/100,2,',','')) }}" required>
</label>
    <label>Preço por km (R$)<input name="per_km_fare" type="text" inputmode="decimal" value="{{ old('per_km_fare', number_format($store->per_km_cents/100,2,',','')) }}" required>
</label>
    <label>Mínimo (R$)<input name="minimum_fare" type="text" inputmode="decimal" value="{{ old('minimum_fare', number_format($store->minimum_cents/100,2,',','')) }}" required>
</label>
    </div>
<div class="actions">
<button>Salvar configuração</button>
<a class="button secondary" href="/cardapio/{{ $store->slug }}">Ver cardápio</a>
</div>
</form>
<section>
<h2>Regiões atendidas e frete do cliente</h2>
<p class="muted">No modo por raio, o endereço do cliente é localizado por API. A menor faixa ativa que cobre o endereço define o frete. Exemplo: até 3 km por R$ 5 e até 5 km por R$ 8. O raio é medido em linha reta, não representa distância de rota nem remuneração por quilômetro do motoboy. Faixas sem raio não são usadas neste modo.</p>
<a class="subtle-link" href="#delivery-mode">Configurar modo de entrega ↑</a>
@foreach($zones as $zone)<form action="/stores/{{ $store->id }}/zones" method="post" class="card fields" style="margin-bottom:12px">@csrf<input type="hidden" name="zone_id" value="{{ $zone->id }}">
<label>Região<input name="name" value="{{ $zone->name }}" required>
</label>
<label>Frete (R$)<input name="fee" type="text" inputmode="decimal" value="{{ number_format($zone->fee_cents/100,2,',','') }}" required>
</label>
<label>Raio máximo a partir da loja (km)<input name="radius_km" type="number" step="0.1" min="0.1" max="100" value="{{ $zone->radius_meters?$zone->radius_meters/1000:'' }}"><small>Opcional no modo manual; obrigatório para participar da entrega por raio.</small></label>
<label>Disponibilidade<select name="enabled">
<option value="1" @selected($zone->enabled)>Ativa</option>
<option value="0" @selected(!$zone->enabled)>Pausada</option>
</select>
</label>
<div class="actions">
<button class="secondary">Salvar região</button>
</div>
</form>@endforeach
<details>
<summary>+ Adicionar região</summary>
<form action="/stores/{{ $store->id }}/zones" method="post" class="fields">@csrf<input type="hidden" name="enabled" value="1">
<label>Nome do bairro/região<input name="name" required maxlength="120">
</label>
<label>Frete (R$)<input name="fee" type="text" inputmode="decimal" value="5,00" required>
</label>
<label>Raio máximo a partir da loja (km)<input name="radius_km" type="number" step="0.1" min="0.1" max="100" placeholder="Ex.: 3"><small>Deixe vazio somente para regiões manuais.</small></label>
<button>Cadastrar região</button>
</form>
</details>
</section>
@endsection
