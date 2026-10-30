# Escopo de produto — Plataforma de restaurantes em Laravel

Versão 1.5 · 24 de outubro de 2026 · Operação web e integração iFood por etapas; Android adiado

## 1. Objetivo e decisões de partida

Criar uma plataforma própria para restaurantes, delivery e redes, reunindo vendas, atendimento, produção, entregas, gestão e relacionamento com clientes. A aplicação deverá ser desenvolvida em Laravel e preparada para implantação em servidor administrado pelo Coolify.

Este é um escopo para orientar desenvolvimento e orçamento; não é uma implementação nem comprovação de que integrações externas já estão disponíveis.

Premissas adotadas: mercado brasileiro; interface em português; moeda BRL; uso por celular, tablet e computador. O produto será obrigatoriamente SaaS multitenant desde a primeira entrega, com múltiplas empresas simultâneas na mesma instalação. Cada empresa será um tenant, com uma ou mais lojas e marcas. O piloto pode começar com uma loja, mas isolamento, configuração e testes de múltiplos tenants são requisitos iniciais, não uma evolução futura.

Princípio de produto: o mesmo pedido deve conectar venda, produção, entrega, pagamento, estoque e análise, evitando redigitação e divergências entre módulos.

## 2. Referências e seleção das melhores soluções

A seleção abaixo é uma avaliação de adequação ao produto proposto, baseada nas páginas públicas consultadas. Não houve acesso aos painéis privados ou teste comparativo de usabilidade, estabilidade e suporte. Os requisitos detalhados das seções seguintes são decisões propostas para este produto, sem atribuir cada detalhe aos concorrentes.

| Referência | Recursos divulgados que merecem ser aproveitados como conceito | Aplicação no produto |
|---|---|---|
| Cardápio Web | Canal próprio com compra sem criar conta; CRM, fidelidade, cashback e recuperação de carrinho; integração entre vendas e gestão | Priorizar conversão e retorno do cliente, usando a mesma base de pedidos e clientes |
| Beefood | Dark kitchen com multicardápios; atendimento por QR Code, garçom, tablet e totem; gestão delivery e integrações | Estruturar marcas e canais sobre uma operação compartilhada, com produção organizada |
| Consumer | PDV, mesas e comandas; estoque e ficha técnica; caixa, financeiro e relatórios como curva ABC | Construir uma operação consistente, com rastreabilidade de valores, consumo e fechamento |

