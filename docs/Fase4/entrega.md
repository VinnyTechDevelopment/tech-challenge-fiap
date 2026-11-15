# Tech Challenge — Fase 4 · Documento de entrega

> Rascunho do PDF entregue no portal. Preencha os campos marcados com ✏️ e exporte para PDF. O diagrama Mermaid renderiza no GitHub; para o PDF, use a imagem exportada de [arquitetura.md](arquitetura.md).

## 1. Participantes

| Nome | RM | Discord / e-mail |
|---|---|---|
| ✏️ | ✏️ | ✏️ |
| ✏️ | ✏️ | ✏️ |

## 2. Repositórios

| Repositório | Papel |
|---|---|
| https://github.com/VinnyTechDevelopment/tech-challenge-fiap | **os-service**: Ordens de Serviço + orquestrador da Saga (MySQL) |
| https://github.com/VinnyTechDevelopment/billing-service-fiap | **billing-service**: orçamento e pagamento Mercado Pago (MySQL) |
| https://github.com/VinnyTechDevelopment/execution-service-fiap | **execution-service**: execução, estoque e catálogo (DynamoDB) |
| https://github.com/VinnyTechDevelopment/lambda-auth-cpf-fiap | API Gateway, Lambda de autenticação por CPF e authorizer |
| https://github.com/VinnyTechDevelopment/infra-kubernetes-fiap | VPC, EKS e New Relic do cluster |
| https://github.com/VinnyTechDevelopment/infra-database-fiap | RDS do os-service |

## 3. Vídeo de demonstração

✏️ Link (YouTube/Vimeo, não listado):

## 4. Arquitetura final

Diagrama completo, com microsserviços, bancos e comunicação: [arquitetura.md](arquitetura.md).

- 3 microsserviços Laravel no **EKS**, cada um em namespace próprio, com HPA e NLB.
- **Um banco por serviço:** RDS MySQL (os-service), RDS MySQL (billing-service), DynamoDB (execution-service). Nenhum serviço acessa o banco de outro; a Lambda de CPF usa um endpoint interno do os-service.
- **Comunicação:** REST síncrono (consulta ao catálogo na abertura da OS) e **SQS** assíncrono para a saga (filas `os-saga-replies`, `billing-commands`, `execution-commands`, cada uma com DLQ).
- **API Gateway** como porta única, com Lambda authorizer (JWT de cliente ou de funcionário) e rotas públicas explícitas (login, links de orçamento, webhook do Mercado Pago).
- **New Relic:** APM, distributed tracing entre os serviços, logs, Kubernetes e dashboards como código.

## 5. Estratégia do Saga Pattern

**Saga orquestrada**, com orquestrador no os-service. Justificativa completa em [adrs/001-saga-orquestrada.md](adrs/001-saga-orquestrada.md).

- **Fluxo:** abrir OS → gerar orçamento (billing) → aprovação do cliente → pagamento Mercado Pago → reserva de peças e fila (execution) → diagnóstico/reparo → finalizada → entregue.
- **Compensações:** orçamento recusado → OS cancelada; pagamento recusado/expirado → cancelar orçamento; falta de peças → **estorno** no Mercado Pago; cancelamento durante a execução → devolver peças + estorno; pagamento atrasado → estorno automático.
- **Garantias:** outbox transacional, idempotência por `message_id`, lock por saga, mensagens fora de ordem tratadas, DLQ e timeouts.
- **Por que orquestração:** fluxo linear com compensações específicas por etapa, regra explícita num único lugar, estado e histórico por OS, timeouts centralizados e rastreamento direto. A coreografia espalharia o fluxo e as compensações por 3 repositórios.

## 6. Justificativa da divisão dos microsserviços e das tecnologias

| Serviço | Área do negócio | Banco e motivo |
|---|---|---|
| os-service | Atendimento: OS, clientes, veículos, status e histórico | MySQL: dados relacionais e transação ACID para estado da saga + outbox |
| billing-service | Cobrança: orçamento, pagamento e estorno | MySQL: consistência forte para valores monetários e auditoria |
| execution-service | Diagnóstico e execução: fila, estoque e catálogo | DynamoDB: acesso por chave/índice, sem joins, reserva atômica com `TransactWriteItems` |

Tecnologias: Laravel (DDD, reaproveita o domínio das fases anteriores), EKS + Terraform (IaC por repositório), SQS (gerenciado, com DLQ e compatível com o AWS Academy), Mercado Pago (Checkout Pro + webhook + refunds), New Relic (ferramenta de observabilidade da Fase 3), PHPUnit + Behat + SonarCloud (qualidade), GitHub Actions (CI/CD independente por serviço, `main` protegida).

## 7. Qualidade (evidências)

| Serviço | Testes | Cobertura de linhas | BDD |
|---|---|---|---|
| os-service | 209 | 93,8% | 5 cenários / 58 passos (fluxo completo + compensações) |
| billing-service | 64 | 96,3% | — |
| execution-service | 170 | 98,0% | — |

O CI de cada serviço falha abaixo de 80% de cobertura (`scripts/coverage-gate.php`) e aplica o quality gate do SonarCloud.
