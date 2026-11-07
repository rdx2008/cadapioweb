# Histórico do CadapioWeb

## 2026-11-02 — API do entregador para o aplicativo Android

- Laravel Sanctum com token por dispositivo; login restrito a entregadores habilitados; sair e redefinição de senha revogam tokens e dispositivos.
- Rotas `/api/v1/courier`: perfil, lojas, painel com presença codificada, heartbeat, presença, offline, aceite/recusa, detalhe e avanço de corrida, retorno, ocorrências, extrato e registro de dispositivo para push.
- Regras de presença, fila e conclusão extraídas para serviços compartilhados entre painel web e API; retentativas idempotentes de etapa pela API.
- Aplicativo Flutter e provedor de push continuam pendentes.
- Limites de requisições com contador próprio por rota: a atualização automática dos painéis (pedidos, cozinha, alertas, iFood, operação, entregador, acompanhamento do cliente) não consome mais o limite das ações do mesmo usuário ou endereço.

## 2026-10-24 — Fundação funcional

### Segunda entrega — venda e produção web

- Edição de catálogo, fotos, horários semanais e pausa de loja.
- Adicionais obrigatórios/opcionais com preços preservados no pedido.
- Regiões/frete, pedido mínimo e revisão do checkout antes da confirmação.
- Filtros, detalhes privados e atualização automática de pedidos e acompanhamento.
- Cancelamento com motivo e entrada monetária em reais.
- Android permanece adiado.

- Implementação inicial Laravel multitenant, catálogo, pedidos, produção e módulo freelancer.
- Evolution central com provisionamento QR e configuração isolada por loja.
- Pacote Coolify e testes de regras críticas.
- Recursos pendentes e limites descritos no README e escopo em docs/escopo.md.
