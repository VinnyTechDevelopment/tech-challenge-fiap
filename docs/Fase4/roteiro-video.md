# Roteiro do vídeo de demonstração (até 15 min)

> Upload no YouTube/Vimeo como **não listado**. Cada bloco abaixo cobre um item exigido no PDF da fase.

| Tempo | Bloco | O que mostrar |
|---|---|---|
| 0:00–1:30 | **Abertura e arquitetura** | Participantes. Diagrama de [arquitetura.md](arquitetura.md): 3 microsserviços, banco próprio de cada um (2× MySQL + DynamoDB), SQS, API Gateway, New Relic. Em uma frase: por que a Saga é orquestrada. |
| 1:30–2:30 | **Repositórios e independência** | Os 3 repos na org; em cada um: `infra/` (Terraform próprio), `k8s/`, `openapi.yaml`, workflows. Branch `main` protegida (Settings → Branches: PR obrigatório + checks). |
| 2:30–7:30 | **Fluxo completo de uma OS** (ambiente EKS via API Gateway, ou local) | 1) login do atendente; 2) `POST /service-order` → **202**, saga `QUOTE_REQUESTED`; 3) `GET /service-order/{id}` → `aguardando_aprovacao` + `approval_url` (gerado pelo billing via SQS); 4) abrir o link de aprovação → `payment_url` do **Mercado Pago (sandbox)**; 5) pagar com cartão de teste → webhook → `aguardando_execucao`; 6) no execution-service: fila (`/execution/queue`), estoque baixado; 7) `diagnosis` → `repair` → `finish`; 8) entrega; 9) `GET /service-order/{id}/history`: histórico de status e passos da saga. |
| 7:30–10:00 | **Saga e tratamento de falhas** | Cenário **sem peças**: abrir OS com quantidade maior que o estoque, aprovar e pagar → `PartsReservationFailed` → `RefundPayment` → estorno no Mercado Pago → OS `cancelada`; mostrar `saga_steps` com `compensation:refund_payment:compensated` e o estoque intacto. Opcional: cancelamento pelo atendimento durante a execução (duas compensações em paralelo) ou pagamento recusado. Mostrar no código o `ServiceOrderSagaOrchestrator` e o teste BDD (`features/saga_ordem_servico.feature`). |
| 10:00–12:30 | **Deploy automatizado com validação de testes** | Abrir um PR pequeno no billing-service (ou execution) → checks: Pint, **Testes + cobertura (>= 80%)** com resumo de cobertura, **SonarCloud** quality gate, kubeconform, build. Merge → `build-ghcr` → `deploy-eks` (`terraform apply`) → `kubectl get pods -n billing` com a nova versão. |
| 12:30–14:30 | **Monitoramento e rastreamento distribuído** | New Relic: (a) **Distributed tracing**: um trace da OS passando por os-service → billing → execution (headers propagados nas mensagens SQS); (b) dashboard **"Saga de Ordens de Serviço"**: sagas concluídas/canceladas, passos por desfecho, latência de consumo, falhas de mensagens; (c) Kubernetes (nri-bundle): pods dos 3 namespaces; (d) logs in context. |
| 14:30–15:00 | Encerramento | Recapitular os requisitos atendidos. |

## Dicas de gravação

- Deixe abas prontas: API Gateway (Bruno/Postman), Mercado Pago sandbox, GitHub Actions, New Relic e terminal com `kubectl`.
- Para o cenário de falta de peças, cadastre antes uma peça com estoque 1.
- Prazos curtos de saga para demonstrar o timeout: `SAGA_APPROVAL_TIMEOUT_MINUTES=2`.
- Localmente, `php scripts/e2e-saga.php` executa e narra os dois cenários (útil como backup).
