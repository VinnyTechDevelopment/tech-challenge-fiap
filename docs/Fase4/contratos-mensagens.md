# Contratos de mensagens (SQS)

As constantes ficam em `app/Application/Messaging/MessageTypes.php`, com o mesmo conteúdo nos três repositórios.

## Envelope

```json
{
  "message_id": "0b6c…",
  "type": "billing.CreateQuote",
  "saga_id": "5f1e…",
  "correlation_id": "5f1e…",
  "source": "os-service",
  "occurred_at": "2026-11-04T14:00:00-03:00",
  "trace_headers": { "traceparent": "00-…", "newrelic": "…" },
  "payload": { "service_order_id": "…" }
}
```

- `message_id`: chave de idempotência (`processed_messages`).
- `saga_id`: identifica a saga. As respostas usam o mesmo `saga_id` e `correlation_id` do comando.
- `trace_headers`: contexto de distributed tracing do New Relic, aceito pelo consumidor, para que o trace atravesse os serviços.

## Filas

O nome físico é `<ambiente>-<fila>`, por exemplo `production-billing-commands`. Cada fila tem uma DLQ `-dlq`, com `maxReceiveCount = 5`.

| Fila | Dono (consome) | Quem publica |
|---|---|---|
| `os-saga-replies` | os-service | billing-service, execution-service |
| `billing-commands` | billing-service | os-service |
| `execution-commands` | execution-service | os-service |

## Comandos (os-service → participantes)

| Tipo | Fila | Payload |
|---|---|---|
| `billing.CreateQuote` | billing-commands | `service_order_id`, `customer_id`, `services[] {service_id, name, quantity, unit_price}`, `items[] {item_id, name, quantity, unit_price}` |
| `billing.CancelQuote` | billing-commands | `service_order_id`, `quote_id`, `reason` |
| `billing.RefundPayment` | billing-commands | `service_order_id`, `payment_id`, `reason` |
| `execution.ReservePartsAndEnqueue` | execution-commands | `service_order_id`, `services[] {service_id, name, quantity}`, `items[] {item_id, quantity}` |
| `execution.CancelExecution` | execution-commands | `service_order_id`, `reason` |

## Respostas e eventos (participantes → os-service)

| Tipo | Origem | Payload principal | Efeito no orquestrador |
|---|---|---|---|
| `billing.QuoteCreated` | billing | `quote_id`, `total`, `approval_url`, `reject_url` | AWAITING_APPROVAL, OS `aguardando_aprovacao`, e-mail |
| `billing.QuoteCreationFailed` | billing | `reason` | CANCELLED |
| `billing.QuoteApproved` | billing | `quote_id`, `payment_id`, `payment_url`, `amount` | AWAITING_PAYMENT, OS `aguardando_pagamento`, e-mail |
| `billing.QuoteRejected` | billing | `quote_id` | CANCELLED |
| `billing.PaymentConfirmed` | billing | `payment_id`, `provider_payment_id`, `amount` | EXECUTION_REQUESTED, envia `ReservePartsAndEnqueue` |
| `billing.PaymentFailed` | billing | `reason` | COMPENSATING, envia `CancelQuote` |
| `billing.QuoteCancelled` | billing | `quote_id`, `status` | confirma compensação `cancel_quote` |
| `billing.PaymentRefunded` | billing | `payment_id`, `amount`, `late_payment?` | confirma compensação `refund_payment` (ou só auditoria se `late_payment`) |
| `billing.PaymentRefundFailed` | billing | `reason` | FAILED (ação manual) |
| `execution.ExecutionQueued` | execution | `status`, `queued_at` | IN_EXECUTION, OS `aguardando_execucao` |
| `execution.PartsReservationFailed` | execution | `reason` | COMPENSATING, envia `RefundPayment` |
| `execution.ExecutionStatusChanged` | execution | `status` (`in_diagnosis`/`in_repair`), `notes` | OS `em_diagnostico` / `em_execucao` |
| `execution.ExecutionFinished` | execution | `notes` | COMPLETED, OS `finalizada` |
| `execution.ExecutionCancelled` | execution | `released[]` | confirma compensação `cancel_execution` |
