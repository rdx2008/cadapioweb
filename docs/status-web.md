# Estado e prioridades da versão web

Atualizado em 24/10/2026 após o envio inicial de ações iFood de teste e a correção do botão Sair, branch `feat/platform-foundation`.

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
| Lojas e catálogo | Cadastro e edição, fotos, horários semanais, pausa, grupos/adicionais e cardápio; faltam variações, combos, feriados e mais de dois períodos diários |
| Checkout | Revisão antes de confirmar, preços e adicionais no servidor, idempotência, regiões/frete e pedido mínimo; validação geográfica por raio implementada com API configurável, pendente de piloto; faltam agendamento e pagamento online |
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
