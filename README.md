# CadapioWeb

Plataforma de restaurantes em **Laravel 13 / PHP 8.4**, com múltiplas empresas na mesma instalação e implantação pelo Coolify.

## Estado desta entrega

Esta versão contém a fundação, o fluxo inicial de venda/produção web, o novo painel e a primeira etapa do conector iFood, ainda para piloto assistido. O escopo completo está em `docs/escopo.md`; os recursos descritos no escopo não devem ser confundidos com recursos já implementados.

**Prioridade definida em 24/10/2026:** concluir os fluxos web primeiro. Android foi adiado e não é dependência da primeira entrega web. O backlog e o estado por módulo estão em `docs/status-web.md`.

### Implementado

- Login, logout, limitação de tentativas e perfis de superadmin, proprietário e freelancer.
- Novo visual responsivo de tenant/superadmin, indicadores completos do período, pausa rápida, busca de empresas e link compartilhável do cardápio.
- iFood: aplicativo centralizado de teste, segredo/token criptografados, vínculo exclusivo por loja feito pelo superadmin, consulta manual de situação e acompanhamento segregado pelo tenant. Testes com HTTP simulado.
- Recepção iFood de teste: eventos armazenados antes do ACK, deduplicação, importação segregada, recuperação de falhas e lista/detalhes no painel do tenant. O comando `ifood:sync` roda pelo scheduler a cada 30 segundos; novos recebimentos exigem ativação explícita por loja no superadmin e vêm desligados.
- Criação de empresas e usuários, lojas, produtos, disponibilidade e cardápio público.
- Checkout com preços calculados no servidor, snapshots dos itens e idempotência por loja.
- Pedidos, etapas de preparo, acompanhamento por link e registro manual auditado de recebimento.
- Módulo freelancer por empresa: ativação, encerramento seguro e preservação das corridas atribuídas.
- Cadastro global do motoboy, aprovação central, disponibilidade, check-in por GPS, fila FIFO e reserva exclusiva.
- Oferta aberta regional, aceite transacional, prevenção de atribuição incompatível entre empresas, coleta e conclusão.
- Cálculo por corrida, km ou taxa fixa; valores em centavos; extrato e confirmação de acerto externo.
- Configuração central da Evolution criptografada, instâncias segregadas, solicitação de QR e webhook protegido por segredo por conexão.
- Dockerfile, Compose com PostgreSQL, Redis, web, worker e scheduler, além de testes automatizados e CI.
- Edição de lojas e produtos, fotos locais com validação, pausa manual, horários semanais com virada de dia e fuso por loja.
- Grupos de adicionais obrigatórios/opcionais com limites, preços por unidade e preservação das escolhas na venda.
- Regiões de entrega com frete, pedido mínimo e revisão do total no servidor antes de confirmar; alterações de preço exigem nova revisão.
- Painel de pedidos com filtros, paginação, detalhes privados, cancelamento com motivo e atualização automática a cada 5 segundos com a página ativa. Som de novo pedido ativável pelo operador.
- Acompanhamento público com atualização automática e valores de cadastro informados em reais, sem cálculo financeiro em ponto flutuante no servidor.

### Limites explícitos

