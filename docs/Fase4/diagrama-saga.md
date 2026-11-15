# Saga da Ordem de Serviço — diagramas de sequência

## Caminho feliz

```mermaid
sequenceDiagram
    autonumber
    actor At as Atendente
    actor Cl as Cliente
    participant OS as os-service<br/>(orquestrador)
    participant EX as execution-service
    participant Q as SQS
    participant BI as billing-service
    participant MP as Mercado Pago

    At->>OS: POST /api/service-order
    OS->>EX: POST /api/catalog/lookup (REST síncrono)
    EX-->>OS: nomes e preços
    OS->>OS: grava OS (recebida) + saga + outbox [1 transação]
    OS-->>At: 202 Accepted (saga QUOTE_REQUESTED)
    OS->>Q: billing.CreateQuote (outbox:relay)
    Q->>BI: CreateQuote
    BI->>BI: grava orçamento + outbox
    BI->>Q: billing.QuoteCreated (links de aprovação)
    Q->>OS: QuoteCreated → OS aguardando_aprovacao, e-mail ao cliente
    Cl->>BI: GET /api/billing/quotes/{token}/approve
    BI->>MP: POST /checkout/preferences
    MP-->>BI: init_point (link de pagamento)
    BI->>Q: billing.QuoteApproved
    Q->>OS: QuoteApproved → OS aguardando_pagamento, e-mail com link
    Cl->>MP: paga
    MP->>BI: webhook (x-signature) → GET /v1/payments/{id}
    BI->>Q: billing.PaymentConfirmed
    Q->>OS: PaymentConfirmed
    OS->>Q: execution.ReservePartsAndEnqueue
    Q->>EX: reserva peças (TransactWriteItems) + entra na fila
    EX->>Q: execution.ExecutionQueued
    Q->>OS: OS aguardando_execucao
    Note over EX: mecânico: PATCH diagnosis → repair → finish
    EX->>Q: ExecutionStatusChanged / ExecutionFinished
    Q->>OS: em_diagnostico → em_execucao → finalizada (saga COMPLETED)
    At->>OS: PATCH /status {entregue}
```

## Compensação: sem peças em estoque (estorno)

```mermaid
sequenceDiagram
    autonumber
    participant OS as os-service
    participant Q as SQS
    participant EX as execution-service
    participant BI as billing-service
    participant MP as Mercado Pago

    OS->>Q: execution.ReservePartsAndEnqueue
    Q->>EX: reserva
    EX->>EX: TransactWriteItems falha (estoque insuficiente)<br/>nada é gravado
    EX->>Q: execution.PartsReservationFailed
    Q->>OS: saga COMPENSATING (pendente: refund_payment)
    OS->>Q: billing.RefundPayment
    Q->>BI: estorno
    BI->>MP: POST /v1/payments/{id}/refunds (idempotency key)
    BI->>Q: billing.PaymentRefunded
    Q->>OS: saga CANCELLED → OS cancelada (motivo registrado)
```

## Compensação: cancelamento durante a execução (duas compensações em paralelo)

```mermaid
sequenceDiagram
    autonumber
    actor At as Atendente
    participant OS as os-service
    participant EX as execution-service
    participant BI as billing-service

    At->>OS: POST /api/service-order/{id}/cancel
    OS->>OS: saga COMPENSATING [cancel_execution, refund_payment]
    par
        OS->>EX: execution.CancelExecution
        EX->>EX: devolve peças ao estoque, job cancelled
        EX-->>OS: ExecutionCancelled
    and
        OS->>BI: billing.RefundPayment
        BI-->>OS: PaymentRefunded
    end
    OS->>OS: todas confirmadas → saga CANCELLED, OS cancelada
```

## Outras falhas tratadas

- **Orçamento recusado / falha ao gerar orçamento:** OS cancelada sem compensação (nada a desfazer).
- **Pagamento recusado ou prazo expirado** (`saga:timeouts`, a cada minuto): `CancelQuote` e, após confirmação, OS cancelada.
- **Pagamento aprovado depois da compensação:** o billing estorna automaticamente e envia `PaymentRefunded{late_payment: true}`, registrado no histórico.
- **Estorno recusado pelo provedor:** saga `FAILED`, evento `SagaCompensationFailed` no New Relic para ação manual.
