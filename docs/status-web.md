# Estado e prioridades da versão web

Atualizado em 24/10/2026 após o envio inicial de ações iFood de teste e a correção do botão Sair, branch `feat/platform-foundation`.

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
| Empresas e acesso | Login, perfis e isolamento das operações existentes; faltam usuários/permissões por loja e recuperação de senha |
| Lojas e catálogo | Cadastro e edição, fotos, horários semanais, pausa, grupos/adicionais e cardápio; faltam variações, combos, feriados e múltiplos intervalos diários |
| Checkout | Revisão antes de confirmar, preços e adicionais no servidor, idempotência, regiões/frete e pedido mínimo; faltam validação geográfica do endereço, agendamento e pagamento online |
| Pedidos e cozinha | Aceite, preparo, pronto, conclusão, filtros, paginação, detalhes privados, atualização automática e som ativável; faltam estações e ocorrências completas |
| Freelancers | Cadastro global, aprovação, check-in, FIFO, oferta regional, aceite, coleta e conclusão; faltam presença web automática, posição da fila visível, alertas, regras editáveis e ocorrências |
| Tarifação e acerto | Três modos de cálculo e registro manual; distância de rota ainda é informada pela loja, sem mapas ou repasse automático |
| Evolution | Servidor central, chave criptografada, provisionamento QR e webhook; testes simulados. Falta homologação real, validar número conectado, reconectar/desconectar e envio confiável |
| WABA | Apenas solicitação pendente, sem conexão oficial implementada |
| Infraestrutura | Dockerfile/Compose e CI preparados; homologação Coolify e concorrência real PostgreSQL pendentes |
| Demais módulos | Mesas/comandas, PDV completo, estoque/ficha técnica, caixa/financeiro completos, fiscal, CRM/fidelidade, marketplaces, impressão e assinatura SaaS pendentes |

## Ordem de desenvolvimento web

### 1. Tornar utilizável o fluxo de venda e produção

**Implementado nesta entrega:** edição de loja/produto, fotos, um horário por dia e pausas; categorias e grupos de adicionais; checkout com revisão, regiões e frete; filtros, detalhes privados e atualização automática com som ativável. Cancelamento exige motivo e os valores das vendas ficam preservados.

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
