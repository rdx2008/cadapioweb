# Estado e prioridades da versão web

Atualizado em 24/10/2026 após o envio inicial de ações iFood de teste e a correção do botão Sair, branch `feat/platform-foundation`.

## Gestão de empresas e BI — 27/10/2026

Superadmin > Empresas > Gerenciar empresa permite editar nome/identificador e nome/e-mail do proprietário principal, suspender com motivo, reativar, excluir da operação e restaurar. Exclusão exige senha atual do superadmin e identificador exato; pedidos ou entregas em andamento impedem a exclusão. É uma exclusão lógica: histórico de pedidos, pagamentos, usuários e auditoria permanece consultável. Empresas excluídas ficam ocultas por padrão na lista e podem ser exibidas/restauradas, inicialmente suspensas.

Suspensão bloqueia login e requisições de proprietários/equipe, cardápio próprio, novas cobranças e novas ofertas de motoboy; revoga ofertas abertas e libera reservas temporárias sem afetar a conta global do entregador. Entregas já atribuídas podem terminar. Confirmações e consultas de cobranças existentes continuam conciliando dinheiro recebido. A ação não fecha a loja no marketplace iFood.

Menu BI e crescimento: /admin/bi na plataforma e /bi no tenant. Filtros por período (até 366 dias), fuso, empresa no admin e loja; comparação com período anterior de mesma duração, evolução diária, vendas pagas, produtos/frete separados, pagamentos em aberto/conferência, ticket médio, cancelamentos, clientes atendidos e recorrentes, melhores dias e horários, produtos, formas de pagamento, entrega/retirada, rankings de lojas e empresas, entrada de empresas e exportação CSV diária. Contagens ativas/suspensas representam a base atual, enquanto entradas/exclusões respeitam o período. CSV e HTML respeitam o tenant autenticado mesmo com parâmetros adulterados; não exportam dados pessoais.

Métricas de vendas usam a data de criação do pedido no fuso escolhido, pagamento confirmado e pedido não cancelado. O total inclui frete e não representa lucro líquido. Clientes atendidos são telefones válidos normalizados em pedidos concluídos; recorrência significa dois ou mais pedidos concluídos no próprio período, por empresa. Dias sem venda entram na média semanal e na série diária. Dados são do cardápio próprio; testes iFood não entram. Receita de assinaturas do SaaS permanece sem lançamentos até existir cobrança/registro desse módulo, separada do volume dos restaurantes.

Verificação final: 186 testes PHP e 13 JavaScript aprovados; Pint, compilação de templates/rotas e fluxo administrativo em Chrome aprovados. Sem transbordamento horizontal em 320/390 px. Nova migration: 2026_10_28_021156_add_tenant_lifecycle_and_bi_indexes. Validação em SQLite local e Chrome em computador/celular; PostgreSQL/Coolify e dados reais continuam sujeitos à validação no ambiente publicado. Nenhuma chamada real aos provedores ou alteração na produção foi executada.

## Configuração simplificada e PagBank em produção — 27/10/2026

Tela de pagamento online mantém apenas a introdução fornecida pelo usuário. InfinitePay pede habilitação e tag, sem texto sobre API Key. PagBank pede habilitação e token, sem seletor de ambiente nem mensagens de homologação. Orientação do token: conta PagBank pelo computador > Vendas > Integrações > Gerar Token, conforme https://developer.pagbank.com.br/docs/token-de-autenticacao. Mensagem de sucesso simplificada.

Novas configurações dos dois provedores são fixadas em production no servidor, inclusive se a requisição tentar enviar sandbox. Cardápio só oferece contas production em qualquer ambiente da aplicação. Configurações antigas de sandbox não são convertidas silenciosamente: podem ser desabilitadas sem alterar o token e exigem novo token para ativar produção. Tentativas históricas preservam seu ambiente/credencial para consulta de transações anteriores. A base do PagBank para novas cobranças é https://api.pagseguro.com; autenticação e confirmação pela API permanecem.

Validação direcionada: 19 testes de pagamento online aprovados, incluindo configuração sem seletor, endpoint/token de produção, exclusão de sandbox do cardápio e ausência dos textos removidos. Templates compilados e Pint aprovado. Sem migration adicional e sem chamada real de cobrança.

## Correção InfinitePay por InfiniteTag — 27/10/2026

O contrato adotado é o checkout externo da página https://www.infinitepay.io/checkout-documentacao. Configuração da InfinitePay exige somente habilitação e InfiniteTag sem $. Não há campo nem exigência de API Key. PagBank continua exigindo seu token. A coluna genérica credential é preservada por compatibilidade de esquema; InfinitePay grava um valor vazio criptografado ao salvar, e credenciais antigas nunca são lidas/enviadas para esse provedor, inclusive em tentativas anteriores. Nenhuma nova migration é necessária.

POST /links envia handle, items, order_nsu, redirect_url e webhook_url. Não envia cabeçalhos de autenticação, options nem o objeto webhook do outro contrato. Checkout externo aceita os hosts HTTPS checkout.infinitepay.io e buy.infinitepay.io. Consulta payment_check usa handle/referência/transaction_nsu/slug sem API Key. Webhook continua sendo somente um sinal para conferir na API, sem assumir aprovação pelo retorno do navegador ou pelo payload recebido.

Sem single_payment/valid_until neste contrato: o prazo de 30 minutos é uma regra local para confirmação automática e não uma promessa de expiração do link externo. Pagamento posterior fica para conferência; a tela informa essa diferença. A documentação desse fluxo descreve notificação de aprovação, sem garantia de eventos de estorno; a loja deve acompanhar estornos no provedor. PagBank e suas credenciais permanecem independentes.

Validação da correção: 175 testes PHP e 13 JavaScript aprovados; 18 casos de pagamento online incluem ausência de cabeçalhos de autenticação na InfinitePay, configuração só por tag, compatibilidade com configuração antiga e preservação do token PagBank. Chrome em 1440/390/320 px: tag salva sem chave e campo de token restrito ao PagBank. Nenhuma transação real foi enviada.

## Checkout online InfinitePay e PagSeguro/PagBank — 27/10/2026

Integração inicial implementada por checkout hospedado, com contas independentes por loja. Em Lojas > Editar > Pagamento online: InfinitePay usa somente InfiniteTag, sem API Key, conforme a correção acima; PagBank usa token e ambiente Sandbox/Produção. Sandbox não aparece no cardápio quando APP_ENV é produção. Credenciais criptografadas, nunca renderizadas/retornadas e excluídas de old input. Troca de ambiente exige nova credencial. Tentativas existentes preservam credencial/ambiente/recebedor originais. Não há split, intermediação ou cobrança da assinatura SaaS neste fluxo.