- Interface usa **Blade + CSS/JS** e consulta periódica de atualizações, sem Vue/Inertia ou WebSockets. Som precisa de interação do operador para ativação; abas suspensas pelo navegador não garantem recebimento de alertas.
- iFood recebe apenas pedidos explicitamente marcados como teste, em uma área separada. Não envia aceite/preparo/cancelamento/despacho, não sincroniza catálogo nem opera restaurantes reais. Credenciais e loja de teste, validação externa e homologação permanecem necessárias. Configurar em **Superadmin → Integração iFood**; informar a chave somente no painel. O rótulo teste da conexão é declarado pelo aplicativo/loja fornecidos; a importação também exige identificação de teste no pedido. ACK é confirmação de leitura, não de aceite. O piloto processa cinco eventos por ciclo e admite até 100 lojas por aplicativo; formatos ainda não suportados ficam preservados para revisão. Não habilitar recebimento de lojas reais enquanto o fluxo operacional estiver incompleto.
- O módulo é ativado por empresa nesta entrega; seleção granular de lojas, múltiplos usuários com permissões por loja, bloqueios locais de entregador e tabelas de regras editáveis ainda precisam ser desenvolvidos.
- Painel web do motoboy serve ao piloto e exige renovar presença. **Aplicativo Android, localização contínua e push ainda não estão implementados**; não lançar operação autônoma com celular bloqueado usando esse painel.
- **WABA ainda é solicitação pendente**, sem Embedded Signup ou envio oficial. A conexão QR possui testes com HTTP simulado, mas exige homologação na versão do servidor Evolution. Verificação automática do número efetivamente pareado, desconexão/migração e mensagens transacionais/campanhas estão pendentes.
- Pagamento é presencial/manual. Não há integração real com gateway, Pix dinâmico, estorno ou repasse ao freelancer.
- Quilometragem de rota e cotação por corrida são informadas pela loja; ainda não há provedor de rotas. Não usamos distância em linha reta para calcular remuneração.
- Cobertura do checkout usa regiões/bairros selecionados pelo cliente, sem conferir geograficamente o endereço. A loja deve conferir endereço e região antes do aceite. Busca de motoboy usa raio geográfico de 3 km, ampliável a 5 km; check-in usa 150 m.
- Horários admitem um intervalo por dia, inclusive virada de meia-noite; feriados e múltiplos intervalos ficam pendentes. Produtos iguais no mesmo carrinho compartilham as mesmas escolhas de adicionais; variações, linhas independentes e combos ficam pendentes.
- Mesas/comandas, estoque/ficha técnica, caixa completo, fiscal, marketplaces, CRM/fidelidade, IA, impressão e cobrança SaaS permanecem no backlog.
- Ocorrências após coleta, compensações de cancelamento e prova de entrega por código precisam de módulo próprio antes do uso comercial amplo. Nesta versão, entregador confirma conclusão e recebimento manualmente; não há autenticação do cliente na entrega.
- CI inclui testes e build de imagem; build Docker/Coolify e concorrência em PostgreSQL precisam ser confirmados no ambiente de CI/homologação. Teste sequencial de dois aceites não substitui teste concorrente com múltiplos processos.

## Desenvolvimento local

