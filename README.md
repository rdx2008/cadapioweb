# CadapioWeb

Plataforma de restaurantes em **Laravel 13 / PHP 8.4**, com múltiplas empresas na mesma instalação e implantação pelo Coolify.

## Estado desta entrega

Esta versão é uma implementação inicial funcional para piloto assistido. O escopo completo está em `docs/escopo.md`; os recursos descritos no escopo não devem ser confundidos com recursos já implementados.

**Prioridade definida em 24/10/2026:** concluir os fluxos web primeiro. Android foi adiado e não é dependência da primeira entrega web. O backlog e o estado por módulo estão em `docs/status-web.md`.

### Implementado

- Login, logout, limitação de tentativas e perfis de superadmin, proprietário e freelancer.
- Criação de empresas e usuários, lojas, produtos, disponibilidade e cardápio público.
- Checkout com preços calculados no servidor, snapshots dos itens e idempotência por loja.
- Pedidos, etapas de preparo, acompanhamento por link e registro manual auditado de recebimento.
- Módulo freelancer por empresa: ativação, encerramento seguro e preservação das corridas atribuídas.
- Cadastro global do motoboy, aprovação central, disponibilidade, check-in por GPS, fila FIFO e reserva exclusiva.
- Oferta aberta regional, aceite transacional, prevenção de atribuição incompatível entre empresas, coleta e conclusão.
- Cálculo por corrida, km ou taxa fixa; valores em centavos; extrato e confirmação de acerto externo.
- Configuração central da Evolution criptografada, instâncias segregadas, solicitação de QR e webhook protegido por segredo por conexão.
- Dockerfile, Compose com PostgreSQL, Redis, web, worker e scheduler, além de testes automatizados e CI.

### Limites explícitos

- Interface desta entrega usa **Blade + CSS/JS**, sem Vue/Inertia ou WebSockets. Atualização operacional é manual. Isso simplifica a fundação; não implementa ainda a interface em tempo real prevista no escopo.
- O módulo é ativado por empresa nesta entrega; seleção granular de lojas, múltiplos usuários com permissões por loja, bloqueios locais de entregador e tabelas de regras editáveis ainda precisam ser desenvolvidos.
- Painel web do motoboy serve ao piloto e exige renovar presença. **Aplicativo Android, localização contínua e push ainda não estão implementados**; não lançar operação autônoma com celular bloqueado usando esse painel.
- **WABA ainda é solicitação pendente**, sem Embedded Signup ou envio oficial. A conexão QR possui testes com HTTP simulado, mas exige homologação na versão do servidor Evolution. Verificação automática do número efetivamente pareado, desconexão/migração e mensagens transacionais/campanhas estão pendentes.
- Pagamento é presencial/manual. Não há integração real com gateway, Pix dinâmico, estorno ou repasse ao freelancer.
- Quilometragem de rota e cotação por corrida são informadas pela loja; ainda não há provedor de rotas. Não usamos distância em linha reta para calcular remuneração.
- Não há ainda zonas de cobertura do checkout: loja deve confirmar atendimento antes do aceite. Busca de motoboy usa raio geográfico de 3 km, ampliável a 5 km; check-in usa 150 m.
- Mesas/comandas, adicionais/combos, estoque/ficha técnica, caixa completo, fiscal, marketplaces, CRM/fidelidade, IA, impressão e cobrança SaaS permanecem no backlog.
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

O comando solicita uma senha com pelo menos 12 caracteres. Não existem senhas padrão de produção. Crie tenants no painel `/admin`, entre como proprietário, cadastre a loja e publique produtos. Cadastro freelancer: `/entregadores/cadastro`.

Em outro terminal, executar `php artisan schedule:work` para processar expirações e avançar o despacho a cada dez segundos. Sem scheduler, a oferta vence, mas o próximo despacho não avança sozinho.

```sh
php artisan test --compact
php artisan view:cache
php artisan route:cache
```

## Instalação no Coolify

1. Criar recurso a partir do repositório Git e selecionar Docker Compose (`compose.yaml`).
2. Configurar `APP_URL`, `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD` e os IPs/CIDRs reais do proxy em `TRUSTED_PROXIES`. Use `.env.coolify.example` como referência; substitua todos os placeholders.
3. Gerar uma chave com `php artisan key:generate --show` em ambiente seguro e preservá-la. Não regenerar a chave a cada deploy: ela protege sessões/segredos da Evolution.
4. Associar domínio HTTPS ao serviço `web`, porta interna **8080**. Banco e Redis ficam sem portas públicas.
5. Após a primeira subida, executar no terminal do serviço web: `php artisan migrate --force`, depois `php artisan app:admin seu@email.com`. Registrar a migração como etapa de implantação única nas atualizações, sem executá-la em todos os containers.
6. Executar `php artisan optimize` quando o ambiente estiver configurado. Reiniciar workers e scheduler em atualizações de código.
7. Confirmar `/up`, acesso, criação de loja, checkout e execução de `dispatch:tick`. A rota `/up` confirma o processo web; monitoramento de banco, scheduler e filas deve ser adicional.
8. Configurar backup externo do PostgreSQL, volume `app_storage`, segredos e Evolution. Executar teste real de restauração antes de receber dados comerciais.

Web, worker e scheduler compartilham código e banco. Redis está disponível para evolução, mas esta versão usa drivers de sessão/cache/fila em banco, explicitamente configurados no Compose. Evolution pode ser servidor externo ou outro recurso Coolify; sua instalação não está embutida neste Compose. Cadastrar URL HTTPS e chave no superadmin.

Para restaurar: criar ambiente isolado com a mesma versão, restaurar PostgreSQL e arquivos, fornecer a **mesma APP_KEY**, conferir conexões externas com envios desativados e só depois direcionar o domínio. Voltar uma imagem não desfaz migrações automaticamente.

## Próximos marcos

1. Homologar imagem Coolify, concorrência real no PostgreSQL e instalação limpa.
2. Evoluir fluxo de ocorrências, segurança/comprovação de entrega, tabela de tarifas editável e configuração de despacho.
3. Completar o painel web do entregador, atualização de presença com página ativa, ofertas e acertos validados em duas lojas e três entregadores. Android/localização em segundo plano ficam para depois.
4. Homologar Evolution QR e implementar onboarding WABA e outbox de mensagens.
5. Implementar gateway, fiscal conforme cenário e os módulos operacionais restantes em fases.
