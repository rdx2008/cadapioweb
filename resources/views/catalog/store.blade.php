@extends('layout')
@section('content')
<div class="heading">
<div class="eyebrow">Configuração da loja</div>
<h1>{{ $store->name }}</h1>
<a href="/dashboard#lojas">← Voltar ao painel</a>
</div>
<form action="/stores/{{ $store->id }}/edit" method="post" class="card">@csrf
    <h2>Identidade e operação</h2>
<div class="fields">
    <label>Nome<input name="name" value="{{ old('name', $store->name) }}" required maxlength="100">
</label>
    <label>Endereço do cardápio (slug)<input name="slug" value="{{ old('slug', $store->slug) }}" required>
<small>Alterar o slug muda o link público.</small>
</label>
    <label class="full">Endereço<input name="address" value="{{ old('address', $store->address) }}" required>
</label>
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
<p class="muted">Um intervalo por dia. Fechamento anterior à abertura continua no dia seguinte. Horários iguais significam 24 horas a partir da abertura.</p>
    <div class="table-wrap">
<table>
<tr>
<th>Dia</th>
<th>Atendimento</th>
<th>Abre</th>
<th>Fecha</th>
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
</tr>
    @endforeach</table>
</div>
    <h2 style="margin-top:24px">Remuneração do freelancer</h2>
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
<p class="muted">O checkout só permite entrega em uma região ativa. Cadastre bairros ou regiões com nomes claros. A seleção do bairro não verifica automaticamente a posição geográfica do endereço; a loja deve conferir o endereço antes do aceite.</p>
@foreach($zones as $zone)<form action="/stores/{{ $store->id }}/zones" method="post" class="card fields" style="margin-bottom:12px">@csrf<input type="hidden" name="zone_id" value="{{ $zone->id }}">
<label>Região<input name="name" value="{{ $zone->name }}" required>
</label>
<label>Frete (R$)<input name="fee" type="text" inputmode="decimal" value="{{ number_format($zone->fee_cents/100,2,',','') }}" required>
</label>
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
<button>Cadastrar região</button>
</form>
</details>
</section>
@endsection
