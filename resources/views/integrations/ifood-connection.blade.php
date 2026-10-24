<article class="card">
    <div class="section-head"><h3>{{ $connection->store_name }}</h3><span class="badge pending">Teste</span></div>
    @if($isAdmin)<p class="muted">{{ $connection->tenant_name }}</p>@endif
    <p><strong>{{ $connection->merchant_name }}</strong><br><span class="compact muted">Merchant ID: {{ $connection->merchant_id }}</span></p>
    <p><span class="badge {{ $connection->status==='verified'?'active':($connection->status==='error'?'blocked':'pending') }}">{{ ['verified'=>'Acesso verificado','pending'=>'Verificação pendente','error'=>'Falha na consulta'][$connection->status] }}</span></p>
    <p class="compact muted">Última consulta bem-sucedida: {{ $connection->checked_at?Illuminate\Support\Carbon::parse($connection->checked_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s'):'Ainda não verificado' }}</p>
    @if($connection->last_error)<p class="notice error">{{ $connection->last_error }}</p>@endif
    @foreach(json_decode($connection->operations??'[]',true) as $operation)<p class="compact"><strong>{{ $operation['operation'] }} / {{ $operation['channel'] }}</strong><br>{{ ['OK'=>'Operação disponível','WARNING'=>'Operação com restrições','CLOSED'=>'Operação fechada','ERROR'=>'Erro na operação'][$operation['state']] }} · {{ $operation['available']?'Disponível':'Indisponível' }}</p>@endforeach
    <p class="compact muted">A situação representa a última consulta; o monitoramento automático ainda não está ativo.</p>
    <div class="actions"><form action="{{ route($isAdmin?'ifood.admin.check':'ifood.owner.check',$connection->id) }}" method="post">@csrf<button class="secondary">Consultar situação</button></form></div>
    @if($isAdmin)<details><summary>Remover vínculo local</summary><p class="compact muted">A autorização no portal iFood não será revogada. Para trocar de loja, remova este vínculo e crie outro.</p><form action="{{ route('ifood.disconnect',$connection->id) }}" method="post">@csrf<button class="outline">Confirmar remoção do vínculo</button></form></details>@endif
</article>
