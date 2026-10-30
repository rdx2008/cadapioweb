@extends('layout')
@section('page-label','Configurações da loja')
@push('styles')<link rel="stylesheet" href="/store-settings.css?v=1">@endpush
@section('content')
<div class="page-heading"><div><span class="dashboard-eyebrow">CONFIGURAÇÕES DA LOJA</span><h1>{{ $store->name }}</h1><p>Configure por assunto. Cada formulário salva somente a sua seção.</p></div><div class="actions"><a class="button secondary" href="/dashboard#lojas">Voltar às lojas</a><a class="button secondary" href="/cardapio/{{ $store->slug }}" target="_blank" rel="noopener">Ver cardápio</a></div></div>
@php($settingsOrigin=old('settings_section')??(old('payment_provider')?'pagamentos':(old('special_date')?'horarios':(old('delivery_mode')||old('zone_kind')?'entregas':''))))
<div class="store-settings-layout" data-store-settings data-settings-origin="{{ $settingsOrigin }}" data-settings-errors="{{ json_encode($errors->keys()) }}">
<div class="store-settings-content">
<section id="dados" class="store-settings-section" data-settings-section><div class="card"><h2>Dados da loja</h2><form action="/stores/{{ $store->id }}/edit" method="post" enctype="multipart/form-data">@csrf<input type="hidden" name="settings_section" value="dados">
<div class="fields">
    <label>Nome<input name="name" value="{{ old('name', $store->name) }}" required maxlength="100">
</label>
    <label>Endereço do cardápio (slug)<input name="slug" value="{{ old('slug', $store->slug) }}" required>
<small>Alterar o slug muda o link público.</small>
</label>
    <label>Recebimento de pedidos<select name="open">
<option value="1" @selected(old('open', $store->open)==1)>Ativo, respeitando horários</option>
<option value="0" @selected(old('open', $store->open)==0)>Pausado manualmente</option>
</select>
</label>
    <label>Pedido mínimo em produtos (R$)<input name="minimum_order" type="text" inputmode="decimal" value="{{ old('minimum_order', number_format($store->minimum_order_cents/100,2,',','')) }}" required>
</label>
</div>
<label>Meta interna de preparo (minutos)<input name="preparation_target_minutes" type="number" min="1" max="240" value="{{ old('preparation_target_minutes',$store->preparation_target_minutes) }}" required><small>Conta a partir de Iniciar preparo. Novas produções usam esta meta; pedidos em preparo mantêm a meta registrada.</small></label>
<div class="actions"><button>Salvar dados da loja</button><span data-settings-save-status class="muted" role="status"></span></div></form></div></section>
<section id="endereco" class="store-settings-section" data-settings-section><div class="card"><h2>Endereço e localização</h2><form action="/stores/{{ $store->id }}/edit" method="post" enctype="multipart/form-data">@csrf<input type="hidden" name="settings_section" value="endereco">
@include('partials.store-address',['addressStore'=>$store])
<div class="actions"><button>Salvar endereço</button><span data-settings-save-status class="muted" role="status"></span></div></form></div></section>
<section id="entregas" class="store-settings-section" data-settings-section><div class="card">@include('catalog.delivery-settings')</div></section>
<section id="pagamentos" class="store-settings-section" data-settings-section><div class="card"><h2>Pagamentos na entrega ou retirada</h2><form action="/stores/{{ $store->id }}/edit" method="post">@csrf<input type="hidden" name="settings_section" value="pagamentos"><p>Escolhas para pagar na entrega ou retirada. Pix e cartão são recebidos diretamente pela loja, com confirmação manual do recebimento. Para entregas com cartão, providencie a maquininha.</p><div class="fields">@foreach(App\Services\CheckoutPayment::LABELS as $key=>$label)<label>{{ $label }}<select name="accepts_{{ $key }}"><option value="1" @selected(old('accepts_'.$key,$store->{'accepts_'.$key})==1)>Aceitar</option><option value="0" @selected(old('accepts_'.$key,$store->{'accepts_'.$key})==0)>Não aceitar</option></select></label>@endforeach</div><small>Mantenha pelo menos uma forma habilitada.</small><div class="actions"><button>Salvar pagamentos presenciais</button><span data-settings-save-status class="muted" role="status"></span></div></form></div>
@include('catalog.online-payments')
</section>
<section id="aparencia" class="store-settings-section" data-settings-section><div class="card"><h2>Aparência do cardápio</h2><form action="/stores/{{ $store->id }}/edit" method="post" enctype="multipart/form-data">@csrf<input type="hidden" name="settings_section" value="aparencia">
<div class="store-settings-branding">@if($store->logo_path)<figure><img src="{{ route('stores.branding',[$store->id,'logo']) }}" alt="Logo atual da loja"><figcaption>Logo atual</figcaption></figure>@endif @if($store->cover_path)<figure><img src="{{ route('stores.branding',[$store->id,'cover']) }}" alt="Capa atual do cardápio"><figcaption>Capa atual</figcaption></figure>@endif</div>
<div class="fields"><label>Logo do cardápio<input type="file" name="logo" accept="image/jpeg,image/png,image/webp"><small>Formato quadrado. JPG, PNG ou WebP, até 2 MB.</small></label>
<label>Capa do cardápio<input type="file" name="cover" accept="image/jpeg,image/png,image/webp"><small>Imagem horizontal, idealmente 1600 × 500. Até 4 MB.</small></label>
</div>
<div class="actions"><button>Salvar aparência</button><span data-settings-save-status class="muted" role="status"></span></div></form></div></section>
<section id="horarios" class="store-settings-section" data-settings-section><div class="card"><h2>Horários de atendimento</h2><form action="/stores/{{ $store->id }}/edit" method="post" enctype="multipart/form-data">@csrf<input type="hidden" name="settings_section" value="horarios">
<div class="fields">    <label>Fuso horário<input name="timezone" value="{{ old('timezone', $store->timezone) }}" required list="timezones">
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