Cliente escolhe o provedor, revisa e confirma. Servidor grava pedido, itens e reserva de pagamento atomicamente; a chamada externa ocorre depois da transação. Uma tentativa por pedido, referência aleatória e valor fechado incluindo frete. Estado creating é persistido antes do POST externo; cliques repetidos usam o mesmo link e timeout/retorno inválido fica uncertain, sem recriação automática. Links HTTPS com domínio permitido; callbacks derivados do APP_URL configurado, sem confiar no Host do navegador. Expiração de 30 minutos ou início de preparo do pedido agendado, o que ocorrer antes; agendamentos precisam permitir pelo menos 10 minutos para pagar. Retorno do navegador nunca confirma pagamento. InfinitePay usa handle/items/redirect_url/webhook_url, com prazo local de confirmação; PagBank recebe expiration_date e é inativado após confirmação por API, com aviso/reconsulta se a inativação falhar.

Webhooks InfinitePay usam URL com segredo específico da conta e guardam somente identificadores limitados, criptografados; status recebido não é autoridade financeira. PagBank valida o corpo original com x-payload-signature/ECDSA e chave pública da API, ou x-authenticity-token/SHA-256 do contrato legado. Chave pública em cache; política/endpoint de produção precisam de piloto, pois há coexistência de contratos na documentação oficial. Após autenticação, os callbacks só solicitam conferência. InfinitePay é consultada em payment_check com referência, slug, transaction_nsu e conta originais; PagBank consulta o Order identificado na notificação assinada, com token original. Identidade, moeda quando explícita, valor e captura precisam corresponder. Transação de um pedido não quita outro. Não são armazenados CPF, cartão, payload bruto, recibo externo nem resposta bruta do provedor.

payments:sync agendado a cada 30 segundos em background, até oito tentativas por execução. Consultas pendentes espaçadas em dois minutos; notificações podem antecipar para 30 segundos. Pagos são reconferidos em seis horas, ou após notificação; expirados saem da consulta periódica e notificação autenticada pode reativar conferência sem recriar link. Confirmado só altera pagamento, não aceita/prepara/despacha automaticamente. Aceite, preparo, despacho e etapas do motoboy exigem confirmação online válida; botão de recebimento manual não confirma pagamento online. Cartão/Pix online não geram numerário do motoboy.

Pagamento após prazo/cancelamento, estorno, referência/valor/moeda divergentes e transação conflitante ficam em review, bloqueando atendimento. Cancelar pedido não estorna pagamento. Estornos, conciliação de taxas e resolução assistida de review/uncertain continuam manuais no painel do provedor e exigem evolução antes de operação comercial sem acompanhamento. A aplicação não cria uma nova cobrança para tentar resolver resultado incerto. PagBank depende de notificação assinada com Order ID para consultar a transação; falta de notificação/credencial revogada permanece pendente, sem confirmação presumida.

Validação: 173 testes PHP e 13 JavaScript, com APIs simuladas e sem movimentação financeira real. Casos novos cobrem assinatura ECDSA/legada, corpo alterado, criação única/timeout, replays, isolamento/criptografia, rotação, moeda/valor/referência, estorno e pagamento tardio, sandbox em produção, agendamento e bloqueios operacionais. Chrome em 1440/390/320 px: cadastro de configuração fictícia, credenciais ocultas, escolha online e revisão. Contas fictícias da prévia foram desativadas, sem chamar provedores. Migration aditiva aplicada apenas em SQLite de prévia; concorrência PostgreSQL, contratos reais, homologação e estorno operacional dependem de piloto.

Deploy: executar migrate --force, manter APP_KEY estável, APP_URL público HTTPS e scheduler ativo. PagBank limita a URL de notificação a 100 caracteres (APP_URL + /wp/p). Usar credenciais da própria loja; não enviar pelo chat. Validar criação, pagamento, notificação, retorno, expiração e estorno autorizado em cada provedor antes de habilitar vendas reais.