Requisitos: PHP 8.4 com SQLite, mbstring, intl, curl, fileinfo e zip; Composer 2. Para esta interface não é necessário build Node.

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan app:admin admin@suaempresa.com
php artisan serve
```

O comando solicita uma senha com pelo menos 12 caracteres. Não existem senhas padrão de produção. Crie tenants no painel `/admin`, entre como proprietário, cadastre a loja e publique produtos. Em **Configurar loja**, cadastre regiões e fretes: sem região ativa, o cardápio oferece somente retirada. Edite produtos para adicionar fotos e grupos/opções. Cadastro freelancer: `/entregadores/cadastro`.

Em outro terminal, executar `php artisan schedule:work` para processar expirações e avançar o despacho a cada dez segundos. Sem scheduler, a oferta vence, mas o próximo despacho não avança sozinho.

```sh
php artisan test --compact
node --test tests/browser/web-sales.test.cjs
php artisan view:cache
php artisan route:cache
```

## Instalação no Coolify

1. Criar recurso a partir do repositório Git e selecionar Docker Compose (`compose.yaml`).
2. Carregar o Compose no Coolify. Ele gera e persiste `SERVICE_REALBASE64_APPKEY`, `SERVICE_PASSWORD_POSTGRES` e `SERVICE_PASSWORD_REDIS`; o Compose distribui os mesmos valores entre web, worker, scheduler e banco/Redis. Os dados fixos de banco, usuário e hosts já estão definidos. Configurar os IPs/CIDRs reais do proxy em `TRUSTED_PROXIES` conforme a rede utilizada.
3. A chave Laravel é `base64:` mais os 32 bytes codificados em Base64 de `SERVICE_REALBASE64_APPKEY`. Não usar `SERVICE_BASE64_64` para isso: esse gerador tem outro formato/tamanho. Em instalação existente, preservar `APP_KEY`, `DB_PASSWORD` e `REDIS_PASSWORD` originais nas variáveis do recurso; esses overrides têm precedência e não devem ser apagados ao atualizar. Não gerar nova chave a cada deploy.
4. Associar domínio HTTPS ao serviço `web`, porta interna **8080**. Banco e Redis ficam sem portas públicas.
   `APP_URL` usa automaticamente `SERVICE_URL_WEB_8080`, que acompanha a configuração de domínio no Coolify. Um `APP_URL` configurado manualmente continua tendo precedência: atualizar/remover apenas esse override se desejar acompanhar o domínio automaticamente. É necessário configurar DNS/HTTPS ou o wildcard do servidor para ter uma URL utilizável; o Compose não cria seu domínio externo.
5. A inicialização dos containers verifica a chave e executa as migrações automaticamente antes de iniciar web, worker e scheduler. Uma trava de sessão no PostgreSQL serializa essa etapa entre os três processos; não há migrações simultâneas. Depois da primeira subida, executar no terminal do serviço web somente `php artisan app:admin seu@email.com`. Se sua infraestrutura já controla migrações separadamente, configurar `RUN_MIGRATIONS=false` e executar `php artisan migrate --force` antes de liberar os serviços.
6. Executar `php artisan optimize` quando o ambiente estiver configurado. Reiniciar workers e scheduler em atualizações de código.
7. Confirmar `/up`, acesso, criação de loja, checkout e execução de `dispatch:tick`. A rota `/up` confirma o processo web; monitoramento de banco, scheduler e filas deve ser adicional.
8. Configurar backup externo do PostgreSQL, volume `app_storage`, segredos e Evolution. Executar teste real de restauração antes de receber dados comerciais.

Web, worker e scheduler compartilham código e banco. Redis está disponível para evolução, mas esta versão usa drivers de sessão/cache/fila em banco, explicitamente configurados no Compose. Evolution pode ser servidor externo ou outro recurso Coolify; sua instalação não está embutida neste Compose. Cadastrar URL HTTPS e chave no superadmin.

Para restaurar: criar ambiente isolado com a mesma versão, restaurar PostgreSQL e arquivos, fornecer a **mesma APP_KEY**, conferir conexões externas com envios desativados e só depois direcionar o domínio. Voltar uma imagem não desfaz migrações automaticamente.

**Atualização da entrega web:** com `RUN_MIGRATIONS=true` (padrão), o container executa `php artisan migrate --force` antes de iniciar. A migração acrescenta configurações, adicionais e snapshots sem apagar pedidos existentes. Lojas existentes precisam cadastrar regiões para reabilitar entrega no cardápio; retirada permanece disponível. Revisões do checkout expiram em 15 minutos e ficam na sessão do navegador. Fotos são servidas por rota pública própria a partir do storage, sem exigir `storage:link`.

**Diagnóstico de inicialização:** o arquivo Deployment Logs confirma build e criação dos containers; a causa de um reinício aparece nos Runtime Logs do serviço afetado. As mensagens `[startup]` identificam chave inválida, domínio ausente e falha de preparação do banco, sem imprimir a chave. Se o Coolify não disponibilizar `SERVICE_URL_WEB_8080`, o Compose também aceita `SERVICE_URL_WEB`; configure `APP_URL` explicitamente quando o recurso não fornecer nenhum deles. `http://localhost` é apenas uma URL interna de contingência, não o domínio público da loja. Não altere senhas nem apague volumes para tratar erro de URL.

## Próximos marcos

1. Homologar imagem Coolify, concorrência real no PostgreSQL e instalação limpa.
   Prioridade adicionada: validar a recepção com loja iFood de teste e implementar envio confiável das ações operacionais, antes de catálogo e produção. Persistência/deduplicação iniciais concluídas; sequência e aceite em `docs/escopo.md`, seção 16.1.
2. Evoluir fluxo de ocorrências, segurança/comprovação de entrega, tabela de tarifas editável e configuração de despacho.
3. Completar o painel web do entregador, atualização de presença com página ativa, ofertas e acertos validados em duas lojas e três entregadores. Android/localização em segundo plano ficam para depois.
4. Homologar Evolution QR e implementar onboarding WABA e outbox de mensagens.
5. Implementar gateway, fiscal conforme cenário e os módulos operacionais restantes em fases.
