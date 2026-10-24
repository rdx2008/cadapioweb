# Estado e prioridades da versão web

Atualizado em 24/10/2026 após a segunda entrega web, branch `feat/platform-foundation`.

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
