@extends('layout')
@section('content')
<div class="heading">
<div class="eyebrow">Configuração da loja</div>
<h1>{{ $store->name }}</h1>
<a href="/dashboard#lojas">← Voltar ao painel</a>
</div>
<form action="/stores/{{ $store->id }}/edit" method="post" enctype="multipart/form-data" class="card">@csrf
    <h2>Identidade e operação</h2>
<details class="card"><summary>Formas de pagamento</summary><p>Escolhas para pagar na entrega ou retirada. Pix e cartão são recebidos diretamente pela loja, com confirmação manual do recebimento. Não há cobrança online nesta etapa. Para entregas com cartão, providencie a maquininha.</p><div class="fields">@foreach(App\Services\CheckoutPayment::LABELS as $key=>$label)<label>{{ $label }}<select name="accepts_{{ $key }}"><option value="1" @selected(old('accepts_'.$key,$store->{'accepts_'.$key})==1)>Aceitar</option><option value="0" @selected(old('accepts_'.$key,$store->{'accepts_'.$key})==0)>Não aceitar</option></select></label>@endforeach</div><small>Mantenha pelo menos uma forma habilitada.</small></details>
<details class="card"><summary>Pedidos agendados</summary><p>O cliente escolhe o horário desejado de entrega ou retirada, sujeito ao aceite da loja. O preparo precisa caber integralmente no horário de atendimento. A pausa manual também bloqueia agendamentos. Não há reserva de capacidade ou despacho automático.</p><div class="fields">
<label>Permitir agendamento<select name="scheduling_enabled"><option value="0" @selected(!old('scheduling_enabled',$store->scheduling_enabled))>Não</option><option value="1" @selected(old('scheduling_enabled',$store->scheduling_enabled))>Sim</option></select></label>
<label>Antecedência mínima (minutos)<input type="number" name="scheduling_lead_minutes" min="15" max="1440" value="{{ old('scheduling_lead_minutes',$store->scheduling_lead_minutes) }}" required></label>
<label>Tempo reservado para preparo (minutos)<input type="number" name="scheduling_prep_minutes" min="15" max="240" value="{{ old('scheduling_prep_minutes',$store->scheduling_prep_minutes) }}" required><small>Para entregas, inclua também uma margem de deslocamento do motoboy.</small></label>
<label>Quantos dias à frente<input type="number" name="scheduling_days" min="1" max="7" value="{{ old('scheduling_days',$store->scheduling_days) }}" required></label></div><small>A antecedência mínima deve ser igual ou maior que o tempo de preparo. Horários apresentados em intervalos de 15 minutos, no fuso da loja.</small></details>
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
    <h2 style="margin-top:24px">Remuneração do motoboy freelancer</h2>
<p class="muted">Valor que a loja paga ao motoboy freelancer por corrida. Esta configuração é interna e não aparece no cardápio do cliente. O frete cobrado do cliente é configurado separadamente, na seção Entrega e frete abaixo.</p>
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
@include('catalog.special-hours')
@include('catalog.delivery-settings')
@include('catalog.online-payments')
@endsection