<div class="actions"><button>Salvar horários</button><span data-settings-save-status class="muted" role="status"></span></div></form></div>
@include('catalog.special-hours')</section>
<section id="agendamentos" class="store-settings-section" data-settings-section><div class="card"><h2>Pedidos agendados</h2><form action="/stores/{{ $store->id }}/edit" method="post" enctype="multipart/form-data">@csrf<input type="hidden" name="settings_section" value="agendamentos">
<p>O cliente escolhe o horário desejado de entrega ou retirada, sujeito ao aceite da loja. O preparo precisa caber integralmente no horário de atendimento. A pausa manual também bloqueia agendamentos. Não há reserva de capacidade ou despacho automático.</p><div class="fields">
<label>Permitir agendamento<select name="scheduling_enabled"><option value="0" @selected(!old('scheduling_enabled',$store->scheduling_enabled))>Não</option><option value="1" @selected(old('scheduling_enabled',$store->scheduling_enabled))>Sim</option></select></label>
<label>Antecedência mínima (minutos)<input type="number" name="scheduling_lead_minutes" min="15" max="1440" value="{{ old('scheduling_lead_minutes',$store->scheduling_lead_minutes) }}" required></label>
<label>Tempo reservado para preparo (minutos)<input type="number" name="scheduling_prep_minutes" min="15" max="240" value="{{ old('scheduling_prep_minutes',$store->scheduling_prep_minutes) }}" required><small>Para entregas, inclua também uma margem de deslocamento do motoboy.</small></label>
<label>Quantos dias à frente<input type="number" name="scheduling_days" min="1" max="7" value="{{ old('scheduling_days',$store->scheduling_days) }}" required></label></div><small>A antecedência mínima deve ser igual ou maior que o tempo de preparo. Horários apresentados em intervalos de 15 minutos, no fuso da loja.</small>
<div class="actions"><button>Salvar agendamentos</button><span data-settings-save-status class="muted" role="status"></span></div></form></div></section>
<section id="motoboy" class="store-settings-section" data-settings-section><div class="card"><h2>Motoboy freelancer</h2><form action="/stores/{{ $store->id }}/edit" method="post" enctype="multipart/form-data">@csrf<input type="hidden" name="settings_section" value="motoboy">

<p class="muted">Valor que a loja paga ao motoboy freelancer por corrida. Esta configuração é interna e não aparece no cardápio do cliente. O frete cobrado do cliente é configurado separadamente, na seção Entrega e frete.</p>
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

<div class="actions"><button>Salvar remuneração do motoboy</button><span data-settings-save-status class="muted" role="status"></span></div></form></div></section>
</div><aside class="store-settings-aside"><nav class="store-settings-nav card" aria-label="Seções das configurações da loja"><strong>Nesta página</strong><p class="muted">Escolha o que deseja configurar.</p><ul>
<li><a href="#dados" data-settings-link><span>Dados da loja</span><small data-settings-dirty hidden>Não salvo</small></a></li>
<li><a href="#endereco" data-settings-link><span>Endereço</span><small data-settings-dirty hidden>Não salvo</small></a></li>
<li><a href="#entregas" data-settings-link><span>Entrega e frete</span><small data-settings-dirty hidden>Não salvo</small></a></li>
<li><a href="#pagamentos" data-settings-link><span>Pagamentos</span><small data-settings-dirty hidden>Não salvo</small></a></li>
<li><a href="#aparencia" data-settings-link><span>Aparência</span><small data-settings-dirty hidden>Não salvo</small></a></li>
<li><a href="#horarios" data-settings-link><span>Horários e feriados</span><small data-settings-dirty hidden>Não salvo</small></a></li>
<li><a href="#agendamentos" data-settings-link><span>Agendamentos</span><small data-settings-dirty hidden>Não salvo</small></a></li>
<li><a href="#motoboy" data-settings-link><span>Motoboy freelancer</span><small data-settings-dirty hidden>Não salvo</small></a></li>
</ul></nav></aside></div>
<script src="/store-settings.js?v=1" defer></script>
@endsection
