# ADR 001 (Fase 4): Saga **orquestrada** com SQS e outbox transacional

- **Status:** aceito
- **Contexto da decisão:** Fase 4 — separação em microsserviços (os-service, billing-service, execution-service)

## Contexto

A abertura de uma OS virou uma transação distribuída entre três serviços, cada um com o próprio banco:

| Etapa | Serviço | Banco |
|---|---|---|
| Abrir OS, histórico de status | os-service | MySQL (RDS) |
| Orçamento, aprovação, pagamento (Mercado Pago), estorno | billing-service | MySQL (RDS próprio) |
| Reserva de peças, fila de execução, diagnóstico/reparo | execution-service | DynamoDB |

Sem transação ACID entre bancos, uma falha no meio do fluxo deixaria estados inconsistentes. Exemplos: a OS paga sem peças em estoque, ou peças reservadas para uma OS cancelada. O PDF da fase exige o **Saga Pattern** com rollback e compensação em qualquer etapa.

## Decisão

**Saga orquestrada**, com o orquestrador dentro do **os-service** (`App\Application\Saga\ServiceOrderSagaOrchestrator`), comunicação assíncrona via **Amazon SQS** e **outbox transacional**.

### Fluxo

```
abrir OS ──CreateQuote──▶ billing ──QuoteCreated──▶ os (aguardando_aprovacao, e-mail com links)
cliente aprova ─────────▶ billing ──QuoteApproved─▶ os (aguardando_pagamento, link Mercado Pago)
webhook Mercado Pago ───▶ billing ─PaymentConfirmed▶ os ──ReservePartsAndEnqueue──▶ execution
execution ──ExecutionQueued / StatusChanged / Finished──▶ os (aguardando_execucao → em_diagnostico → em_execucao → finalizada)
```

### Compensações

| Falha | Compensação enviada pelo orquestrador | Resultado |
|---|---|---|
| Billing não consegue gerar o orçamento | — | OS `cancelada` |
| Cliente recusa o orçamento | — (billing já registrou a recusa) | OS `cancelada` |
| Pagamento recusado | `billing.CancelQuote` | OS `cancelada` após `QuoteCancelled` |
| Prazo de aprovação/pagamento expirou (`saga:timeouts`) | `billing.CancelQuote` | OS `cancelada` |
| Sem peças em estoque | `billing.RefundPayment` (estorno no Mercado Pago) | OS `cancelada` após `PaymentRefunded` |
| Atendimento cancela antes do pagamento | `billing.CancelQuote` | OS `cancelada` |
| Atendimento cancela durante a execução | `execution.CancelExecution` (devolve peças) + `billing.RefundPayment` | OS `cancelada` após **as duas** confirmações |
| Pagamento chega depois da compensação | billing estorna sozinho e avisa (`PaymentRefunded{late_payment}`) | auditoria no histórico |
| Estorno falha no provedor | — | saga `FAILED` (alerta no New Relic, ação manual) |

O estado da saga fica em `service_order_sagas` (QUOTE_REQUESTED, AWAITING_APPROVAL, AWAITING_PAYMENT, EXECUTION_REQUESTED, IN_EXECUTION, COMPLETED, COMPENSATING, CANCELLED, FAILED). Cada passo e cada compensação é registrado em `saga_steps`, consultável em `GET /api/service-order/{id}/history`.

## Por que orquestração, e não coreografia

| Critério | Orquestração (escolhida) | Coreografia |
|---|---|---|
| Visibilidade do fluxo | Um único lugar descreve a ordem dos passos e as compensações | O fluxo fica implícito nas assinaturas de eventos de 3 repositórios |
| Compensações | Explícitas, com estado `COMPENSATING` aguardando confirmações (inclusive duas em paralelo) | Cada serviço precisa saber a quem compensar, o que acopla os serviços aos eventos uns dos outros |
| Timeouts | O orquestrador sabe onde a saga parou e expira pelo prazo | Exige um "observador" extra |
| Rastreamento / demonstração | `saga_steps` + evento `SagaStep` no New Relic mostram tudo por OS | Correlação manual entre logs de vários serviços |
| Acoplamento | Os participantes só conhecem os próprios comandos e respondem na fila do orquestrador | Menor acoplamento ao orquestrador, porém maior entre os participantes |
| Ponto único de falha | Mitigado: o orquestrador é stateless (estado no MySQL), roda com HPA, e as mensagens ficam no SQS até ele voltar | Não há |

Com três participantes e um fluxo de negócio essencialmente **linear e com compensações específicas por etapa** (orçamento → pagamento → execução), a orquestração deixa a regra de negócio explícita e testável. Isso aparece no BDD (`features/saga_ordem_servico.feature`) e na demonstração em vídeo.

## Garantias de consistência implementadas

- **Outbox transacional** (os-service e billing): o novo estado e a mensagem a enviar são gravados na mesma transação MySQL (`outbox_messages`). O `outbox:relay` publica no SQS em ordem (`sequence`). Se o banco faz rollback, nenhuma mensagem sai; se o SQS está fora, nada se perde.
- **Idempotência / inbox** (`processed_messages`): o SQS entrega *at-least-once*. Cada `message_id` é processado uma única vez, na mesma transação do handler. No execution-service (DynamoDB), os handlers também são idempotentes por OS: reserva duplicada não baixa estoque duas vezes.
- **Lock por saga** (`SELECT … FOR UPDATE`): duas respostas simultâneas da mesma saga não se atropelam.
- **Mensagens fora de ordem** (SQS Standard): o orquestrador aplica os status intermediários (ex.: `ExecutionFinished` antes de `StatusChanged`) e ignora, registrando, mensagens atrasadas.
- **Reserva atômica de estoque** (DynamoDB `TransactWriteItems` com `ConditionExpression stock_quantity = :previous`): todas as peças ou nenhuma, com retry em caso de concorrência.
- **DLQ**: após 5 falhas de processamento, a mensagem vai para a fila `-dlq` e aparece no dashboard do New Relic.

## Por que SQS (e não RabbitMQ/Kafka)

- Serviço gerenciado, que funciona no AWS Academy usando a `LabRole` dos nodes do EKS (sem IRSA). Não há broker stateful para operar dentro do cluster.
- DLQ e long polling nativos; custo desprezível no volume do projeto.
- LocalStack reproduz o SQS no ambiente local (`docker-compose.microservices.yml`).
- Kafka seria superdimensionado: não precisamos de replay de stream nem de ordenação por partição, e a ordem relevante é tratada pelo orquestrador.

## Consequências

- O os-service concentra a regra do fluxo; mudanças no fluxo acontecem num lugar só.
- Os participantes ficam simples: recebem um comando e respondem com sucesso ou falha.
- A abertura da OS responde **202 Accepted**: o restante do fluxo é assíncrono, e o status é consultado por `GET /service-order/{id}` ou `/history`.
