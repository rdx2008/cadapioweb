<section aria-labelledby="setup-title" class="card setup-card">
<h2 id="setup-title">Próximos passos</h2>
<p class="muted">Prepare sua loja para vender.</p>
@forelse($setupStores as $setupStore)
<h3>{{ $setupStore->name }}</h3>
@foreach([[$setupStore->available_products>0,'Cadastrar produtos',$setupStore->available_products>0?'✓ Produtos disponíveis':'Falta cadastrar ou ativar um produto','#cardapio'],[(bool)$setupStore->weekly_hours,'Configurar horários',$setupStore->weekly_hours?'Horários configurados':'Sem horário semanal: a loja depende da abertura e pausa manual','/stores/'.$setupStore->id.'/edit'],[$setupStore->active_zones>0,'Definir regiões de entrega',$setupStore->active_zones>0?'Regiões de entrega configuradas':'Retirada disponível; cadastre uma região para oferecer entrega','/stores/'.$setupStore->id.'/edit']] as [$done,$label,$hint,$href])
<a class="setup-step" href="{{ $href }}"><span class="step-icon {{ $done?'is-done':'' }}">@if($done)@include('partials.icon',['name'=>'check'])@endif</span><span><strong>{{ $label }}</strong><small>{{ $hint }}</small></span>@include('partials.icon',['name'=>'arrow'])</a>
@endforeach
@empty<p>Comece criando sua primeira loja. Depois, cadastre os produtos e faça um pedido de teste pelo cardápio público.</p><a class="button" href="#lojas">Cadastrar primeira loja</a>@endforelse
</section>