Fontes: [Cardápio Web](https://cardapioweb.com/), [Beefood](https://beefood.com.br/cardapio-web/), [Consumer](https://www.consumer.com.br/). Complementos: [multimarcas Beefood](https://beefood.com.br/sistema-dark-kitchen/) e [KDS Cardápio Web](https://cardapioweb.com/monitor-kds/).

O diferencial recomendado é combinar essas capacidades com instalação própria, exportação dos dados, indicadores de margem por canal e uma interface que exiba somente os módulos ativados para a loja.

## 3. Estrutura do negócio e permissões

- **Administrador da plataforma:** empresas, planos, módulos, consumo, suporte e saúde da instalação.
- **Proprietário da empresa:** lojas, marcas, usuários, indicadores consolidados e configurações.
- **Gerente:** operação da loja, permissões delegadas, descontos, cancelamentos e fechamento.
- **Atendente e caixa:** pedidos e recebimentos dentro dos limites autorizados.
- **Garçom:** mesas, comandas e lançamento de itens.
- **Cozinha e expedição:** apenas produção, conferência e liberação necessárias à função.
- **Entregador:** entregas atribuídas, navegação e comprovação.
- **Estoque e financeiro:** acesso aos respectivos módulos.
- **Cliente:** cardápio, checkout, acompanhamento e benefícios próprios.

Permissões combinam função, empresa, loja e ação. Desconto acima do limite, estorno, reabertura de caixa e alteração de dados sensíveis exigem autorização específica e registro do responsável. Acesso de suporte deve ser temporário, autorizado e auditado.

## 4. Módulos funcionais

### 4.1. Empresas, lojas e marcas

- Cadastro de empresa, lojas, marcas, identidade visual, contatos e horários.
- Horários por modalidade, feriados, pausas e fechamento temporário.
- Subdomínio próprio por loja ou marca; domínio personalizado em etapa posterior.
- Catálogos independentes ou compartilhados, com preços e disponibilidade locais.
- Cozinha compartilhada por marcas, preservando origem do pedido e relatórios individuais.
- Configurações de retirada, entrega, consumo local e agendamento.
- Onboarding guiado: dados, cardápio, pagamentos, entrega, impressão e pedido de teste.

### 4.2. Catálogo e composição de produtos

- Categorias, produtos, fotos otimizadas, descrições, etiquetas e informações de ingredientes.
- Variações de tamanho, adicionais, grupos obrigatórios e opcionais, limites mínimo e máximo.
- Combos com escolhas por etapa; itens fracionados, como pizza, com política de preço explícita.
- Produtos por peso, quando houver operação compatível e entrada validada do peso.
- Preços por canal e loja, promoções por período e disponibilidade por horário.
- Ficha técnica por variação; adicionais também podem consumir ingredientes.
- Estoque por unidade vendável ou por receita, conforme configuração do item.
- Importação CSV com prévia, validação e relatório de erros; exportação do catálogo.
- Produto indisponível deve sair da venda, mantendo o histórico dos pedidos anteriores.

### 4.3. Cardápio digital e checkout

- Interface responsiva, marca própria, pesquisa, categorias e destaques.
- Compra como visitante, sem cadastro obrigatório; identificação progressiva por telefone.
- Seleção de entrega, retirada, mesa ou agendamento antes da conclusão.
- Carrinho persistente, revisão dos adicionais e repetição de pedido sujeita a preços atuais.
- Sugestões contextuais de complementos, sem interromper a compra.
- Endereço com CEP, número, complemento, referência e confirmação da localização.
- Validação da área atendida, pedido mínimo, horário, taxa e estimativa de entrega.
- Cupons, benefícios, instruções e seleção de pagamento.
- Resumo com itens, descontos, entrega e total antes da confirmação.
- Página de acompanhamento com identificador público não previsível.
- Avaliação após conclusão e canal para resolver problemas.
- Cardápio acessível por QR Code e instalável como PWA.

O servidor recalcula preço, adicionais, cupom, frete e disponibilidade no checkout. O valor enviado pelo navegador nunca é a referência financeira.

### 4.4. Central de pedidos e PDV

- Fila única com filtros por origem, loja, marca, modalidade e situação.
- Registro manual para balcão, telefone e atendimento, usando o mesmo motor de preços.
- Aceite manual ou automático, conforme modalidade e confirmação de pagamento.
- Histórico de alterações, alertas de atraso e indicação clara de falhas de integração.
- Prazo estimado conforme fila e capacidade, com possibilidade de ajuste autorizado.
- Rejeição ou cancelamento com motivo, tratamento financeiro e comunicação ao cliente.
- Pedidos agendados com capacidade por faixa e liberação para produção no momento configurado.
- Busca por número, telefone, cliente e origem externa.
- Atalhos de teclado, leitura de código de barras quando aplicável e foco em operação rápida.
- Cadastro rápido de cliente e endereço pelo atendente.

### 4.5. Mesas, comandas e garçons

- Mapa de mesas com ocupação, tempo aberto e conta parcial.
- Comandas individuais ou compartilhadas e identificação do garçom.
- Pedidos pelo garçom ou QR Code, com aprovação configurável antes da cozinha.
- QR Code vinculado a sessão ativa de mesa, evitando reutilização indevida de uma conta encerrada.
- Transferência e união de mesas; transferência de itens com auditoria.
- Divisão por itens, pessoas ou valores; pagamentos parciais e múltiplas formas.
- Taxa de serviço configurável, com remoção autorizada e registro.
- Cancelamento de item com motivo e aviso à produção, quando já enviado.
- Solicitação de atendimento e conta; impedir fechamento com saldo pendente.

### 4.6. Cozinha, KDS e expedição

- Estações por tipo de preparo: cozinha, bar, sobremesa e embalagem.
- Encaminhamento dos itens para a estação correta, mantendo visão consolidada do pedido.
- Tempos por etapa, alertas e destaque de agendamentos e prioridades autorizadas.
- Fluxo de preparo por item e sinalização de pedido pronto somente com os itens necessários finalizados.
- Observações e ingredientes em destaque; informação de alergia informada pelo cliente deve ficar visível, sem prometer ausência de contaminação.
- Conferência de embalagem, bebidas e complementos antes da saída.
- Impressão alternativa ou complementar ao KDS, por setor.
- Reimpressão identificada e auditada; falha de impressora visível e passível de reenvio.
- Indicadores de tempo por estação, retrabalho e atraso.

### 4.7. Entregas

#### 4.7.1. Rede de motoboys freelancers e fila por loja

O sistema deve oferecer cadastro de entregadores freelancers e despacho híbrido: primeiro a fila dos entregadores presentes na loja; quando não houver candidato próximo elegível, uma oferta aberta para entregadores disponíveis. A rede pode atender várias empresas, sem permitir que lojistas consultem dados de outras empresas.

**Cadastro na plataforma:** o freelancer se cadastra diretamente no portal/aplicativo da plataforma, sem precisar ser cadastrado por um restaurante. Informa seus dados, veículo, região de atuação e documentos definidos pela operação; acompanha aprovação e mantém seu perfil. O cadastro e a aprovação são geridos pela plataforma. Lojas podem convidar um entregador já cadastrado, autorizar vínculos e aplicar bloqueios locais, sem criar cópias independentes do perfil. A identidade global é única e pode atender diferentes tenants elegíveis.

- Perfil do entregador no nível da plataforma, com identidade, contato, veículo, documentos definidos pela operação, situação de aprovação e bloqueios. Vínculos, avaliações operacionais e restrições podem existir por loja.
- Aplicativo/PWA próprio para disponibilidade, check-in, ofertas, coleta, navegação, conclusão e extrato de ganhos.
- Check-in presencial por loja, validando localização recente dentro de um raio configurável e sessão autenticada; QR Code rotativo da loja pode complementar a validação. Registrar precisão do GPS e tratar exceções com autorização do gerente.
- Cada loja mantém sua própria fila FIFO, ordenada pelo horário de entrada e desempate por identificador sequencial do servidor. Exibir posição ao entregador e fila ao operador autorizado.
- Um entregador mantém apenas um check-in ativo de loja por vez. Ao trocar de loja, sai da fila anterior. Check-in não dá acesso aos pedidos da loja.
- Elegibilidade exige cadastro aprovado, loja sem bloqueio aplicável, disponibilidade ativa, heartbeat e localização recentes, presença dentro do raio e ausência de corrida incompatível.
- Check-out manual, afastamento da área, expiração por inatividade ou início de corrida retira/suspende o entregador da fila conforme regra explícita. Ao voltar e fazer novo check-in, entra no fim.
- Fila, presença e disponibilidade devem ser recalculadas no servidor; aplicativo aberto ou push entregue não são prova suficiente de elegibilidade.

#### 4.7.2. Despacho sequencial e oferta aberta

1. Pedido fica apto para despacho quando estiver pronto ou atingir antecedência de preparo configurada, sem liberar coleta antes da conferência.
2. Sistema seleciona o primeiro entregador elegível da fila da loja e cria oferta exclusiva com prazo configurável; proposta inicial de 30 segundos.
3. Aceite válido atribui a corrida e retira o entregador da disponibilidade para novas corridas incompatíveis.
4. Recusa ou expiração oferece a corrida ao próximo elegível, sem permitir que o mesmo candidato receba novamente a mesma rodada de oferta. Por padrão, recusa mantém sua posição para outra corrida; expiração por inatividade pode suspender presença. Regras diferentes precisam ser explícitas e auditáveis.
5. Se a fila estiver vazia ou não houver mais entregador próximo elegível após as tentativas, abrir a corrida aos freelancers disponíveis e elegíveis na região de coleta. Proximidade significa distância da loja, não do cliente.
6. Na oferta aberta, todos os candidatos do grupo recebem oportunidade simultânea; ganha o primeiro aceite confirmado atomicamente pelo servidor, conforme o modelo solicitado semelhante ao Uber.
7. O raio inicial e a expansão por etapas são configuráveis. Sem aceite, ampliar a busca até o limite; ao esgotar, alertar a loja, permitir despacho manual autorizado e manter o pedido visível como pendente.
8. Uma oferta aberta já ativa não é tomada por alguém que acabou de fazer check-in. A ordem da loja vale para os próximos despachos; a oferta atual termina por aceite, expiração ou cancelamento registrado.

Valores iniciais de timeout e raio são parâmetros a validar no piloto, sem números fixos escondidos no código. Permitir ao tenant habilitar ou não a rede compartilhada; oferta aberta para a rede exige adesão da loja e do entregador à operação.

#### 4.7.3. Regras da corrida e prevenção de conflitos

- Mostrar antes do aceite: loja de coleta, destino aproximado, distância estimada, valor do frete devido ao entregador, previsão de coleta, quantidade de volumes e necessidade de receber dinheiro/troco.
- Revelar endereço completo e contato do cliente somente ao entregador atribuído, pelo tempo necessário à corrida.
- Separar taxa de entrega cobrada do consumidor, remuneração do entregador e eventual subsídio da loja. A proposta aceita preserva o valor acordado; mudança material exige novo aceite.
- Máquina de estados: aguardando despacho → ofertada → atribuída → deslocamento à loja → chegada → coletada → em entrega → concluída. Prever cancelada, falha de entrega e reoferta, com motivos.
- Estados da oferta são independentes: criada, aceita, recusada, expirada, revogada ou perdida para outro aceite.
- Aceite transacional com trava/atualização condicional e restrição de atribuição ativa por corrida. Retentativas do mesmo aceite retornam o mesmo resultado; concorrentes recebem “corrida já atribuída”.
- Impedir duas atribuições incompatíveis ao mesmo entregador, mesmo em lojas de tenants diferentes. No lançamento, uma corrida ativa por entregador; agrupamento de pedidos fica para evolução explícita.
- Executar despacho concorrente da mesma loja sem oferecer pedidos diferentes simultaneamente ao mesmo primeiro candidato. Reserva temporária de oferta com expiração deve impedir esse conflito.
- Cancelamento antes da coleta pode reofertar; após coleta exige tratamento de ocorrência e orientação do operador, sem redistribuição automática cega.
- Registrar chegada, espera, coleta, entrega, tentativa frustrada e responsável por alterações manuais.
- Histórico e extrato por entregador, acerto por loja, pendências de numerário e regras configuradas para espera/cancelamento. Pagamento automatizado ao freelancer não é presumido: primeira versão registra valores e acertos; repasse financeiro requer integração própria.
- Uso de localização restrito ao período de disponibilidade/check-in/corrida necessário à operação; acesso e retenção documentados.
- Push e tela em tempo real são meios de aviso; elegibilidade e vencedor são sempre decididos pelo servidor. Validar limitações de GPS em segundo plano na PWA; se comprometer o despacho, aplicativo móvel torna-se dependência desse módulo.

#### 4.7.4. Cálculo e remuneração das corridas

O módulo deve permitir regras de preço por loja, com padrões administrados pela plataforma e limites de edição conforme permissão. Modos obrigatórios:

- **Por corrida:** valor definido para aquela corrida antes da oferta, por regra/tabela ou ajuste autorizado; permanece vinculado à proposta aceita.
- **Por quilômetro:** distância tarifável multiplicada pelo preço por km, com valor mínimo e opção explícita de parcela base por corrida.
- **Taxa fixa:** valor previamente configurado por loja ou zona, independente da distância dentro da cobertura definida.

“Por corrida” representa uma cotação individual; “taxa fixa” representa uma tabela reutilizada automaticamente. O operador deve identificar claramente o modo aplicado e a composição do valor.

Regras complementares:

- Definir a distância tarifável: por padrão, rota estimada da loja até o destino. Deslocamento do motoboy até a loja só entra se houver regra específica, informada antes do aceite.
- Usar distância de rota retornada pelo provedor de mapas, com origem, destino, horário, versão da regra e resultado preservados. Distância em linha reta não deve ser apresentada como quilometragem de rota.
- Na falha do cálculo de rota, usar tabela fixa de contingência previamente configurada ou exigir cotação manual; sinalizar a origem do valor, sem inventar distância.
- Exemplo ilustrativo: base de R$ 4,00 + 3,2 km × R$ 2,00/km = R$ 10,40; se o mínimo for R$ 12,00, ofertar R$ 12,00. Valores são exemplos, não preços padrão do produto.
- Configurar precisão da distância, arredondamento monetário, mínimo, zonas, adicionais de espera e cancelamento; apresentar o total antes da oferta.
- Alterar regra de preço não modifica corridas já aceitas. Mudança de endereço ou condição material gera recotação e novo aceite, ou tratamento autorizado de ocorrência.
- Preservar separação entre frete cobrado do consumidor, remuneração do motoboy, subsídio da loja e eventual tarifa da plataforma. Entrega grátis ao consumidor não zera automaticamente a remuneração do freelancer.
- Gerar lançamento de ganho conforme conclusão ou ocorrência remunerável; extrato e acerto registram valor previsto, devido, ajustado e pago, com auditoria.

#### 4.7.5. Ativação do módulo e funções dependentes

**Motoboys Freelancers** é um módulo opcional, ativável ou desativável por tenant pelo administrador da plataforma, conforme plano/permissão. Dentro do tenant habilitado, o proprietário escolhe as lojas participantes. A ativação libera funções integradas no restante do sistema, e não somente um menu de entregadores.

| Área | Função liberada com módulo ativo |
|---|---|
| Loja | Adesão à rede, vínculos, fila presencial e políticas de despacho |
| Pedidos | Solicitar corrida, exibir oferta/atribuição e tratar falhas de despacho |
| Cozinha e expedição | Acionar despacho por previsão de preparo, avisar chegada e confirmar coleta |
| Logística | Check-in, fila FIFO, ofertas sequenciais, oferta aberta e atribuição automática |
| Preços | Cotação por corrida, por km ou fixa e composição de remuneração |
| Cliente | Acompanhamento do entregador atribuído quando disponível e comprovação de entrega |
| Financeiro | Ganhos, valores a acertar, numerário coletado e conciliação por freelancer |
| Relatórios | Espera, tempo de coleta/entrega, recusas, aceites, custo e desempenho |
| Notificações | Avisos específicos de oferta, aceite, chegada, coleta e conclusão |

Com o módulo desativado, a loja continua usando pedidos, retirada e entrega manual/própria configurada, sem acesso à rede freelancer, check-in, fila ou despacho automático desse módulo. Funções gerais de venda, frete ao consumidor, cozinha e caixa não dependem da sua ativação.

A habilitação deve ser verificada no backend, APIs, jobs, eventos e WebSockets, além da interface. O frontend recebe as capacidades efetivamente liberadas para usuário, tenant e loja; não basta esconder menus. Dependências ficam registradas em uma matriz de funcionalidades e permissões.

Desativação com corridas ou ofertas em andamento entra em estado de encerramento: bloquear novas solicitações, revogar ofertas ainda não aceitas e permitir concluir/tratar corridas atribuídas. Não apagar histórico, ganhos, acertos ou obrigações pendentes. Depois do encerramento, manter consulta histórica conforme permissões e impedir novos despachos. Reativação preserva histórico e exige revalidar disponibilidade; não restaura check-ins ou ofertas expiradas.

O portal global do entregador continua existindo para atender outros tenants ativos; desabilitar uma loja ou empresa não desativa a conta global do freelancer.

#### 4.7.6. Recursos gerais de entrega

- Áreas por bairro, CEP, raio ou polígono, com regra de precedência documentada.
- Taxas, pedido mínimo, prazo e restrições por área e horário.
- Cadastro, disponibilidade e atribuição de entregadores.
- Agrupamento manual de pedidos e sugestões de rota em etapa avançada.
- PWA do entregador: aceitar atribuição, abrir navegação, registrar saída e conclusão.
- Comprovação por código do cliente ou outro método configurado; registro de tentativa frustrada.
- Controle de valores recebidos na entrega e acerto por entregador.
- Rastreamento somente durante entrega ativa, condicionado às permissões e limitações do dispositivo.
- Integração com logística terceirizada mediante contrato e API disponíveis.

### 4.8. Pagamentos, caixa e conciliação

- Pix com cobrança individual e confirmação pelo provedor; cartão por checkout ou tokenização do gateway.
- Dinheiro, troco, cartão presencial e pagamento na entrega.
- Separação entre forma de pagamento, transação online, recebimento e lançamento de caixa.
- Abertura, suprimento, sangria, fechamento cego e comparação entre esperado e contado.
- Separação por operador, turno, loja e caixa físico.
- Pagamentos parciais e múltiplos; saldo remanescente calculado pelo servidor.
- Estorno total e parcial com situação pendente, confirmado ou falhou.
- Conciliação entre venda, taxa do provedor, valor líquido e recebimento.
- Fluxo para pagamento recebido após expiração ou cancelamento do pedido.
- Fiado opcional, com limite, vencimento, permissões e recebimento posterior.

Provedores priorizados pelo usuário: InfinitePay e PagSeguro/PagBank, inicialmente por checkout hospedado com confirmação pela API. O projeto deve permitir outros provedores por adaptadores. Cada loja recebe em sua conta de provedor; intermediação e split ficam fora do lançamento inicial.

### 4.9. Estoque, compras e ficha técnica

- Ingredientes, embalagens, fornecedores e unidades de medida.
- Conversão de unidades e rendimento para relacionar compra e consumo.
- Receitas por tamanho, variação, adicional e combo.
- Entradas, reservas, consumo, perdas, ajustes e inventário com motivo.
- Baixa por evento de negócio definido, protegida contra repetição.
- Cancelamento antes do preparo libera reserva; após consumo, gera perda ou ajuste autorizado, sem devolver automaticamente insumo utilizado.
- Alertas de mínimo e indisponibilidade de itens afetados.
- Compras, recebimento parcial e atualização de custo com histórico.
- CMV teórico por receita e CMV apurado por movimentações e inventário, apresentados separadamente.
- Lotes, validade, produção de semielaborados e transferência entre lojas em fase avançada.

### 4.10. Financeiro e indicadores

- Contas a pagar e receber, categorias, vencimentos e centros de custo.
- Fluxo de caixa realizado e previsto; despesas recorrentes.
- Visão gerencial de receitas, taxas, descontos, insumos e resultado estimado.
- Receita bruta, vendas líquidas, ticket médio, pedidos e cancelamentos.
- Comparação por loja, marca, produto, canal, período e modalidade.
- Curva ABC, custo e margem de contribuição estimada por produto e canal.
- Indicadores de produção, entrega, recompra e uso de promoções.
- Exportação CSV e relatórios compartilháveis conforme permissão.
- Definição visível de cada métrica, inclusive quais cancelamentos, taxas e custos entram no cálculo.

Relatórios gerenciais não substituem escrituração contábil. Custos ausentes devem aparecer como incompletos, impedindo apresentar margem estimada como lucro apurado.

### 4.11. CRM, fidelidade e marketing

- Perfil do cliente com consentimentos, pedidos, frequência e preferências informadas.
- Segmentos por recência, frequência, valor, loja e categoria de compra.
- Cupons com validade, limite global, limite por cliente e regras de combinação.
- Fidelidade por pontos ou cashback, ativados separadamente para manter clareza.
- Extrato de benefícios com geração, reserva, uso, expiração e reversão por cancelamento.
- Automações de boas-vindas, pós-venda, aniversário e reativação, com limites de frequência.
- Recuperação de carrinho quando houver identificação e permissão de contato aplicáveis.
- Campanhas com custo, público, resultado e cancelamento de recebimento.
- Mensuração de conversão e atribuição por origem, sem confundir correlação com receita incremental.
- Eventos de analytics e mídia configuráveis, respeitando escolhas de privacidade.

### 4.12. WhatsApp e assistência por IA

- Evolution API como gateway central de WhatsApp, oferecendo conexão por QR Code e conexão oficial WABA/WhatsApp Cloud API conforme o modo escolhido pelo tenant.

#### 4.12.1. Configuração central no administrador da plataforma

- Somente o superadmin cadastra URL do servidor Evolution, credencial administrativa, versão suportada, configuração de callbacks e limites operacionais.
- O mesmo servidor Evolution atende todos os tenants, com instâncias/conexões segregadas e mapeadas a empresa e loja; compartilhar servidor não significa compartilhar número, sessão ou WABA.
- A chave administrativa permanece no backend, protegida e nunca enviada aos navegadores dos tenants. O lojista não precisa configurar host, porta, webhook ou chave global.
- Provisionar automaticamente instância, vínculo, callbacks e parâmetros disponíveis na versão homologada, com tentativas idempotentes e reconciliação de instâncias órfãs.
- Admin acompanha conexão, falhas, consumo e quotas por tenant; pode testar servidor e rotacionar credenciais sem expor conversas desnecessariamente.
- Persistir mapeamento por identificador real da instância e da conta externa; o tenant não pode escolher livremente o identificador de outra conexão.

#### 4.12.2. Conexão simples no painel do tenant

**Modo QR Code:** lojista seleciona loja, informa número com código do país e clica em conectar. Backend cria/configura a instância e apresenta QR Code para leitura no celular. Atualizar QR expirado, mostrar andamento e confirmar o número efetivamente conectado; divergência com o número informado exige correção antes de ativar. Incluir desconectar e reconectar com autorização apropriada. Gerar QR não confirma autenticação: a leitura pelo responsável continua necessária.

**Modo oficial WABA:** lojista escolhe “Conectar API oficial” e segue onboarding autorizado da Meta, preferencialmente Embedded Signup quando o aplicativo da plataforma estiver habilitado. Associar WABA, identificador de telefone e autorizações ao tenant; validar callbacks e teste de envio. Prever conexão assistida com credenciais da própria conta quando o onboarding incorporado não estiver disponível.

A automação deve remover configuração técnica repetitiva, mas não prometer conexão oficial apenas digitando o telefone: o fluxo oficial depende de autorizações, ativos e verificações da Meta. QR de sessão via WhatsApp Web/Baileys e Cloud API oficial são modos distintos; coexistência e qualquer QR de onboarding oficial só entram se suportados e homologados para a conta e versão escolhidas.

Fonte técnica: o [repositório oficial da Evolution API](https://github.com/evolution-foundation/evolution-api) distingue conexão Baileys/WhatsApp Web e WhatsApp Cloud API. O provisionamento exato e o suporte ao onboarding incorporado devem ser validados na prova técnica da versão instalada; não se presume que a Evolution realize todas as etapas da Meta automaticamente.

#### 4.12.3. Isolamento, mensagens e operação

- Webhooks passam por verificação de autenticidade disponível no provedor e roteamento pelo vínculo de instância/conta mantido no servidor; nunca pelo tenant declarado livremente no payload.
- Deduplicar mensagens e eventos, tratar reordenação e status de envio, entrega, leitura e falha quando disponíveis.
- Limites e filas por tenant impedem uma campanha de bloquear notificações de pedidos de outros tenants.
- Conversas, contatos, mídias, templates, permissões e consumo segregados por empresa; escopo de loja explícito para equipes e números locais.
- Suportar mais de um número por tenant conforme plano; uma conexão pertence a um único tenant. Compartilhamento de um número entre lojas da mesma empresa exige regra de roteamento definida.
- Não fazer fallback silencioso entre WABA e sessão QR, nem enviar novamente mensagem já confirmada em outro modo. Mudança de modo exige fluxo de migração explícito.
- Acompanhar desconexão, sessão expirada, autorização revogada e mensagens pendentes; permitir reprocessamento seguro e alertar o lojista.
- Para WABA, controlar templates, janela de atendimento, elegibilidade e custos conforme regras vigentes do provedor.

#### 4.12.4. Atendimento e IA

- Notificações de pedido e atendimento, observando regras e custos do provedor.
- Consulta a horários, catálogo, área de entrega e andamento do pedido.
- Transferência para atendente com contexto e histórico necessário.
- IA opcional para dúvidas e auxílio na escolha, usando dados atuais da loja.
- Confirmação explícita de itens, endereço e total antes de efetivar pedido.
- IA sem autonomia para conceder descontos fora das regras, alterar estoque ou marcar cobrança como paga.
- Registro das ações, limites de consumo e desativação por empresa.

### 4.13. Marketplaces e integrações

- Camada de adaptadores com identificadores externos, situação de sincronização e logs.
- Importação de pedidos, aceite, cancelamento e atualização de status, conforme API contratada.
- Mapeamento de produtos, adicionais, pagamentos e motivos de cancelamento.
- Sincronização de catálogo e disponibilidade apenas quando o canal oferecer suporte.
- Idempotência para notificações repetidas, retentativas e painel de falhas.
- Primeiro marketplace escolhido após validar acesso, documentação e homologação.
- Novos conectores liberados individualmente; não anunciar integração antes de validá-la.

As integrações divulgadas pelos fornecedores de referência não garantem que as mesmas APIs estejam liberadas para este projeto.

### 4.14. Fiscal

- Adaptador para provedor fiscal, escolhido conforme documento e operação atendidos.
- Cadastro fiscal de empresa e produtos, certificado quando exigido e ambiente de homologação.
- Emissão, consulta, cancelamento, armazenamento de XML e documento auxiliar.
- Monitoramento de rejeições e tentativas; contingência somente quando prevista e suportada.
- Identificação inequívoca entre comprovante não fiscal e documento fiscal.
- Implantação validada por cenário, unidade federativa e provedor, com apoio contábil.

O escopo não presume cobertura nacional automática ou conformidade de todos os regimes desde o lançamento.

### 4.15. SaaS e gestão da plataforma

- Empresas, planos, módulos, limites e período de teste.
- Cobrança da assinatura separada dos pagamentos dos consumidores.
- Consumo de mensagens, armazenamento e integrações visível para cada empresa.
- Suspensão comercial com comunicação e regra de preservação e exportação de dados.
- Painel de falhas, disponibilidade, auditoria de suporte e trilha de alterações de plano.
- Atualizações do produto e configurações por empresa sem criar versões divergentes do código.

## 5. Regras transversais e integridade

1. **Isolamento:** empresa e loja validadas no servidor, inclusive em arquivos, buscas, jobs, relatórios e WebSockets. Nunca confiar em tenant enviado pelo navegador.
2. **Histórico:** pedido guarda cópia dos nomes, preços, impostos aplicáveis, adicionais e descontos da venda; editar o catálogo não altera o passado.
3. **Dinheiro:** valores financeiros em centavos ou decimal fixo; quantidades fracionadas com precisão definida. Nunca usar ponto flutuante para totais financeiros.
4. **Idempotência:** repetir checkout, webhook ou job não duplica pedido, recebimento, benefício, emissão fiscal ou baixa de estoque.
5. **Concorrência:** transações, restrições e bloqueios adequados protegem último item disponível, uso de cupom e fechamento de caixa simultâneos.
6. **Eventos:** mudança de pedido e evento persistem de forma confiável; usar outbox transacional para efeitos externos críticos.
7. **Auditoria:** ações sensíveis registram autor, horário, empresa, motivo e alterações relevantes; segredos não entram em logs.
8. **Tempo:** armazenar instantes em UTC e apresentar no fuso da loja; dia operacional pode terminar após meia-noite.
9. **Benefícios:** descontos, cashback e pontos possuem precedência e limites claros; impedir saldo negativo e uso duplicado.
10. **Operação degradada:** reconexão atualiza telas e sinaliza inconsistências; PWA não implica PDV offline completo.

## 6. Estados e fluxos essenciais

Separar três dimensões, evitando um status único que misture cobrança e cozinha:

- **Pedido:** aguardando aceite → aceito → em produção → pronto → em entrega ou aguardando retirada → concluído. Rejeição e cancelamento são saídas controladas conforme etapa.
- **Pagamento:** pendente → autorizado, quando aplicável → pago; alternativas de falha, expiração e estorno pendente/parcial/total.
- **Fiscal:** não solicitado → pendente → autorizado ou rejeitado → cancelamento quando aplicável.

Fluxo delivery: cliente escolhe modalidade → monta carrinho → servidor valida preços e área → cria pedido e cobrança → confirmação válida → aceite → produção → expedição → atribuição → entrega → conciliação e pós-venda.

Fluxo salão: abertura de sessão → lançamentos por garçom ou cliente → confirmação quando exigida → produção por rodada → consumo → divisão da conta → recebimentos → fechamento e encerramento da sessão.

Fluxo cancelamento: validar permissão e etapa → registrar motivo → interromper tarefas possíveis → tratar reserva/consumo → solicitar estorno quando necessário → reverter benefícios → atualizar integração → informar cliente. Efeitos pendentes ficam visíveis até conclusão.

## 7. Experiência e navegação

Menu do lojista: **Visão geral; Pedidos; PDV; Mesas; Cozinha; Entregas; Cardápio; Clientes; Marketing; Estoque; Financeiro; Relatórios; Integrações; Configurações.** Exibir somente itens autorizados e habilitados.

Telas operacionais devem privilegiar poucos toques, botões grandes, atualização em tempo real e mensagens acionáveis. Cor não pode ser a única indicação de estado. O cardápio do consumidor deve mostrar modalidade, horário, estimativa e total com clareza.

Painéis separados: administração da plataforma; gestão do restaurante; operação de caixa; garçom; cozinha; expedição; entregador; cardápio público; acompanhamento do cliente.

## 8. Arquitetura Laravel proposta

### 8.1. Stack

- **Backend:** Laravel 13, com versão de PHP compatível e dependências fixadas no início da implementação.
- **Frontend:** Vue 3, TypeScript e Inertia, com componentes compartilhados entre painéis; cardápio público otimizado para carregamento móvel.
- **Banco:** PostgreSQL para dados transacionais.
- **Cache e filas:** Redis; workers independentes para operação, integrações, marketing e relatórios.
- **Tempo real:** Laravel Reverb para eventos operacionais, com reconciliação via consulta à API após reconexão.
- **WhatsApp:** serviço Evolution central configurado pelo superadmin, com conexões por tenant e adaptadores para modo QR e modo oficial.
- **Despacho:** serviço de domínio Laravel com filas, presença temporária e ofertas; atribuições e aceite persistidos transacionalmente no PostgreSQL.
- **Arquivos:** armazenamento compatível com S3; alternativa em volume persistente para instalação pequena.
- **API:** endpoints versionados para integrações e dispositivos, com autenticação e escopos apropriados.
- **Qualidade:** testes automatizados de regras críticas, análise estática e integração contínua.

São escolhas de projeto, sujeitas a uma validação técnica inicial. A documentação atual de implantação do Laravel 13 informa PHP mínimo 8.3 e orienta servir a aplicação pela pasta `public`, além de prever otimização e gerenciamento dos serviços persistentes: [Laravel Deployment](https://laravel.com/docs/13.x/deployment).

### 8.2. Organização

Monólito modular, com domínios explícitos: Identidade, Empresas, Catálogo, Pedidos, Salão, Produção, Logística, Pagamentos, Caixa, Estoque, Financeiro, Relacionamento, Fiscal, Integrações e Assinaturas.

Cada domínio concentra regras, permissões e testes. Controladores delegam casos de uso; integrações externas usam interfaces e adaptadores. Evitar microserviços no início, preservando possibilidade de extração quando houver motivo operacional real.

Banco compartilhado com `tenant_id`, relações compostas e validações de pertencimento onde necessário. Toda consulta e tarefa deve carregar contexto explícito. Testes de isolamento são obrigatórios; apenas aplicar um filtro global do ORM é insuficiente.

### 8.3. Entidades principais

| Domínio | Entidades |
|---|---|
| Organização | empresas, lojas, marcas, usuários, vínculos, papéis e permissões |
| Catálogo | cardápios, categorias, produtos, variações, grupos de escolhas, adicionais, preços e disponibilidade |
| Vendas | clientes, endereços, carrinhos, pedidos, itens, escolhas, ajustes, eventos e sessões de mesa |
| Operação | comandas, rodadas, estações, tarefas de produção, impressoras e trabalhos de impressão |
| Cobrança | intenções, transações, alocações de pagamento, estornos, caixas e movimentações |
| Logística | áreas, regras de frete, entregadores, atribuições, rotas e ocorrências |
| Rede freelancer | perfis globais de entregadores, vínculos e bloqueios por loja, check-ins, fila por loja, presença, ofertas, aceites, corridas, ganhos e acertos |
| WhatsApp central | servidores Evolution, conexões por tenant/loja, instâncias, ativos WABA, eventos recebidos, mensagens, status, templates e quotas |
| Gestão | insumos, receitas, reservas, movimentos, inventários, fornecedores, compras e títulos financeiros |
| Relacionamento | consentimentos, cupons, resgates, contas de benefícios, lançamentos, segmentos e campanhas |
| Plataforma | assinaturas, módulos, credenciais, mapeamentos externos, inbox/outbox, auditoria e documentos fiscais |

O desenho físico, índices e migrações são entregáveis do projeto técnico; esta relação define os domínios necessários.

## 9. Implantação no Coolify

### 9.1. Pacote esperado

Entregar repositório Git com Dockerfile de múltiplas etapas, Docker Compose para produção, `.env.example` sem segredos, instruções de instalação, migrações, dados demonstrativos separados e procedimento de atualização e restauração.

O Coolify documenta implantação de Laravel e uso de banco, Redis, workers e scheduler. A opção Docker Compose abaixo é a arquitetura proposta para dar visibilidade e controle separado aos processos: [Laravel no Coolify](https://coolify.io/docs/applications/framework-examples/php/laravel).

### 9.2. Serviços

| Serviço | Responsabilidade | Persistência |
|---|---|---|
| Web | HTTP, cardápio, painel e API | Aplicação imutável; uploads fora da imagem |
| Worker operacional | Pedidos, pagamentos e integrações críticas | Eventos no banco; filas conforme política de recuperação |
| Worker secundário | Marketing, relatórios e processamento de imagens | Sem estado local obrigatório |
| Scheduler | Rotinas programadas, expirações e reconciliação | Uma execução efetiva por tarefa |
| Reverb | Atualizações em tempo real | Reconciliação pelo banco/API |
| PostgreSQL | Fonte dos dados de negócio | Volume persistente e backup externo |
| Redis | Cache, coordenação e filas | Política explícita de persistência e recuperação |
| Storage | Fotos e documentos | S3 externo ou volume com backup |
| Evolution API | Gateway compartilhado de WhatsApp, conexões QR e oficiais conforme homologação | Sessões, configuração e dados próprios em serviços/volumes definidos pela versão |

A Evolution deve ter versão fixada, persistência e backup próprios, monitoramento e atualização controlada. Pode ser serviço separado no Coolify ou servidor externo já existente; sua URL e credencial ficam no admin da aplicação. A capacidade de conexões simultâneas precisa de teste. A indisponibilidade da Evolution não pode impedir checkout ou registro dos pedidos.

### 9.3. Requisitos operacionais

- HTTPS e domínios configurados no proxy do Coolify; WebSockets encaminhados corretamente.
- Banco e Redis em rede privada, sem publicação desnecessária de portas.
- Segredos por ambiente; `APP_KEY` gerada uma vez e preservada nos backups seguros.
- `APP_DEBUG=false` em produção; permissões corretas de escrita e raiz HTTP em `public`.
- Build reproduzível; migração executada uma vez por implantação e compatível com a versão em execução.
- Atualização coordenada de web e workers; reinício controlado dos processos persistentes.
- Health checks de aplicação e verificações separadas para banco, fila, scheduler e armazenamento.
- Logs centralizáveis, alertas de jobs falhos, atraso nas filas e integrações sem retorno.
- Backups criptografados fora do servidor; teste de restauração antes da entrada em produção.
- Ambiente de homologação com credenciais de teste e sem campanhas reais.
- Rollback da aplicação documentado; mudanças de banco exigem estratégia própria e não devem depender de migração destrutiva automática.

Ponto inicial para ensaio de capacidade: servidor com 4 vCPU e 8 GB de RAM, ajustado após teste. Isso é hipótese de dimensionamento para piloto, não promessa de capacidade. Instalação em um único servidor possui ponto único de falha; alta disponibilidade requer projeto adicional.

## 10. Segurança, dados e recuperação

- Autenticação segura, recuperação de acesso, revogação de sessões e MFA para administradores.
- Autorização no servidor, proteção contra abuso, validação de uploads e URLs assinadas para documentos privados.
- Credenciais de integrações protegidas e rotacionáveis; webhooks autenticados conforme o provedor.
- Cartões processados pelo gateway; não armazenar número completo ou CVV.
- Registro de consentimentos e preferências; exportação, exclusão ou anonimização conforme política e obrigações aplicáveis.
- Política de retenção para pedidos, documentos, rastreamento e logs definida antes da produção.
- Proteção contra acesso cruzado em exportações, cache, tarefas em fila e canais de tempo real.
- Dados financeiros e de auditoria preservados segundo regras aplicáveis, sem exclusão indiscriminada.
- Metas iniciais propostas: recuperação em até 4 horas e perda máxima de 1 hora de dados. Só podem ser assumidas após implementar backups/logs compatíveis e demonstrar restauração.

Esta seção especifica controles de engenharia; a política jurídica de privacidade e as obrigações fiscais precisam de validação própria.

## 11. Qualidade e critérios de aceite

Metas mensuráveis para homologação, com ambiente e massa de teste registrados:

| Área | Critério |
|---|---|
| Isolamento | Usuário de uma empresa não lê nem altera dados, arquivos ou eventos de outra, inclusive manipulando IDs |
| Checkout | Duplo clique e repetição da requisição geram um único pedido efetivo |
| Pagamento | Webhook duplicado ou fora de ordem não duplica recebimento nem reabre estado terminal indevidamente |
| Preço | Alteração no navegador não reduz o valor validado pelo servidor |
| Estoque | Venda concorrente do último item respeita a política de disponibilidade; cancelamento aplica a reversão correta |
| Cozinha | Pedido só fica pronto quando as tarefas necessárias terminam; reconexão recupera situação atual |
| Salão | Divisão e pagamentos parciais fecham exatamente o saldo, inclusive arredondamento |
| Caixa | Relatório permite explicar diferenças entre vendas, recebimentos, suprimentos, sangrias e dinheiro contado |
| Campanhas | Cliente que cancela recebimento deixa de participar dos envios elegíveis seguintes |
| Integrações | Falhas podem ser diagnosticadas e reprocessadas sem gerar efeitos duplicados |
| WhatsApp multitenant | Conectar, desconectar ou receber webhook de uma instância nunca afeta ou expõe conexão de outro tenant |
| Provisionamento | Repetir conexão após timeout reconcilia a instância existente sem duplicar sessões |
| QR e WABA | QR expirado pode ser renovado; WABA só é ativada após validar autorização e vínculo do número |
| Fila de motoboys | Entregadores presentes recebem ofertas na ordem definida; candidato ausente ou ocupado é ignorado |
| Oferta aberta | Aceites simultâneos geram exatamente um vencedor e uma atribuição ativa |
| Concorrência global | Um freelancer não aceita corridas incompatíveis de dois tenants ao mesmo tempo |
| Cadastro freelancer | Entregador se cadastra na plataforma uma vez e usa o mesmo perfil em lojas autorizadas de tenants distintos |
| Tarifação | Cotação por corrida, km e taxa fixa aplica mínimos e arredondamento; valor aceito não muda com edição posterior da tabela |
| Módulo desativado | Interface, API, jobs e eventos impedem novo despacho freelancer; entrega manual e demais módulos continuam operando |
| Encerramento do módulo | Desativar revoga ofertas abertas, preserva corridas atribuídas até tratamento final e mantém histórico e acertos |
| Reoferta | Recusa, expiração, cancelamento e queda de conexão não deixam corrida atribuída a dois entregadores |
| Instalação | Equipe instala em Coolify limpo seguindo apenas a documentação e variáveis fornecidas |
| Recuperação | Restauração recupera pedidos, arquivos e segredos necessários e mede RPO/RTO reais |

Metas iniciais de desempenho: LCP do cardápio até 2,5 segundos no cenário móvel de referência; p95 de endpoints internos simples até 500 ms, sem tempo de terceiros; atualização operacional em até 3 segundos em rede estável. São objetivos a validar com teste de carga, não garantias antecipadas.

Testes críticos: isolamento, cálculo de adicionais e combos, cupons, fechamento, concorrência de estoque, pagamentos/estornos, reprocessamento, permissões e fluxos completos. Acrescentar testes de contrato para cada integração e testes reais com impressoras e dispositivos escolhidos.

## 12. Fases de entrega

### Fase 0 — Fundação e validação técnica

Definir marca provisória, fluxos e protótipos; estruturar multitenancy obrigatório e permissões; criar repositório e pipeline; subir aplicação mínima no Coolify; validar gateway, impressão, Evolution QR/WABA, requisitos Meta e APIs pretendidas. Provar aceite concorrente de corrida e precisão/atualização de presença nos dispositivos escolhidos. Entregar backlog com estimativas após essas validações.

### Fase 1 — Delivery próprio utilizável

Lojas e horários, catálogo com variações e adicionais, cardápio móvel, retirada e entrega por área, checkout, um gateway Pix/cartão, central de pedidos, pedido manual, KDS simples, expedição, caixa básico, cadastro de cliente, relatórios básicos, auditoria e backup.

Aceite: uma loja piloto recebe, cobra, prepara, entrega, cancela e concilia pedidos reais de teste sem redigitar informações. Impressão via navegador pode atender o piloto; impressão automática exige o agente previsto na fase seguinte. Se documento fiscal for obrigatório para a operação piloto, o respectivo módulo torna-se bloqueador desta fase.

### Fase 2 — Operação completa da loja

PDV aprimorado, mesas e comandas, interface de garçom, divisão de conta, KDS por estação, agente de impressão, módulo freelancer opcional por tenant/loja com cadastro na plataforma, tarifação por corrida/km/taxa fixa, check-in e fila por loja, oferta aberta regional e aceite atômico, funções dependentes liberadas por capacidade, interface do entregador, ficha técnica, estoque, compras básicas, financeiro, conciliação e provedor fiscal nos cenários definidos.

Aceite: simulação de turno completo, da abertura ao fechamento, incluindo falha de impressora, cancelamento após preparo e pagamento parcial.

### Fase 3 — Recorrência e canais externos

Evolution centralizada no admin, provisionamento por tenant, conexão QR e conexão oficial WABA conforme homologação, atendimento humano integrado, um marketplace homologado, CRM, cupons avançados, um modelo de fidelidade, campanhas e recuperação de carrinho. Liberar IA somente após catálogo, preços e ações transacionais estarem protegidos.

Aceite: integração sem duplicidade e campanha segmentada com consentimento e resultado rastreável.

### Fase 4 — Redes e expansão comercial

Multiloja e multimarcas na interface completa, gestão central de catálogo, estoque entre lojas, consolidação de indicadores, planos e cobrança SaaS, domínios próprios, totem, roteirização e demais conectores aprovados.

A estrutura de empresa/loja/marca existe desde a fundação, mesmo que a primeira interface exponha apenas uma loja. Não é necessário construir todos os recursos de rede antes de validar a operação inicial.

## 13. Prioridades e limites

**Indispensáveis no lançamento:** integridade de pedidos e pagamentos; cardápio rápido; operação simples; isolamento entre empresas; visibilidade de falhas; instalação reproduzível; backup restaurável.

**Diferenciais após estabilização:** venda direta com recorrência; custo e margem por canal; operação de múltiplas marcas; integração entre salão, cozinha e entrega.

**Fora do lançamento inicial:** aplicativo nativo Android/iOS, salvo se necessário ao módulo de despacho por limitações comprovadas da PWA; PDV offline completo; TEF amplo e certificações de hardware; frota com otimização avançada; ERP contábil, folha e RH; marketplace próprio de restaurantes; split financeiro; integração com todos os canais. A conexão Evolution por QR Code faz parte do escopo autorizado, além do modo oficial WABA.

### Exceção deliberada ao isolamento: rede de entregadores

O perfil global do freelancer e sua disponibilidade são recursos compartilhados controlados pela plataforma. Cada tenant acessa somente seus vínculos, fila, ofertas, corridas e acertos. O entregador vê as próprias ofertas e corridas; na rede aberta, informações pré-aceite são minimizadas. O mecanismo global impede conflitos de atribuição entre empresas, sem expor clientes, pedidos ou filas de concorrentes. Isso deve ter modelo de acesso e testes próprios, em vez de retirar indiscriminadamente os filtros de tenant das tabelas logísticas.

Impressão silenciosa em impressoras USB ou de rede local exige um agente local ou solução equivalente validada. O navegador e o servidor no Coolify, sozinhos, não devem ser tratados como solução universal de impressão automática. O agente deverá aceitar somente trabalhos autorizados, identificar a estação e reportar falhas.

Operação offline completa, se necessária depois, exige armazenamento local, sincronização, resolução de conflitos e política para pagamentos e fiscal; deve receber escopo próprio.

## 14. Dependências e custos externos

Prever contratação e credenciais para VPS, domínio, armazenamento e backup, gateway, WhatsApp, provedor fiscal, mapas/geocodificação e e-mail. IA e marketplaces entram conforme módulos ativados e condições comerciais.

Mesmo em servidor próprio, transações, mensagens e serviços externos podem ter tarifas. Não prometer ausência de taxas de terceiros. O lojista deve conseguir identificar esses custos e exportar seus dados de negócio.

## 15. Entregáveis do desenvolvimento

1. Protótipos dos fluxos críticos e biblioteca de componentes.
2. Modelo de dados, regras de estado, matriz de permissões e contratos de integração.
3. Código Laravel/frontend organizado por domínios, com histórico Git.
4. Migrações, importadores, dados demonstrativos e documentação da API.
5. Dockerfile, Compose, exemplo de variáveis e manual Coolify.
6. Testes críticos, relatório de homologação e teste de recuperação.
7. Guias de operação, impressão, integração, atualização e incidentes.
8. Backlog priorizado por fase, dependências e critérios de conclusão.

Prazo e orçamento devem ser calculados após protótipos e provas técnicas da fase 0. Um escopo deste porte exige entregas graduais e homologação de operação; não deve ser tratado como apenas um CRUD de cardápio.

## 16. Resultado esperado

Uma plataforma que começa vendendo e operando bem em uma loja, preserva consistência entre pedidos e dinheiro e evolui para uma solução comercial de restaurantes e redes. O núcleo é próprio em Laravel; a instalação é reproduzível no Coolify; recursos externos entram por contratos claros, sem comprometer a base do produto.

## 16.1. Integração iFood incluída no escopo

Decisão aprovada em 24/10/2026: antecipar o conector iFood para restaurantes, antes dos demais marketplaces. É um módulo próprio e incremental; a primeira configuração não equivale à integração operacional nem à homologação. A pausa do cardápio próprio não deve pausar silenciosamente a loja iFood.

1. **Conexão e identificação de lojas.** Aplicativo centralizado administrado pelo superadmin, segredo e token criptografados, renovação de token, vínculo explícito entre tenant, loja local e Merchant ID, consulta de situação e auditoria. O tenant consulta somente seus vínculos. O acesso global do aplicativo a uma loja não prova que ela pertence ao tenant solicitante; o vínculo é responsabilidade expressa do superadmin. Começar com credenciais e loja de teste. O ambiente é declarado a partir do aplicativo/loja, pois a URL da API é compartilhada; não prometer detecção automática de credenciais de produção.
2. **Pedidos e eventos.** Implementar Merchant, Order e Events conforme os contratos oficiais vigentes. Receber eventos no servidor, persistir antes de confirmar leitura, deduplicar pelo ID externo e vincular cada pedido ao Merchant ID e tenant corretos. Exibir origem iFood no painel; aceitar, preparar, informar pronto e despachar pelos endpoints aplicáveis, conciliando o resultado por evento. Tratar cancelamentos, negociações, agendados, troco, taxas e meios de pagamento. Retentativas e falhas devem ficar visíveis; um timeout não autoriza inventar confirmação. A operação não depende de manter o painel do usuário aberto.
3. **Catálogo.** Mapear IDs externos e códigos do PDV, categorias, produtos, grupos e adicionais. Definir origem de cada alteração e preço específico do canal iFood, preservando o preço do canal próprio. Implementar criação, leitura, alteração, disponibilidade, acompanhamento de lotes, conflitos e retentativas. Expandir o catálogo local antes de prometer pizzas, combos, modificadores aninhados ou múltiplos contextos. Importação inicial exige prévia e validação de correspondências para não substituir um cardápio existente indevidamente.
4. **Homologação e piloto.** Cadastro profissional elegível no portal iFood, aplicativo funcional, credenciais e loja de teste; homologar os módulos aplicáveis e obter autorização das lojas de produção. O superadmin libera produção somente após evidências de aprovação e piloto assistido. Módulo financeiro e contratação de entrega iFood são expansões separadas, com seus próprios requisitos.

Regras obrigatórias: separar pedido pago online, valores devidos à loja e dinheiro sob responsabilidade do entregador; não lançar todo pedido iFood como recebimento manual da loja. Entrega operada pelo iFood não deve abrir automaticamente corrida freelancer. Credenciais, tokens e corpos brutos de erro não podem aparecer em telas, flash de formulários ou auditoria. Rotação de credenciais invalida verificações anteriores. Revogar vínculo local não equivale a revogar autorização no portal iFood.

Critérios de aceite: dois tenants com Merchants diferentes; tentativas de acesso cruzado; repetição e inversão de eventos; reinício durante processamento; falha/expiração de token; indisponibilidade e limites da API; cancelamento externo; discrepância de pagamento; confirmação de catálogo sem duplicação. Testes simulados são necessários, mas não substituem testes reais e homologação.

Referências oficiais consultadas em 24/10/2026:
- [Autenticação centralizada](https://developer.ifood.com.br/en-US/docs/food/guides/modules/authentication/centralized)
- [Merchant: endpoints](https://developer.ifood.com.br/en-US/docs/food/guides/modules/merchant/endpoints)
- [Pedidos: fluxo](https://developer.ifood.com.br/en-US/docs/food/guides/modules/order/workflow)
- [Catálogo](https://developer.ifood.com.br/en-US/docs/food/guides/modules/catalog/introduction)
- [Política de homologação](https://developer.ifood.com.br/en-US/docs/getting-started/homologation/categories)

## 17. Soluções adotadas para operação confiável

Esta seção define a configuração inicial recomendada para implementação e prevalece sobre alternativas abertas nas seções anteriores. Parâmetros podem ser ajustados após o piloto, mantendo regras visíveis e histórico das alterações.

### 17.1. Aplicativo do entregador

Decisão atual: concluir primeiro o funcionamento web de todos os painéis e fluxos prioritários. O aplicativo Android fica adiado para uma fase posterior e deixa de ser requisito da primeira entrega web. As referências a Android, localização contínua em segundo plano e push nativo nesta seção descrevem a evolução futura, não funcionalidades já existentes.

No lançamento web do módulo freelancer, oferecer painel responsivo para cadastro, aprovação, check-in, posição na fila, ofertas, aceite, coleta, conclusão e acertos. Atualizar presença e localização automaticamente enquanto a página estiver aberta e ativa, com aviso de permissão negada, localização imprecisa, perda de conexão e presença expirada. O servidor continua decidindo elegibilidade e aceite único. Sem presença válida, suspender novas ofertas; não cancelar automaticamente corridas atribuídas. Não prometer rastreamento contínuo ou recebimento garantido de oferta com navegador fechado/celular bloqueado. Disputas, ausência de entregador e falhas devem ter tratamento no painel da loja.

O fluxo web deve funcionar integralmente sem exigir instalação de app. Para uma etapa posterior, prever aplicativo Android em Flutter, com arquitetura preparada para iOS. O backend continua em Laravel e a implantação no Coolify; aplicativo móvel será um cliente da API. A operação web não deve depender de componentes exclusivos desse aplicativo.

O aplicativo implementa serviço de localização em primeiro plano durante disponibilidade e corrida, respeitando permissões do sistema, indicação de uso e requisitos da distribuição. Push sinaliza ofertas, mas a lista de ofertas válidas vem do servidor; abrir a notificação não equivale a aceitar. Sem permissão ou sinal recente suficiente, o entregador fica indisponível para novas ofertas automáticas. A perda de localização durante corrida já atribuída gera alerta e último horário conhecido, sem cancelar automaticamente a corrida.

Transmitir atualizações em frequência adaptativa para reduzir consumo de bateria e dados. Usar inicialmente heartbeat a cada 30 segundos quando disponível; após 90 segundos sem atualização, suspender elegibilidade. Esses valores são parâmetros de piloto. Registrar operações pendentes no dispositivo e reenviar com chave idempotente ao reconectar; nenhuma entrega aparece confirmada antes do aceite do servidor.

### 17.2. Política inicial da fila

- Check-in dentro de 150 metros da loja, com localização de até 60 segundos e precisão declarada de até 50 metros. GPS incompatível exige tentativa de atualização ou liberação pontual pelo gerente, com motivo e prazo de validade; QR rotativo complementa presença, sem substituir sozinho a validação.
- Uma loja e uma corrida ativa por entregador. Não implementar agrupamento de corridas no primeiro lançamento.
- Oferta exclusiva por 30 segundos, seguindo FIFO; reserva temporária do candidato impede ofertas simultâneas incompatíveis.
- Recusa explícita move o entregador ao fim da fila para os próximos pedidos e o exclui das próximas tentativas da mesma corrida. Esta decisão substitui o padrão anterior de manter posição após recusa.
- Oferta expirada por falta de resposta suspende disponibilidade e exige confirmação do entregador para retornar ao fim da fila.
- Ao esgotar candidatos da fila, abrir oferta por 60 segundos aos elegíveis a até 3 km da loja; ampliar para 5 km por mais 60 segundos. Os raios de elegibilidade podem usar distância geográfica; a remuneração por km usa distância de rota.
- Sem aceite após a expansão, manter pedido pendente, alertar operador e cliente sobre revisão de prazo e permitir nova rodada ou despacho manual autorizado. Não marcar saída nem entrega fictícia.
- Check-in ocorrido depois de uma oferta aberta não interrompe a rodada em andamento.

O raio de check-in e o de busca aberta são configurações diferentes. Exceções e alterações de regras devem ficar visíveis na operação.

### 17.3. Aceite único e recuperação do despacho

Persistir corrida, ofertas e atribuição no PostgreSQL. A operação de aceite verifica oferta vigente, elegibilidade e disponibilidade global do entregador dentro de transação, com bloqueio em ordem consistente e restrições que impeçam atribuições ativas duplicadas. Redis auxilia presença e coordenação, mas não é a autoridade final do vencedor.

Usar identificador idempotente no aceite, relógio do servidor para expiração e outbox para publicar notificações após confirmação. Jobs de reconciliação detectam ofertas expiradas e despachos parados. Após reinício, reconstruir a operação a partir do banco; não reutilizar presença expirada. Falha no push não altera uma atribuição confirmada.

### 17.4. Remuneração e acerto no lançamento

A loja é responsável pelo pagamento do freelancer. A plataforma calcula, registra e concilia os valores; não recebe nem repassa automaticamente dinheiro dos entregadores na primeira versão. Não haverá tarifa de intermediação por corrida no lançamento; assinatura do módulo é cobrança separada.

O valor exibido e aceito fica congelado na corrida. Ganho normal é reconhecido na conclusão confirmada; ajustes exigem motivo e autorização. O extrato separa remuneração, adicional, compensação de cancelamento e numerário que o entregador recebeu do cliente.

Acerto por loja e entregador ao fim do turno ou diariamente: valor devido ao entregador menos numerário sob sua responsabilidade, com saldo e comprovante do pagamento externo. Se o saldo for negativo, mostrar valor a devolver à loja. Ambos podem consultar o demonstrativo; contestação abre ocorrência e não altera silenciosamente o lançamento original.

Política inicial de ocorrências: antes do aceite, sem remuneração; após aceite e antes da coleta, eventual compensação segue tabela expressamente configurada e apresentada; após coleta, exigir ocorrência assistida, orientação de entrega/devolução e registro do valor devido. Se não houver tabela de compensação habilitada, bloquear promessa automática de valor e encaminhar decisão ao operador autorizado. Adicional de espera só pode ser cobrado quando houver tolerância, preço e limite previamente cadastrados e exibidos. Não deixar essas tabelas implícitas no algoritmo.

### 17.5. Evolution central com continuidade

Manter uma configuração central e um servidor Evolution inicial, com instâncias segregadas por tenant. Usar filas distintas para mensagens operacionais e campanhas, quotas por empresa e limitação de concorrência para impedir monopolização do servidor.

Persistir cada intenção de envio antes de chamar a Evolution; registrar tentativas e identificador externo. Aplicar retentativas com atraso progressivo, circuit breaker e fila de falhas. Timeout ambíguo deve resultar em estado de envio desconhecido e reconciliação quando o provedor permitir; não prometer entrega exatamente uma vez nem reenviar cegamente mensagens que possam ter sido enviadas.

Se a Evolution ficar indisponível, checkout, PDV, cozinha, pagamento e despacho continuam funcionando. O cliente acompanha pelo link do pedido; o painel mostra WhatsApp indisponível e mensagens pendentes. Não trocar automaticamente QR por WABA. Ao recuperar, expirar avisos antigos e enviar apenas informação ainda útil, preservando histórico.

Preparar cadastro de múltiplos servidores Evolution no admin para expansão futura, com cada conexão vinculada a um servidor. Isso não significa failover instantâneo de sessão: migração e recuperação de sessões precisam de procedimento homologado. Backup das sessões e restauração devem ser testados na versão fixada.

### 17.6. Separação dos tenants e rede compartilhada

Pedidos, clientes, preços, mensagens e finanças pertencem a um tenant. Perfil do freelancer, disponibilidade global e mecanismo de prevenção de corrida simultânea pertencem à plataforma. Ofertas fazem a ponte por permissões explícitas, com dados mínimos antes do aceite. Loja nenhuma recebe acesso a pedidos ou filas de outra empresa.

Testes automatizados tentam acesso cruzado em endpoints, arquivos, filas, exportações e WebSockets. O piloto deve conter pelo menos dois tenants com usuários, números WhatsApp e pedidos diferentes; testar somente uma empresa não atende a homologação multitenant.

### 17.7. Ativação, encerramento e bloqueios de lançamento

Estados do módulo por tenant: desativado, ativo e em encerramento. Ativação exige loja configurada, tabela de remuneração, cobertura e responsável por acertos. Encerramento impede novas corridas, revoga ofertas sem aceite e preserva tratamento das atribuídas e valores pendentes. Nenhuma suspensão comercial deve apagar dados ou impedir o registro necessário de uma ocorrência em andamento.

Antes de liberar o módulo freelancer web em produção, demonstrar em navegadores reais de computador e celular: localização e ofertas com a página ativa, aviso de suspensão/inatividade, perda e retorno de conexão, aceites simultâneos, recuperação após reinício, conclusão e acerto financeiro. Executar piloto com duas empresas e pelo menos três entregadores. Testes de localização contínua e ofertas com tela bloqueada pertencem à futura entrega Android e não bloqueiam a versão web, que deve informar seus limites operacionais.

O início da operação integrada exige prova técnica de Evolution nos modos contratados, impressão e gateway. Dependência ainda não homologada fica explicitamente desabilitada, sem comprometer módulos já aprovados. Estas validações são entregas obrigatórias da fase 0 e critérios de liberação, não tarefas adiadas para depois do lançamento.

### 17.8. Gestão comercial de empresas e indicadores

O superadmin pode editar, suspender, reativar, excluir da operação e restaurar empresas. Exclusão lógica preserva registros financeiros e auditoria, exige confirmação do identificador e senha administrativa e impede remoção com pedidos/entregas em andamento. Suspensão bloqueia acessos e novas vendas do canal próprio; não impede conclusão de entrega já aceita nem conciliação de pagamento recebido, e não altera a disponibilidade no marketplace externo.

BI da plataforma consolida vendas dos tenants e crescimento da base, com análise individual por empresa/loja. Cada proprietário recebe somente os dados da própria empresa. Período/fuso, comparação anterior, evolução, produtos e frete, ticket médio, cancelamentos, contatos atendidos/recorrentes, melhores dias/horários, rankings e CSV diário compõem esta entrega. Vendas pagas dos restaurantes não são receita do SaaS; mensalidades/comissões dependem de lançamentos de cobrança da plataforma. Neste estágio o BI usa pedidos do cardápio próprio, sem pedidos de teste iFood.
### 17.9. Importação assistida de cardápios

Importação por loja em XLS/XLSX/CSV, identificação de colunas e abas, remapeamento, prévia e confirmação explícita. Produtos, tamanhos, sabores por preço total, adicionais e escolhas inclusas de combo têm semânticas distintas. Valores ambíguos bloqueiam a confirmação; planilhas arbitrárias não dispensam revisão humana. Histórico e prevenção de duplicação acompanham o lote; dados existentes não são sobrescritos e itens novos começam pausados.

Combos podem ser fechados ou ter grupos de escolhas próprias, além de componentes vinculados a produtos da mesma loja. Fotos principais de fontes compatíveis são processadas por fila. Fotos de opções, repetição da mesma escolha, categorias secundárias e resgates de fidelidade não são migrados automaticamente nesta etapa; limites aparecem na prévia. A validação de Grano Vivo usa 54 produtos e 114 linhas de opções da planilha fornecida.
### 17.10. Ocorrências e comprovantes na logística web

Central de entregas por loja/situação, histórico de ocorrências com autoria e solução, descrição e foto privada opcional. Motoboy atribuído registra em corrida ativa; proprietário registra também falta de motoboy antes do aceite. Registrar ou resolver ocorrência não modifica automaticamente estado, pagamento ou acerto. Suspensão comercial e encerramento do módulo preservam o tratamento da corrida aceita.

Conclusão pode conter nome de recebedor e foto opcional, restritos ao proprietário e ao motoboy responsável. Esse registro declaratório não substitui o futuro código de confirmação do cliente. Arquivos passam por validação/conversão e autorização por empresa/corrida, sem exposição pública. PIN e alertas de ocorrências em tempo real permanecem no escopo posterior.

### 17.11. Retorno e troca de motoboy

Antes da coleta, o proprietário confirma que o pacote permanece na loja, informa motivo/remuneração e libera o motoboy. Depois da coleta, loja ou motoboy solicitam retorno; a reserva só é liberada quando a loja confirma recebimento físico, conferência e valores. Cada tentativa encerrada preserva proposta, remuneração combinada, dinheiro declarado e autoria, com acerto externo individual auditado. Valores do cliente e remuneração são conferidos separadamente, sem compensação, transferência ou estorno automáticos.

Dinheiro declarado no retorno exige conferência do pagamento antes de nova busca. Recebimento parcial não quita o pedido; o total original permanece. Cancelar o pedido devolvido preserva o histórico da entrega. A nova busca é explícita, mantém a cotação e invalida ofertas de rodadas anteriores; motoboy anterior acessa apenas seu resumo sem dados da entrega seguinte. Alteração de cotação entre tentativas e conciliação automática de saldos parciais são evoluções posteriores.
