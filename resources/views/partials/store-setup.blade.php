<section aria-labelledby="setup-title" class="card">
    <h2 id="setup-title">Prepare sua loja para vender</h2>
    @forelse($setupStores as $setupStore)
        <h3>{{ $setupStore->name }}</h3>
        <ul>
            <li>{{ $setupStore->available_products > 0 ? '✓ Produtos disponíveis' : 'Falta cadastrar ou ativar um produto' }} · <a href="#cardapio">Gerenciar cardápio</a></li>
            <li>{{ $setupStore->weekly_hours ? '✓ Horários configurados' : 'Sem horário semanal: a loja depende da abertura e pausa manual' }} · <a href="/stores/{{ $setupStore->id }}/edit">Configurar horários</a></li>
            <li>{{ $setupStore->active_zones > 0 ? '✓ Regiões de entrega configuradas' : 'Retirada disponível; cadastre uma região para oferecer entrega' }} · <a href="/stores/{{ $setupStore->id }}/edit">Configurar entrega</a></li>
            <li>{{ $setupStore->open ? '✓ Recebimento de pedidos habilitado, conforme os horários' : 'Loja pausada: novos pedidos estão bloqueados' }} · <a href="/stores/{{ $setupStore->id }}/edit">Abrir ou pausar</a></li>
        </ul>
        <p><a class="button secondary" href="/cardapio/{{ $setupStore->slug }}" target="_blank" rel="noopener">Abrir cardápio público</a></p>
        <label>Link para compartilhar<input readonly value="{{ url('/cardapio/'.$setupStore->slug) }}" onclick="this.select()"></label>
    @empty
        <p>Comece criando sua primeira loja. Depois, cadastre os produtos e faça um pedido de teste pelo cardápio público.</p>
        <a class="button" href="#lojas">Cadastrar primeira loja</a>
    @endforelse
    <p class="muted">Para testar, confirme um pedido pelo cardápio e acompanhe em Pedidos & cozinha. O recebimento é registrado manualmente; pagamentos online ainda não estão integrados.</p>
</section>