Referências verificadas: [InfinitePay Checkout Integrado](https://ajuda.infinitepay.io/pt-BR/articles/10766888-como-usar-o-checkout-integrado-da-infinitepay), [Checkout PagBank](https://developer.pagbank.com.br/docs/checkout), [Webhooks PagBank](https://developer.pagbank.com.br/reference/webhooks-checkout), [Autenticidade ECDSA](https://developer.pagbank.com.br/reference/validacao-de-autenticidade), [Autenticidade legada](https://developer.pagbank.com.br/reference/confirmar-autenticidade-da-notificacao).

## Pagamento na entrega/retirada e troco — 27/10/2026

Loja configura Dinheiro, Cartão e Pix em Lojas > Editar > Formas de pagamento. Pelo menos uma forma deve permanecer ativa. Migration preserva dinheiro como padrão, cartão e Pix desativados. Escolha do cliente é validada novamente na confirmação e gravada no pedido. Troco opcional só para dinheiro, em centavos, cobrindo produtos mais frete. Não altera total da venda nem dinheiro líquido a prestar contas. Campo fica oculto/desabilitado para Pix/cartão; revisão e retorno à edição preservam a informação. Informações aparecem ao cliente, proprietário, operação e motoboy; perfil Cozinha não recebe informações financeiras adicionais.

São pagamentos presenciais, sem gateway, QR Pix, chave Pix, tokenização de cartão ou confirmação por webhook. Loja deve providenciar maquininha na entrega e confirmar manualmente Pix/cartão após conferir recebimento. Escolher forma não marca pedido como pago. Motoboy só conclui entrega com Pix/cartão depois dessa confirmação, que pode ser feita pela loja durante corrida ativa. Numerário da corrida inclui apenas dinheiro ainda a receber; Pix/cartão têm cash_cents zero e não viram dívida do motoboy. Dinheiro mantém confirmação de recebimento pelo entregador e restrição de alteração pela loja durante a corrida. Ofertas identificam necessidade de cartão/maquininha; troco e dados do cliente aparecem após aceite. Pedidos históricos e corridas existentes mantêm valores anteriores.

Validação: 157 testes PHP e 12 JavaScript aprovados. Sete novos casos cobrem troco/frete, métodos inválidos/desativados, alteração entre revisão e confirmação, snapshot/idempotência, configurações/isolamento e ciclo de entrega Pix/cartão sem numerário. Chrome em 1440/390/320 px: configuração persistida, três métodos, campo condicional, revisão e retorno à edição, troca de dinheiro para Pix sem envio de troco antigo. Migration aplicada somente na prévia SQLite; deploy e piloto PostgreSQL/Coolify pendentes.

## Pedidos agendados — 27/10/2026

Configuração por loja, desligada por padrão, com antecedência mínima de 15 a 1440 minutos, preparo de 15 a 240 minutos e horizonte de 1 a 7 dias. Antecedência não pode ser menor que o preparo. Para entrega, a loja deve incluir margem de deslocamento. Cliente seleciona horário desejado de entrega/retirada em intervalos de 15 minutos, no fuso da loja; somente horários válidos aparecem. Loja fora do expediente atual pode receber agendamentos futuros, mas pausa manual bloqueia ambos os fluxos. Todo o intervalo de preparo precisa caber no atendimento, respeitando intervalos, horários especiais e passagem da meia-noite.

Checkout recalcula elegibilidade na revisão e confirmação; horário vencido, pausa ou mudança da configuração/valores exige revisão. Instantes de atendimento e início do preparo são gravados em UTC com fuso original. Confirmações repetidas não duplicam pedido. Revisão, acompanhamento público, painel, detalhes e operação/cozinha destacam agendamento e início previsto. Aceite antecipado permitido; preparo antecipado bloqueado no servidor e nos botões, liberados pela atualização do painel. Cancelamento continua permitido. Sem aceite/preparo/despacho automático, reserva de capacidade, reagendamento ou garantia de pontualidade. Catálogo, cobertura e preços validados no momento de contratar, sem nova geocodificação no dia do atendimento.

Validação: 150 testes PHP e 12 JavaScript aprovados. Seis novos testes cobrem horários inválidos, loja fechada/pausada, fuso/UTC, virada de dia, interrupção no preparo, mudanças após revisão, idempotência e liberação do preparo. Chrome: configuração persistida, lista de horários e ausência de rolagem horizontal em 1440/390/320 px. Migration aplicada somente na prévia SQLite; deploy Coolify/PostgreSQL pendente.

## Combos — 27/10/2026

Tipo Combo disponível no cadastro e editor, com preço base fixo e grupos de componentes da mesma loja. Cada opção referencia produto comum, com tamanho fixo quando houver variações, quantidade e acréscimo por unidade do componente. Preço avulso do componente não entra no cálculo. Até 10 grupos, 20 opções por grupo e 10 escolhas por grupo; pelo menos um grupo deve ser obrigatório.

Cardápio e checkout verificam componentes/tamanhos ativos e limites de escolha. Grupos obrigatórios sem opções suficientes retiram o combo da vitrine. Cada linha da sacola mantém suas próprias escolhas; servidor recalcula preços, exige nova revisão se houver alteração e grava componentes no histórico do pedido. Configurações isoladas por tenant/loja, com auditoria e bloqueio da loja durante alterações. Produtos com adicionais obrigatórios, pizzas e outros combos não podem ser componentes nesta etapa; não há montagem interna do componente nem baixa de estoque.

Validação: 144 testes PHP e 12 JavaScript aprovados, incluindo sete casos de combos e isolamento de escolhas da sacola. Templates e rotas compilados. Migração aditiva cria combo_groups e combo_options; executar migrate --force no deploy. Publicação Coolify e validação visual em produção pendentes.

## Tipo de produto e configurações específicas — 27/10/2026

Cadastro/edição incluem Tipo de produto: Produto comum ou Pizza. Combos foram acrescentados na etapa seguinte descrita acima. Editor comum mostra dados, preços/variações e adicionais; sabores, bordas e máximo de sabores ficam exclusivos do editor Pizza. Alterar o seletor requer salvar para carregar as seções do novo tipo. Listagem identifica o tipo.

Migration adiciona product_type com padrão common e identifica como pizza os produtos já habilitados no modo pizza, com sabores cadastrados ou grupo do tipo border. Não altera disponibilidade nem preços na migração. Novos endpoints de sabores/regras e cadastro de grupos Borda recusam produtos comuns no servidor.

Mudança de tipo desliga pizza_enabled e pausa produto para revisão, em transação e com auditoria. Sabores e bordas antigos permanecem armazenados. Em produto comum, bordas preservadas não aparecem no editor/cardápio e não são exigidas/cobradas no checkout. Voltar ao tipo Pizza mostra os dados preservados, sem reativar automaticamente venda ou montagem de sabores. Produto comum continua podendo ter variações de tamanho e adicionais normais.

Validação: 137 testes PHP e 11 JavaScript; quatro novos casos cobrem exibição, requisições forjadas, criação/troca de tipo, pausa, preservação, checkout e isolamento. Chrome: migração de pizza já configurada, editor comum sem seções de pizza, mudança entre tipos e telas 390/320 px. Migration aplicada somente em banco local de prévia; publicação e piloto PostgreSQL/Coolify pendentes.

## Pizzas com sabores e bordas — 27/10/2026

Editor permite ativar modo pizza após habilitar tamanhos e cadastrar sabores com preço em tamanho disponível. Cada tamanho aceita de 1 a 4 sabores distintos, em partes iguais. Regra por produto: maior preço entre sabores ou média aritmética com arredondamento em centavos. Preços são da pizza inteira de cada sabor/tamanho e substituem o preço padrão da variação neste modo. Campo vazio deixa sabor indisponível naquele tamanho; sabor pode ser pausado. Tamanhos sem qualquer sabor ativo/preço não aparecem no cardápio, e produto sem tamanho vendável sai da vitrine.

Sabores próprios por produto, até 100, com descrição e preços por tamanho. Endpoints verificam tenant/produto e IDs de tamanhos, travam a loja e auditam alterações. Migration aditiva cria pizza_flavors e adiciona campos de modo/regra, limite por tamanho e tipo de grupo de adicionais. Produtos existentes permanecem fora do modo pizza. Não permite desligar tamanhos enquanto modo pizza estiver ativo.

Tipo Borda em grupos de adicionais: um grupo de borda por produto, no máximo uma escolha; mínimo 0 opcional ou 1 obrigatório. Preço fixo da opção soma uma vez por pizza, antes de multiplicar pela quantidade. Sem borda pode ser opção de R$0. Não há preço de borda diferente por tamanho nesta etapa. Grupos existentes continuam como adicionais, sem conversão automática.

Cardápio mostra sabores compatíveis, limites e regra de cálculo; trocar tamanho desmarca sabores incompatíveis. Sacola, edição/retorno da revisão e pedido preservam tamanhos, sabores/frações e borda. Servidor rejeita sabores de outro produto, repetidos, pausados, sem preço e acima do limite; mudança de preço/regra/disponibilidade após revisão bloqueia confirmação. Snapshot mantém as escolhas na cozinha e no acompanhamento sem alterar pedidos antigos. Continua suportando várias combinações do mesmo produto.

Validação: 133 testes PHP e 11 JavaScript. Sete cenários novos de backend cobrem maior preço, média/arredondamento, combinações/tamanhos independentes, isolamento, indisponibilidade, configuração/bordas e snapshot na cozinha. Chrome com cadastro completo por UI: tamanhos, dois sabores, média e borda; duas pizzas de base R$60 + borda R$8 totalizam R$136. Testados retorno da revisão, confirmação e telas 390/320 px. Frações livres por fatias, sabores com partes desiguais, combos e piloto real PostgreSQL/Coolify continuam pendentes.

## Várias combinações do mesmo produto — 27/10/2026

Sacola permite adicionar o mesmo produto em linhas independentes, com tamanho, adicionais e quantidade próprios. Cada linha mostra as escolhas e permite editar/remover. Abrir diálogo prepara uma nova combinação; fechar ou cancelar não altera a sacola. Salvar item substitui somente a linha em edição. Limites: até 50 itens e 50 unidades somadas do mesmo produto. Itens iguais podem permanecer em linhas separadas.

Novo formato cart_lines é validado por linha no servidor; não aceita formato misturado nem preço por linha enviado pelo cliente. Produto deve pertencer à loja/tenant, tamanho ao produto e adicionais aos grupos corretos, respeitando limites de escolha. Revisão e confirmação recalculam todas as combinações e cobram um único frete para o pedido. Snapshots de itens preservam nomes, tamanhos, adicionais, quantidades e valores. Idempotência permanece por pedido. Formato anterior e revisões já abertas continuam compatíveis.

Edição do carrinho restaura todas as combinações a partir da revisão. Disponibilidade ou preço alterado exige nova revisão; uma linha inválida bloqueia o pedido inteiro. Quantidade no diálogo é rascunho e só entra no pedido ao adicionar/salvar, evitando alterações acidentais ao cancelar. Não inclui ainda pizzas de múltiplos sabores, combos ou observações por item.

Validação: 126 testes PHP e dez JavaScript, incluindo cinco casos novos de checkout e dois de estado da sacola. Chrome desktop e celular 390/320 px: dois tamanhos no mesmo pedido, edição, cancelamento de edição, remoção, retorno da revisão e confirmação final com duas linhas. Sem migration nesta etapa; publicação e piloto PostgreSQL/Coolify continuam pendentes.

## Gerenciador de cardápio — 27/10/2026

Nova área /catalog substitui o cadastro simples embutido no dashboard. Menu e atalhos apontam para o gerenciador; o painel antigo mantém um link de compatibilidade. Proprietário escolhe loja, pesquisa por nome e filtra por categoria, habilitados/pausados/destaques; lista paginada de 24 produtos mostra foto, descrição, preço único ou mínimo ativo, quantidade de grupos e pausa rápida. Estados vazios distinguem loja ausente, primeiro produto e filtro sem resultado.

Categorias próprias por loja permitem criar, renomear e definir ordem. Migration preserva os nomes existentes e vincula produtos, com chave estrangeira composta categoria/loja. Renomear atualiza apenas produtos vinculados da mesma loja, sem alterar snapshots de pedidos. Produto tem ordem dentro da categoria e destaque para a vitrine pública. Cardápio respeita a ordem de categorias/produtos; vitrine prioriza os destaques habilitados, com fallback para produtos do cardápio quando nenhum foi marcado.

Cadastro guiado: foto com prévia, categoria existente ou nova, descrição, disponibilidade, destaque, ordem, preço único ou até 20 tamanhos já na criação. Novo formulário começa pausado; após salvar, abre editor com navegação visível entre dados/foto, tamanhos/preços e adicionais/revisão. Adicionais são configurados no editor após o primeiro salvamento; não há salvamento único de todos os grupos no cadastro inicial. Dados, tamanhos e foto são criados de forma transacional e falha remove arquivo órfão. Edição segue com salvamento por seção. Produtos antigos e endpoint de cadastro anterior permanecem compatíveis.

Validação: 121 testes PHP e oito JavaScript. Seis novos casos cobrem loja vazia, filtros/paginação, isolamento, categorias, cadastro com foto e tamanhos, rollback e ordem/vitrine públicas. Chrome desktop 1440 px e celular 390/320 px: categoria, cadastro de tamanhos, rascunho pausado, publicação, busca e pausa rápida. Migração aplicada somente em banco local de prévia com produtos existentes; PostgreSQL/Coolify e piloto real pendentes. Combos, vários sabores e múltiplas combinações do mesmo produto por pedido continuam pendentes.

## Tamanhos e variações — 27/10/2026

Produto pode usar preço único ou preço por variação. Proprietário cadastra/edita/pausa até 20 variações com nome e preço completo; ativação exige uma disponível. Gestão verifica tenant e produto e trava a loja, com auditoria. Migration aditiva cria product_variants e products.uses_variants; produtos existentes permanecem com preço único. Preço base anterior fica preservado para eventual retorno ao modo único.

Cardápio mostra o menor preço disponível como A partir de e permite selecionar tamanho no diálogo. A sacola usa o preço da variação mais adicionais por unidade. Uma combinação por produto por pedido, conforme estrutura atual do carrinho; múltiplos tamanhos do mesmo produto no mesmo pedido, combos e pizzas de vários sabores seguem pendentes. Sem variação disponível, o produto sai do cardápio nesse modo. Pausar produto continua bloqueando todas as variações.

Revisão e confirmação buscam variação no produto/loja corretos e rejeitam ausência, pausa, modo alterado ou alteração de preço após revisão. Nome/preço da variação são preservados no snapshot do pedido e nas telas existentes de produção e acompanhamento. Preço informado pelo navegador não é aceito como autoridade. Pedidos antigos e idempotência permanecem preservados.

Validação: 115 testes PHP e oito JavaScript; cinco testes novos cobrem isolamento, adulteração, preço com adicionais, indisponibilidade, alteração entre revisão/confirmação, snapshot e restauração da seleção. Chrome em 390/320 px: proprietário cadastra Pequena/Grande, ativa modo; cliente escolhe Grande de R$45, quantidade dois e revisa R$90. PostgreSQL/Coolify e piloto real pendentes. Ifood permanece sem alteração.

## Presença, fila e ofertas web do motoboy — 26/10/2026

Painel do motoboy agora renova GPS a cada 30 segundos com a página visível e atualiza fila/ofertas/corridas a cada cinco segundos. Check-in continua exigindo até 150 m da loja e precisão de até 50 m. Heartbeat exige amostra de até 60 segundos, preserva horário de entrada e loja escolhida, e não reativa presença offline/vencida. Saída do raio ou módulo desativado suspendem a fila; nova entrada exige confirmação. Oferta exclusiva expirada não pode ser reativada pela renovação automática. Scheduler dispatch:tick continua necessário para reconciliar ofertas expiradas.

Posição FIFO mostra somente posição/total, sem nomes ou dados de outros entregadores. Desconsidera presenças vencidas, bloqueados, localizações fora do raio e reservas/corridas vigentes. Ofertas incluem contagem regressiva pelo horário do servidor, aviso visual e som liberado por clique; expiração desabilita ações na tela e o servidor continua decidindo o aceite. Feed mantém apenas ofertas e corridas do usuário autenticado; destino e contato do cliente não aparecem antes do aceite. Rotas novas são privadas, limitadas por frequência e retornam no-store. Desconexão mantém o último quadro com aviso, sem confirmar ações. Revogação de sessão limpa o quadro.

Em corrida atribuída, GPS pode atualizar o último ponto sem marcar disponibilidade, recriar check-in ou cancelar corrida, inclusive durante encerramento do módulo. Não inclui mapa, histórico de trajeto, rastreamento com navegador fechado ou entrega garantida de notificação. Permissão negada, precisão ruim, página oculta e falha de rede aparecem no painel. A presença expira após 90 segundos sem renovação válida; voltar à página depois disso exige confirmação.

Validação: 110 testes PHP e oito JavaScript, incluindo sete cenários novos de backend e três de navegador simulado. Chrome com GPS simulado: check-in, posição, renovação real após 30 segundos, saída offline e telas 1440/390/320 px. Nenhuma API iFood alterada. Concorrência PostgreSQL e piloto em dispositivos reais permanecem pendentes.

## Feriados e horários especiais — 26/10/2026

Configurar loja permite cadastrar/substituir uma data de fechamento ou abertura em até dois períodos e remover a exceção para restaurar o padrão semanal. Cada loja armazena suas datas; gestão exclusiva do proprietário, isolamento por tenant, trava da loja e auditoria nas alterações. Migration aditiva inclui special_hours, sem alterar horários existentes. Feriados não são importados automaticamente; máximo de 366 exceções cadastradas por loja.

A exceção usa a data no fuso da loja, prevalece sobre a semana e o modo manual de horários; pausa manual continua bloqueando atendimento. Uma data especial interrompe à meia-noite o período anterior. Período especial pode atravessar a madrugada, salvo outra exceção na data seguinte. Cardápio exibe o horário especial; revisão e confirmação do checkout revalidam disponibilidade, inclusive quando fecha entre as duas etapas. Pedidos existentes continuam na operação.

Validação: suíte de 103 testes PHP e cinco JavaScript; teste direcionado de cinco casos/64 verificações após acrescentar fechamento entre revisão e confirmação. Chrome em desktop e 390/320 px, cadastro de fechamento, dois períodos com madrugada e remoção. Testes locais SQLite; publicação e validação PostgreSQL/Coolify pendentes.

## Equipe por loja — 26/10/2026

Proprietário cria acessos em Equipe e acessos com e-mail, senha inicial de 12 caracteres, perfil Operação/Cozinha e lojas permitidas. Operação vê atendimento/valores e pode aceitar, preparar, concluir, cancelar com motivo e registrar recebimento manual nas lojas liberadas. Cozinha recebe somente itens/adicionais, modalidade e situação; não recebe telefone, endereço ou valores, e só pode iniciar preparo e marcar pronto. Configurações, catálogo, equipe, relatórios do proprietário, despacho/acerto e integrações iFood/WhatsApp permanecem exclusivos do proprietário. Perfis personalizados e gerência delegada não estão incluídos nesta etapa.

Painel /operation e atualização a cada cinco segundos são separados do painel do proprietário. Leituras, detalhes, filtros e alterações verificam tenant e vínculo à loja no servidor; ações revalidam perfil, bloqueio e lojas com trava no usuário. Alterar acesso encerra sessões em banco e renova remember_token; bloqueados não entram novamente. Tela limpa a fila ao receber revogação/expiração. Proprietário não pode converter/bloquear sua própria conta por essas rotas, nem gerenciar pessoas/lojas de outra empresa. Usuários e permissões são auditados sem senha. Migration aditiva mantém proprietários/admins/entregadores ativos e adiciona vínculo com chaves estrangeiras compostas para consistência por tenant.

Validação: 98 testes PHP e cinco JavaScript; Chrome em 1280/390/320 px com criação real dos perfis em banco isolado, navegação restrita, privacidade da cozinha e revogação de sessão em banco. Concorrência PostgreSQL e piloto em lojas reais permanecem necessários. Recuperação de senha também cobre perfil staff. Não há convite automático por e-mail; senha inicial deve ser compartilhada privadamente.

## Recuperação de senha — 26/10/2026

Implementados link no login, solicitação neutra por e-mail, mensagem em português e formulário de redefinição. Broker Laravel utiliza token hasheado, expiração de 60 minutos, limitação de solicitação e consumo único. Reset serializa o acesso ao token em transação, troca senha e remember_token, encerra sessões do usuário em banco e registra auditoria sem senha/token. Outras contas e sessões permanecem preservadas. URL de e-mail utiliza APP_URL confiável; formulário usa no-store/no-referrer e dados sensíveis não são gravados no old input.

Envio real depende de SMTP no Coolify. Drivers de log/array mantêm recuperação indisponível e não geram links no log. Testes usam notificações simuladas; ainda é necessário testar recebimento real. Validação: 92 testes PHP, quatro JavaScript e Chrome em 1280/390/320 px. Recuperação concluída no código; equipe e permissões por loja permanecem como próxima entrega.

## CEP primeiro e preenchimento automático — 25/10/2026

Fluxo atual do cliente: escolher entrega → informar CEP obrigatório e buscar endereço → revelar rua/bairro/cidade/UF preenchidos → informar número e complemento → consultar e confirmar frete → revisar pedido. ViaCEP no servidor preenche dados postais, com cache por sete dias, timeout e limite de requisições. Não exige nova chave. CEP genérico libera campos faltantes; erro/inexistência oferece nova tentativa ou preenchimento manual com CEP no formato válido. CEP alterado limpa endereço, número, complemento e comprovante do frete; respostas antigas são ignoradas. Retirada não exige endereço. Campos antigos são restaurados na edição do carrinho.

ViaCEP não fornece localização validada nem aprova cobertura. Geoapify continua localizando endereço com número antes de calcular frete; nenhum centroide de CEP vira posição da casa. Não há envio de nome/telefone à busca postal, e complemento descritivo do provedor não é copiado para o apartamento do cliente. Validado com 88 testes PHP, quatro JavaScript e Chrome em 1280/390/320 px: busca completa, genérica, erro, preenchimento manual, troca durante requisição, invalidação de frete e retirada. Integrações externas nos testes são simuladas. Esta seção substitui o fluxo anterior de CEP opcional.

## Histórico: endereço do cliente em campos separados — 25/10/2026

Checkout passa a pedir rua, número, bairro, cidade, UF e CEP opcional, com complemento separado. Consulta Geoapify usa parâmetros estruturados de rua/número/cidade/estado e envia CEP somente quando informado. Servidor monta o endereço canônico na consulta e revisão, preserva campos na edição e invalida comprovante após alterações. Bairro informado não determina a tarifa: permanece necessário o bairro identificado pelo geocodificador. Número/localização precisos continuam obrigatórios; CEP não é exigido, mas cobertura real do provedor pode variar. Nenhum fallback aprova coordenadas aproximadas como número exato. Validação: 86 testes PHP, quatro JavaScript; Chrome em desktop e celular com consulta simulada sem CEP e retirada sem exigir endereço. Teste real com endereços locais segue necessário.

## Cálculo pelo endereço em todos os modos — 25/10/2026

Configuração de entrega separada da remuneração do motoboy. A loja escolhe bairros/regiões ou raio; somente os campos relevantes aparecem. Por bairro, geocodificação fornece bairro, cidade e UF para correspondência exata normalizada (acentos/caixa/espaços). Mesmo bairro em outra cidade não recebe tarifa, dados faltantes não aprovam entrega e cadastros ambíguos são recusados. Bairros legados são preservados, mas precisam de cidade e UF preenchidas. Não há mais seleção manual de região no cardápio público.

Por raio: frete fixo dentro do alcance máximo; faixas com menor limite que cobre o ponto; ou base + valor proporcional por quilômetro, com mínimo e alcance máximo. Distância em linha reta, arredondamento monetário para centavos. Fixo/quilômetro dispensam cadastro de bairros/faixas. API de localização é usada nos dois modos; comprovante assinado é conferido novamente na revisão/confirmação. Valores e região enviados pelo navegador não decidem o frete. Falha de geocodificação continua bloqueando a entrega automática, mantendo retirada.

Migration aditiva preserva bairros, faixas e configurações anteriores; modo legado deriva da opção de raio até a loja salvar a nova tela. Cadastro de bairros sem cidade/UF passa a exigir complementação na tela. Validado com 84 testes PHP, quatro testes JavaScript e Chrome em 1280/390/320 px: troca entre os modos, persistência, campos ocultos/desabilitados, frete do cliente sem alterar remuneração do motoboy e checkout sem seleção de bairro. API real e PostgreSQL local ainda dependem do piloto. Esta seção prevalece sobre descrições históricas de modo manual abaixo.

## Histórico: cardápio público e cobertura por raio

Cardápio público redesenhado em layout próprio, sem menu administrativo: capa e logo enviados pela loja, categorias com navegação, busca, seleção de produtos em diálogo, adicionais, sacola responsiva e revisão antes da confirmação. Cliente vê somente o frete com ícone animado de motoboy; animação respeita movimento reduzido. Configurações internas identificam explicitamente a remuneração do motoboy freelancer.

Cobertura automática opcional por faixas de raio: geocodificação Geoapify no servidor, confirmação do endereço encontrado, menor faixa ativa que cobre o ponto e nova validação antes de criar pedido. Coordenadas e frete enviados pelo navegador não são autoridade. Comprovantes criptografados vinculam loja, endereço e validade de 30 minutos; edição do endereço invalida a seleção no navegador. Consulta fora da área, resultado sem precisão de número, falha ou ausência de chave não aprovam entrega. Raio usa distância geográfica em linha reta; não é distância de rota nem altera remuneração do motoboy. Regiões antigas permanecem no modo manual até ativação explícita.

Configurar GEOAPIFY_API_KEY no Coolify e fazer redeploy. GEOCODING_DAILY_LIMIT tem padrão 2800 para reservar margem no plano gratuito; cache criptografado por endereço por 24 horas e limite global de quatro consultas por segundo reduzem consumo. Limites do provedor são compartilhados pela conta e devem ser acompanhados no painel Geoapify. Não há chave real cadastrada no desenvolvimento; testes de API são simulados e a cobertura brasileira precisa de piloto real. Atribuição Geoapify/OpenStreetMap aparece nas consultas. Nunca usar nome ou telefone na busca de endereço; complemento é armazenado no pedido sem envio ao geocodificador.

Na loja: localizar endereço, escolher o ponto encontrado, salvar, cadastrar faixas (ex.: 3 km/R$ 5 e 5 km/R$ 8) e escolher “Raio automático”. Capa e logo ficam no armazenamento existente por tenant. Migration acrescenta campos, sem apagar dados. Validação: 80 testes PHP, quatro testes JavaScript, Chrome em 1440/390/320 px, fluxo real de retirada e confirmação, consulta de entrega simulada no navegador, invalidação de endereço alterado e movimento reduzido. Sem homologação real do provedor ou PostgreSQL local.

## Horários com almoço e jantar

Configuração da loja permite um segundo período opcional por dia. O checkout respeita o fechamento entre períodos, o fuso da loja, a passagem da meia-noite e a pausa manual. Horários antigos seguem compatíveis, sem nova migration. Painel mostra os dois períodos. Validação: 72 testes PHP e quatro testes JavaScript; Chrome em desktop e celular.

## iFood: solicitações operacionais de teste e saída da conta

Implementada a próxima etapa: o proprietário solicita aceite, início de preparo, pronto, despacho de entrega própria e cancelamento nos detalhes privados do pedido de teste. Cada comando é persistido antes de sair do servidor, com chave idempotente, revisão das credenciais e bloqueio de outra ação enquanto houver resultado pendente/incerto. O status do pedido continua sendo alterado somente pelos eventos do iFood. Preparação FOOD reconhece PRS, além do mapeamento anterior SPS; confirmações CFM/RTP/DSP/CAN reconciliam os comandos aplicáveis.

Em **Superadmin → Integração iFood**, habilitar separadamente **ações de teste** em uma conexão verificada com recebimento ativo. A migração vem com ações desligadas para todas as lojas. Trocar credenciais ou desligar o recebimento também desliga novos envios. Comandos já enviados permanecem no histórico aguardando eventos. O comando `php artisan ifood:send-commands` é agendado a cada dez segundos em segundo plano, com até três solicitações por ciclo. Antes de enviar, consulta novamente a identidade do pedido, identificação de teste e modalidade logística na API. Despacho só é enviado quando a API confirma DELIVERY/MERCHANT; entrega iFood não gera despacho próprio nem corrida freelancer.

Cancelamento usa somente motivos obtidos no endpoint do próprio pedido, com validade local de cinco minutos e nova validação antes do envio. Pedido agendado aguarda o horário de preparo; aceite vencido é bloqueado localmente. Alterações OPA/DAR e negociações HSD preservadas nos eventos impedem ações sobre contexto ainda não tratado. Nenhum valor é lançado como recebido e nenhum status é antecipado em função de um clique ou `202`.

Estados do comando: aguardando envio, verificando, enviando, enviado/aguardando evento, confirmado, recusado/não enviado, resultado incerto e encerrado sem reenvio. `429` respeita atraso e admite até três tentativas; falha antes do envio não é confundida com resultado confirmado. Timeout durante POST, respostas ambíguas/`5xx` e reinício depois de marcar o envio não são reenviados automaticamente. Sem evento por cinco minutos, o resultado fica incerto. CARF sem correlação inequívoca também conserva incerteza: não assume que o pedido foi cancelado nem libera outra ação automaticamente. Resolução assistida dessas situações é trabalho pendente antes de produção. Eventos terminais não podem ser sobrescritos por uma resposta HTTP tardia.

Os detalhes atualizam situação e histórico de ações pelo navegador sem sobrescrever um formulário em foco ou cancelamento aberto. Testes simulados cobrem o ciclo completo, clique repetido, isolamento, retorno por eventos, motivo inválido, limite de API, timeout, erro de servidor, rotação de credenciais, agendamento, logística e retorno terminal durante o envio. Chamadas seguem a referência atual de endpoints Order com POST; homologação real deve confirmar os contratos, pois parte dos guias narrativos ainda mostra verbos divergentes para pronto/despacho. Ainda faltam negociações completas, códigos de coleta/entrega, alterações de composição/endereço, conciliação, catálogo e homologação real.

Botão **Sair da conta** movido para o cabeçalho autenticado, com contraste próprio e versão compacta para celular; não depende mais do rodapé do menu. O menu lateral pode rolar em telas baixas. CSS/JS receberam nova versão de URL para invalidar o cache do visual anterior. A saída preserva o POST com CSRF e invalida a sessão.

Referência dos contratos: [Order endpoints](https://developer.ifood.com.br/en-US/docs/food/guides/modules/order/endpoints).

## iFood: recepção de pedidos de teste

Implementada a segunda etapa inicial do conector: busca de eventos no endpoint oficial Events, filtro obrigatório de Merchants vinculados, persistência criptografada antes do ACK, deduplicação por aplicativo/ID do evento e importação exclusiva por aplicativo/ID do pedido. O ACK confirma armazenamento/leitura; não aceita o pedido. Eventos repetidos podem receber novo ACK sem recriar a venda. Eventos de Merchant desconhecido e conflitos de identidade não são confirmados.

O comando `php artisan ifood:sync` é agendado a cada 30 segundos em segundo plano para não bloquear o despacho freelancer. Um bloqueio compartilhado impede sincronizações simultâneas; uma reserva no banco garante intervalo mínimo de 30 segundos por aplicativo. Reinícios deixam eventos persistidos para novas tentativas; após encerramento abrupto, o bloqueio pode levar até dez minutos para expirar. O scheduler precisa estar ativo. Novos recebimentos vêm desligados e exigem ativação explícita do superadmin em uma loja de teste verificada. Trocar credenciais desliga os recebimentos e invalida a verificação.

Pedidos recebidos aparecem na área **Integração iFood** do tenant, com lista atualizada pelo navegador e detalhes privados. Ainda não entram no quadro principal, nos indicadores financeiros ou nas corridas freelancer. Valores de itens, benefícios, taxas, total, pago online e pendente são preservados como informações do iFood, sem criar recebimento manual nem presumir repasse. O resumo armazenado do pedido omite CPF e segmentação do cliente. Eventos e resumo detalhado ficam criptografados.

Cobertura desta etapa: pedidos de teste explicitamente identificados (`isTest` ou `test`), categoria FOOD, entrega/retirada, imediatos/agendados, quantidades fracionárias e adicionais aninhados dentro dos limites. Campos obrigatórios ausentes, valores monetários inválidos, Merchants divergentes ou pedidos reais geram pendência visível, sem venda fictícia. Códigos PLC, CFM, SPS, RTP, DSP, CON e CAN atualizam o status; eventos antigos não reabrem pedidos cancelados/concluídos. Tipos não suportados são preservados sem ação, com diagnóstico no superadmin; não implementamos ainda negociações nem atualizações de composição por eventos específicos.

Falhas na busca de detalhes ou no ACK são retomadas, com atraso progressivo de até uma hora para processamento. Desligar novos recebimentos não interrompe eventos já armazenados. Vínculos com pendências ou pedidos ativos não podem ser removidos, e um Merchant com histórico não pode ser transferido silenciosamente para outra loja. O piloto limita recebimento a 100 lojas por aplicativo e processamento a cinco eventos por ciclo; dimensionamento, limites e concorrência precisam de validação real antes de produção.

Validação local: API simulada, testes de repetição, isolamento, eventos fora de ordem, ACK após persistência, recuperação após erro, pedidos reais bloqueados e atualização web com perda de rede. Ainda não há teste com credenciais reais nem homologação. O envio inicial de comandos está descrito na seção acima; negociações, informação de pagamento e modalidade logística completas seguem pendentes. O aplicativo iFood deve pertencer à empresa dona deste SaaS; restaurantes clientes autorizam o acesso após homologação. A loja de um amigo pode servir como piloto posterior, sem substituir o cadastro de desenvolvedor da plataforma.

## Histórico: painel e conexão inicial iFood

Painéis de tenant e superadmin redesenhados com base na prévia aprovada, navegação responsiva, busca de empresas, cópia do link do cardápio, pausa rápida e indicadores completos do período. Conferência local no Chrome em desktop e celular, com fixtures isoladas. O usuário confirmou acesso de administrador e cadastro de tenants no Coolify; isso não substitui o piloto de todos os fluxos em produção.

Integração iFood adicionada ao escopo na seção 16.1, antecipada em relação aos demais marketplaces. Primeira etapa implementada: configuração central de aplicativo de teste, credenciais e cache de token criptografados, autenticação, vínculo exclusivo de Merchant ID à loja por superadmin, consulta manual de situação e leitura segregada por tenant. Rotação de credenciais exige nova verificação. As telas identificam teste e a data da última consulta; situação consultada não é monitoramento nem habilitação para receber pedidos.

Validação do iFood: testes HTTP simulados. Ainda é necessário cadastrar credenciais e Merchant ID de teste no painel e validar com a API real. Recepção e ações iniciais de teste estão descritas acima; catálogo, produção e homologação permanecem pendentes.

Operação: após publicar esta versão, a inicialização do Compose executa as migrations. Em **Superadmin → Integração iFood**, informar Client ID e Client Secret do aplicativo centralizado de teste, testar autenticação, selecionar empresa/loja e informar Merchant ID da loja de teste. Nunca enviar a chave pelo chat. O tenant acompanha em **Integração iFood**; não recebe credenciais globais nem pode reivindicar Merchant ID de outra empresa. O ambiente de teste é declarado por credenciais/loja, não detectado pela URL compartilhada da API.

## Onde parou

Fundação funcional e parte do delivery inicial implementadas. As fases 0 e 1 do escopo ainda não estão concluídas; não existe ainda uma operação completa de restaurante pronta para produção. Foram implementadas partes da logística antes de completar todos os recursos do delivery. Não atribuir um percentual artificial ao escopo: infraestrutura, telas iniciais e integrações homologadas são entregas diferentes.

| Área | Estado confirmado |
|---|---|
| Empresas e acesso | Login, perfis e isolamento das operações existentes; recuperação de senha implementada, pendente de SMTP real; equipe por loja implementada com Operação/Cozinha; permissões personalizadas pendentes |
| Lojas e catálogo | Cadastro e edição, fotos, horários semanais, pausa, grupos/adicionais e cardápio; variações, combos e feriados implementados nas etapas acima; mais de dois períodos diários pendentes |
| Checkout | Revisão antes de confirmar, preços e adicionais no servidor, idempotência, regiões/frete e pedido mínimo; validação geográfica por raio implementada com API configurável, pendente de piloto; agendamento implementado; pagamento online pendente |
| Pedidos e cozinha | Aceite, preparo, pronto, conclusão, filtros, paginação, detalhes privados, atualização automática e som ativável; faltam estações e ocorrências completas |
| Freelancers | Cadastro global, aprovação, check-in, FIFO, oferta regional, aceite, coleta e conclusão; faltam presença web automática, posição da fila visível, alertas, regras editáveis e ocorrências |
| Tarifação e acerto | Três modos de cálculo e registro manual; distância de rota ainda é informada pela loja, sem mapas ou repasse automático |
| Evolution | Servidor central, chave criptografada, provisionamento QR e webhook; testes simulados. Falta homologação real, validar número conectado, reconectar/desconectar e envio confiável |
| WABA | Apenas solicitação pendente, sem conexão oficial implementada |
| Infraestrutura | Dockerfile/Compose e CI preparados; homologação Coolify e concorrência real PostgreSQL pendentes |
| Demais módulos | Mesas/comandas, PDV completo, estoque/ficha técnica, caixa/financeiro completos, fiscal, CRM/fidelidade, marketplaces, impressão e assinatura SaaS pendentes |

## Ordem de desenvolvimento web

### 1. Tornar utilizável o fluxo de venda e produção

**Implementado nesta entrega:** edição de loja/produto, fotos, até dois períodos por dia e pausas; categorias e grupos de adicionais; checkout com revisão, regiões e frete; filtros, detalhes privados e atualização automática com som ativável. Cancelamento exige motivo e os valores das vendas ficam preservados.

**Ainda pendente neste bloco:** validação visual completa em navegadores reais, geocodificação do endereço, agendamento, variações/combos e homologação Coolify/PostgreSQL. A cobertura atual é por região declarada pelo cliente, sem prova de que o endereço está naquela região. Os testes JavaScript usam DOM simulado e não substituem teste em navegador real.

Aceite: operação completa em navegador de computador e celular, com testes de isolamento, valores e falhas; não depender de atualização manual para receber novos pedidos.

### 2. Concluir logística freelancer pela web

Atualizar presença/GPS enquanto página ativa; fila visível; ofertas com contagem e atualização automática; parâmetros de frete e despacho editáveis; seleção de lojas participantes; prova de entrega e ocorrências; extrato e acerto detalhados. Acrescentar endereço aproximado pré-aceite e tratamento manual quando ninguém aceitar. Não exigir Android.

Aceite: duas empresas e três entregadores, sem duplicação de corrida, com perda de rede, expiração, recusa e desativação do módulo. Navegador fechado ou página suspensa não implica disponibilidade garantida.

### 3. Fechar WhatsApp e pagamentos reais

Homologar Evolution QR, verificar número efetivo e tratar reconexão; implementar mensagens transacionais persistidas, retentativas e limites por tenant. Desenvolver onboarding/conexão WABA oficial separado do QR. Integrar um gateway para Pix/cartão, webhook idempotente, estorno e conciliação. Definir provedores e obter credenciais de homologação quando necessário; concluir componentes independentes enquanto isso.

### 4. Completar operação de restaurante

PDV, mesas/comandas, garçons web, divisão da conta, estações KDS, impressão, caixa, estoque e ficha técnica, compras, financeiro e fiscal no cenário escolhido. Respeitar dependências: emissão fiscal obrigatória para uma operação piloto deve estar pronta antes de iniciar essa operação.

### 5. Crescimento e comercialização

CRM, cupons, fidelidade, campanhas, marketplaces, multimarcas, relatórios consolidados, planos e cobrança SaaS.

## Homologação transversal

Coolify, PostgreSQL, backups restauráveis, segurança e validação em navegador acompanham as entregas. A suíte foi ampliada com testes de revisão do checkout, adicionais, horários, regiões, fotos, isolamento e JavaScript. Passar testes locais não comprova build Docker, homologação externa ou todos os fluxos do escopo.

## Android adiado

Não iniciar aplicativo Android agora. Manter contratos do backend compatíveis com um futuro app. A versão web usa presença com página ativa e comunica limitações de suspensão/segundo plano, sem prometer rastreamento contínuo. O app futuro acrescentará recursos de dispositivo; não será necessário para operar os fluxos web acima.
