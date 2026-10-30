@php($specialHours = json_decode($store->special_hours ?? '{}', true))
<section id="special-hours" class="card">
<h2>Feriados e horários especiais</h2>
<p class="muted">Cadastre cada data em que o atendimento será diferente. A exceção substitui os horários semanais, mesmo quando eles estão desativados. O fuso usado é {{ $store->timezone }}.</p>
<p class="muted">Uma data especial começa à meia-noite e interrompe o atendimento iniciado no dia anterior. Fechamento anterior ou igual à abertura indica o dia seguinte. Se houver outra exceção no dia seguinte, ela prevalece desde a meia-noite. A pausa manual sempre bloqueia novos pedidos.</p>
<details @if($errors->hasAny(['special_date','enabled','opens_at','closes_at','second_opens_at','second_closes_at'])) open @endif>
<summary>+ Adicionar ou substituir data</summary>
<p class="muted">Para alterar uma data já cadastrada, informe a mesma data e salve a nova configuração. Feriados não são cadastrados automaticamente.</p>
<form action="{{ route('stores.special-hours', $store->id) }}" method="post" class="fields">@csrf
<label>Data<input type="date" name="special_date" value="{{ old('special_date') }}" required></label>
<label>Atendimento<select name="enabled"><option value="0" @selected(old('enabled','0')=='0')>Fechado o dia inteiro</option><option value="1" @selected(old('enabled')=='1')>Abrir em horário especial</option></select></label>
<label>Abertura<input type="time" name="opens_at" value="{{ old('opens_at') }}"><small>Obrigatório ao abrir nesta data.</small></label>
<label>Fechamento<input type="time" name="closes_at" value="{{ old('closes_at') }}"></label>
<label>2º período: abertura (opcional)<input type="time" name="second_opens_at" value="{{ old('second_opens_at') }}"></label>
<label>2º período: fechamento (opcional)<input type="time" name="second_closes_at" value="{{ old('second_closes_at') }}"></label>
<div><button>Salvar data especial</button></div>
</form></details>
<div class="table-wrap"><table><thead><tr><th>Data</th><th>Atendimento</th><th>Ação</th></tr></thead><tbody>
@forelse($specialHours as $date => $window)
<tr><td>{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</td><td>@if($window['enabled']){{ $window['opens_at'] }} às {{ $window['closes_at'] }}{{ $window['closes_at'] <= $window['opens_at'] ? ' (dia seguinte)' : '' }}@if(!empty($window['second_opens_at']))<br>{{ $window['second_opens_at'] }} às {{ $window['second_closes_at'] }}{{ $window['second_closes_at'] <= $window['second_opens_at'] ? ' (dia seguinte)' : '' }}@endif @else<span class="badge cancelled">Fechado o dia inteiro</span>@endif</td><td><form action="{{ route('stores.special-hours.remove', $store->id) }}" method="post">@csrf<input type="hidden" name="special_date" value="{{ $date }}"><button class="secondary" aria-label="Remover exceção de {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}">Remover exceção</button></form></td></tr>
@empty<tr><td colspan="3">Nenhuma exceção cadastrada. Vale a configuração semanal.</td></tr>@endforelse
</tbody></table></div>
</section>
