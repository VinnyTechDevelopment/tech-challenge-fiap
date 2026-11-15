# os-service — Ordens de Serviço (Tech Challenge FIAP)

[![Laravel CI](https://github.com/VinnyTechDevelopment/tech-challenge-fiap/actions/workflows/laravel-ci.yml/badge.svg)](https://github.com/VinnyTechDevelopment/tech-challenge-fiap/actions/workflows/laravel-ci.yml)
[![Quality Gate](https://sonarcloud.io/api/project_badges/measure?project=VinnyTechDevelopment_tech-challenge-fiap&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=VinnyTechDevelopment_tech-challenge-fiap)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=VinnyTechDevelopment_tech-challenge-fiap&metric=coverage)](https://sonarcloud.io/summary/new_code?id=VinnyTechDevelopment_tech-challenge-fiap)

Microsserviço de **Ordens de Serviço** da oficina: clientes, veículos, abertura e acompanhamento da OS, notificações, autenticação de funcionários e o **orquestrador da Saga** que coordena o billing-service e o execution-service.

## Fase 4 — microsserviços e Saga Pattern

A aplicação foi dividida em **3 microsserviços independentes**, cada um com repositório, infraestrutura (Terraform), banco de dados e pipeline de CI/CD próprios:

| Microsserviço | Repositório | Banco | Responsabilidade |
|---|---|---|---|
| **os-service** (este) | `tech-challenge-fiap` | MySQL (RDS) | OS (abertura, status, histórico), clientes, veículos, auth de funcionários, **orquestrador da Saga** |
| **billing-service** | [`billing-service-fiap`](https://github.com/VinnyTechDevelopment/billing-service-fiap) | MySQL (RDS próprio) | Orçamento, aprovação, pagamento **Mercado Pago**, estorno |
| **execution-service** | [`execution-service-fiap`](https://github.com/VinnyTechDevelopment/execution-service-fiap) | **DynamoDB** (NoSQL) | Catálogo, estoque, fila de execução, diagnóstico/reparo |

A infraestrutura compartilhada continua em [`infra-kubernetes-fiap`](https://github.com/VinnyTechDevelopment/infra-kubernetes-fiap) (VPC, EKS e New Relic do cluster), [`infra-database-fiap`](https://github.com/VinnyTechDevelopment/infra-database-fiap) (RDS deste serviço) e [`lambda-auth-cpf-fiap`](https://github.com/VinnyTechDevelopment/lambda-auth-cpf-fiap) (API Gateway, que agora roteia para os 3 serviços, Lambda de CPF e authorizer).

- **Arquitetura e justificativa da divisão/tecnologias:** [docs/Fase4/arquitetura.md](docs/Fase4/arquitetura.md)
- **Saga (decisão e justificativa):** [docs/Fase4/adrs/001-saga-orquestrada.md](docs/Fase4/adrs/001-saga-orquestrada.md)
- **Diagramas de sequência (caminho feliz e compensações):** [docs/Fase4/diagrama-saga.md](docs/Fase4/diagrama-saga.md)
- **Contratos das mensagens SQS:** [docs/Fase4/contratos-mensagens.md](docs/Fase4/contratos-mensagens.md)

### Saga orquestrada — por que e como

Escolhemos uma **Saga orquestrada**, com o orquestrador neste serviço (`app/Application/Saga/ServiceOrderSagaOrchestrator.php`), e não uma coreografia. O fluxo da OS é linear (orçamento → aprovação → pagamento → execução) e cada etapa tem uma compensação específica. Com um orquestrador central:

- a ordem dos passos e as compensações ficam explícitas num lugar só e testadas (PHPUnit + BDD);
- o estado de cada saga é persistido (`service_order_sagas`), com histórico de passos (`saga_steps`) e timeouts (`saga:timeouts`);
- o rastreamento por OS fica direto: `GET /api/service-order/{id}/history`, evento `SagaStep` e dashboard no New Relic.

```
abrir OS ─CreateQuote─▶ billing ─QuoteCreated─▶ (cliente aprova) ─QuoteApproved─▶ (Mercado Pago) ─PaymentConfirmed─▶
        ─ReservePartsAndEnqueue─▶ execution ─ExecutionQueued / StatusChanged / Finished─▶ OS finalizada
```

| Falha | Compensação |
|---|---|
| orçamento recusado / falha ao gerar | OS cancelada |
| pagamento recusado ou prazo expirado | `billing.CancelQuote` → OS cancelada |
| **falta de peças no estoque** | `billing.RefundPayment` (estorno Mercado Pago) → OS cancelada |
| cancelamento pelo atendimento durante a execução | `execution.CancelExecution` (devolve peças) + `billing.RefundPayment` → OS cancelada |
| pagamento chega após a compensação | billing estorna sozinho (`late_payment`) |

Consistência: **outbox transacional** (estado + mensagem na mesma transação), **idempotência** por `message_id`, **lock por saga**, tratamento de **mensagens fora de ordem** e **DLQ** após 5 falhas. Detalhes no ADR.

### Comunicação

- **Assíncrona (Amazon SQS):** todos os passos da saga. Filas `os-saga-replies` (este serviço consome), `billing-commands` e `execution-commands`.
- **Síncrona (REST):** `POST execution-service/api/catalog/lookup` ao abrir a OS (valida e precifica serviços/peças); a Lambda de CPF consulta `GET /api/internal/customers/by-document/{cpf}`. **Nenhum serviço acessa o banco de outro.**

### Endpoints principais (Fase 4)

| Método | Rota | Descrição |
|---|---|---|
| POST | `/api/service-order` | Abre a OS e inicia a saga (**202 Accepted**) |
| GET | `/api/service-order/{id}` | OS + estado da saga |
| GET | `/api/service-order/{id}/history` | Histórico de status + passos da saga (inclui compensações) |
| POST | `/api/service-order/{id}/cancel` | Cancela disparando as compensações |
| PATCH | `/api/service-order/{id}/status` | Registra a entrega (`finalizada → entregue`) |
| GET | `/api/customer/me/service-orders` | Cliente (JWT por CPF) consulta as próprias OS |

Contrato completo: [`openapi.yaml`](openapi.yaml) (Swagger UI em `http://localhost:8082`). Coleção **Bruno** em [`bruno/`](bruno/TechChallengeAuth), com o fluxo completo pelos 3 serviços.

### Rodando os 3 microsserviços localmente

Com os repositórios clonados lado a lado (`tech-challenge-fiap`, `billing-service-fiap`, `execution-service-fiap`):

```bash
docker compose -f docker-compose.microservices.yml up -d --build
# os-service :8080 | execution-service :8083 | billing-service :8084 | LocalStack (SQS + DynamoDB) :4566

# Teste ponta a ponta: fluxo completo + compensação (estorno por falta de peças)
php scripts/e2e-saga.php
```

O pagamento local usa o gateway `fake`: o link de pagamento é o endpoint `/simulate` do billing. Para o sandbox do Mercado Pago, veja o README do billing-service.

### Testes e qualidade (Fase 4)

| Verificação | Ferramenta | Resultado |
|---|---|---|
| Testes unitários + integração | PHPUnit (MySQL real no CI) | 209 testes |
| **Cobertura de linhas** | PCOV + `scripts/coverage-gate.php` (falha o CI abaixo de 80%) | **93,8%** |
| **BDD — fluxo completo da OS** | Behat, [`features/saga_ordem_servico.feature`](features/saga_ordem_servico.feature) | 5 cenários / 58 passos |
| Qualidade estática | SonarCloud (quality gate no PR) + Laravel Pint | — |
| E2E entre os 3 serviços | `scripts/e2e-saga.php` (LocalStack + 2 MySQL + DynamoDB) | fluxo feliz + compensação |

```bash
make test        # PHPUnit (container + MySQL efêmero)
make coverage    # gera coverage.xml
vendor/bin/behat # BDD (mesmo banco de testes)
```

A evidência de cobertura de cada build fica no **resumo do job "Testes + cobertura"** no GitHub Actions e nos badges do SonarCloud acima.

### CI/CD

Cada push ou PR roda `.github/workflows/laravel-ci.yml`, com os jobs que são *required checks* da `main` protegida:

1. **Lint (Pint)**
2. **Testes + cobertura (>= 80%)**: PHPUnit, gate de cobertura e BDD (Behat)
3. **Qualidade (SonarCloud)**: quality gate
4. **Manifestos Kubernetes**: `kubeconform` em [`k8s/`](k8s)
5. **Build da imagem**

Em push para `main`/`homologacao`: `build-ghcr.yml` publica a imagem → `deploy-eks.yml` roda `terraform apply` no EKS (namespace, ConfigMap/Secret, Job de migration, Deployment da API com HPA, **Deployment worker** com consumer/outbox/scheduler, fila SQS e dashboards New Relic).

---

## Descrição da solução e objetivos da fase 2

Esta entrega consolida uma API Laravel com autenticação JWT e duas estratégias de execução:

- Ambiente local com Docker Compose para desenvolvimento e testes rápidos.
- Ambiente Kubernetes (Minikube) provisionado via Terraform para validar deploy, escalabilidade e operação em cluster.

Objetivos principais desta fase:

- Padronizar build e publicação de imagem Docker para o GHCR.
- Automatizar deploy e infraestrutura Kubernetes usando Terraform.
- Garantir rastreabilidade de mudanças por tags de imagem e pipeline CI/CD.
- Manter documentação executável para setup local e execução em cluster.

## Guia rápido desta documentação

- Execução local: veja a seção `Início rápido (do zero)` e `Configuração do ambiente`.
- Deploy em Kubernetes: veja a seção `Ambiente Kubernetes (EKS + Terraform)`.
- Provisionamento com Terraform: veja a seção `Passo a passo` dentro de `Ambiente Kubernetes (EKS + Terraform)`.

## Desenho da arquitetura proposta

![Arquitetura da Fase 3](docs/Fase3/workflow.jpg)

Documentação de arquitetura desta fase:

- [Diagrama de sequência](docs/Fase3/diagrama-sequencia.md) — autenticação por CPF e abertura de OS.
- [Justificativa do banco de dados + diagrama ER](docs/Fase3/banco-de-dados.md).
- RFCs: [nuvem](docs/Fase3/rfcs/001-escolha-da-nuvem.md), [banco de dados](docs/Fase3/rfcs/002-escolha-do-banco-de-dados.md), [estratégia de autenticação](docs/Fase3/rfcs/003-estrategia-de-autenticacao.md).
- ADRs: [padrão de comunicação](docs/Fase3/adrs/001-padrao-de-comunicacao.md), [uso de HPA](docs/Fase3/adrs/002-uso-de-hpa.md).

Nesta API, a rota `GET /api/customer/me` é a única protegida pelo JWT de cliente (emitido pela Lambda
`lambda-auth-cpf`, segredo `CUSTOMER_JWT_SECRET` — **diferente** do `JWT_SECRET` usado pela guard
`api`/staff). Ver o RFC de estratégia de autenticação acima para o porquê de serem guards separadas.

## Stack
- PHP 8.4
- Laravel 13
- MySQL 8
- JWT para autenticação
- Docker Compose
- Swagger UI para visualização da documentação


## Pré-requisitos
Antes de iniciar, tenha instalado na máquina:

- Docker
- Docker Compose

## Início rápido (do zero)

Se você quer rodar o projeto com o mínimo de passos, execute:

```bash
make bootstrap
```

Depois, acesse:

- API: `http://localhost:8080`
- Swagger: `http://localhost:8082`

Para parar tudo:

```bash
docker compose down
```

## Serviços disponíveis

Ao subir o ambiente, os serviços ficam disponíveis em:

- API Laravel: `http://localhost:8080`
- Swagger UI: `http://localhost:8082`
- MySQL: `localhost:3308`

## Configuração do ambiente

### 1. Copie o arquivo de ambiente

```bash
cp .env.example .env
```

### 2. Revise as variáveis do banco

O projeto já vem configurado para usar o serviço `db` do Docker Compose. No `.env`, valide ao menos estes campos:

```env
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=techchallenge
DB_USERNAME=techchallenge
DB_PASSWORD=

JWT_SECRET=
```

Se quiser evitar inconsistência no MySQL, mantenha `DB_PASSWORD` preenchido com o mesmo valor usado no container.

Para gerar o JWT_SECRET precisa ter ao menos 256 bytes. Para facilitar utilize este comando e copie o output:

```
docker compose run --rm app-php php artisan jwt:secret
```

### 3. Suba os containers

```bash
docker compose up -d --build
```

### 4. Instale as dependências do PHP

```bash
docker compose run --rm app-php composer install
```

### 5. Gere a chave da aplicação
```bash
docker compose run --rm app-php php artisan key:generate
```

### 6. Rode as migrations
```bash
docker compose run --rm app-php php artisan migrate
```

## Como executar no dia a dia

Para iniciar o ambiente:

```bash
docker compose up -d
```

## Documentação da API

O contrato da API está no arquivo `openapi.yaml`.

Para visualizar a documentação no navegador, suba o serviço e acesse:

```text
http://localhost:8082
```
## Testes e qualidade de código

O projeto usa um `Makefile` para centralizar os comandos de teste, coverage e análise estática. Certifique-se de ter o `make` instalado na máquina.

### Referência rápida

| Comando | Descrição |
|---|---|
| `make help` | Lista todos os targets disponíveis |
| `make test` | Roda a suíte de testes **sem** gerar relatório de coverage (mais rápido) |
| `make coverage` | Roda os testes e gera `coverage.xml` via PCOV |
| `make scan` | Roda o SonarQube Scanner (requer `SONAR_TOKEN` e SonarQube em pé) |
| `make ci` | `coverage` + `scan` em sequência — ideal para pipelines de CI |
| `make all` | `up` + `coverage` + `scan` |
| `make up` | Sobe os containers de desenvolvimento em background |
| `make down` | Para e remove os containers de desenvolvimento |
| `make lint` | Formata o código com Laravel Pint |
| `make seed-dev` | Popula o banco de dados com informações principais |

### Rodar os testes

```bash
# Execução rápida, sem relatório de cobertura
make test

# Com geração de coverage.xml
make coverage
```

Os testes rodam dentro do container `app-test` definido em `docker-compose.test.yml`, usando um banco MySQL efêmero em tmpfs.

### Análise de cobertura com SonarQube

O SonarQube é configurado no `docker-compose.yml`. Na primeira execução, acesse `http://localhost:9000` para criar um projeto e gerar um token de acesso.

```bash
# Gera coverage e envia para o SonarQube
SONAR_TOKEN=sqa_xxxx make ci
```

O `SONAR_TOKEN` também pode ser exportado no shell para não precisar repeti-lo:

```bash
export SONAR_TOKEN=sqa_xxxx
make ci
```

## Observações

- O container `app-php` publica a aplicação na porta `8080` usando `php artisan serve`.
- O Swagger UI lê diretamente o arquivo `openapi.yaml` do projeto.
- O banco MySQL é exposto localmente na porta `3308`.

## Ambiente Kubernetes (EKS + Terraform)

Até a Fase 2 este ambiente rodava num cluster **Minikube local**. A partir da Fase 3 ele roda num
cluster **EKS real na AWS**, e o banco deixou de ser um `Deployment` MySQL dentro do cluster para
virar um **RDS gerenciado** — ambos provisionados por repositórios próprios, não por este:

- [`infra-kubernetes-fiap`](https://github.com/VinnyTechDevelopment/infra-kubernetes-fiap) — cria a VPC e o cluster EKS.
- [`infra-database-fiap`](https://github.com/VinnyTechDevelopment/infra-database-fiap) — cria o RDS MySQL, dentro da mesma VPC.

Este repositório só entra **depois** dos dois acima já aplicados: o `infra/` daqui lê os outputs
deles via `terraform_remote_state` (endpoint do cluster, endereço do RDS etc.) e provisiona só o que
é específico da aplicação — namespace, ConfigMaps, Secrets, Job de migration, Deployment/Service/HPA
do Laravel e do Swagger. Não existem `kubectl apply` soltos nem `local-exec`: cada peça é um resource
do Terraform (`kubernetes_namespace_v1`, `kubernetes_deployment_v1`, `kubernetes_secret_v1` etc.).

### Papel de cada peça

| Peça | Papel |
|---|---|
| **EKS** (`infra-kubernetes-fiap`) | Cluster Kubernetes gerenciado pela AWS, com node group autoescalável. |
| **RDS** (`infra-database-fiap`) | MySQL 8.0 gerenciado, na mesma VPC do EKS. |
| **Terraform** (`infra/`, este repositório) | Namespace, ConfigMaps, Secrets, Job de migration, Deployment/Service/HPA da aplicação e do Swagger — tudo que é específico do Laravel, lendo cluster e banco via `terraform_remote_state`. |
| **GitHub Actions** | `build-ghcr.yml` builda e publica a imagem no GHCR a cada push. `.github/workflows/deploy-eks.yml` roda `terraform plan` em Pull Requests (path `infra/**`) e `terraform apply` automático — via `workflow_run`, assim que a imagem termina de ser publicada — quando o push é em `main` (produção) ou `homologacao` (homologação); `workflow_dispatch` continua disponível como fallback manual. |

### Docker Compose vs EKS — quando usar cada um

| | Docker Compose | EKS (homologação/produção) |
|---|---|---|
| Uso principal | Dia a dia, desenvolvimento | Ambiente real, validado por CI/CD |
| Orquestração | `docker-compose.yml` | Resources Terraform (`kubernetes_*`) |
| Banco | Container `db` | RDS gerenciado (`infra-database-fiap`) |
| Acesso externo | Portas mapeadas direto | Network Load Balancer real da AWS (sem tunnel) |
| Escala | Manual | HPA (autoscaling automático, 2–10 réplicas) |

### Pré-requisitos

- `infra-kubernetes-fiap` e `infra-database-fiap` já aplicados (cluster e RDS precisam existir antes deste repositório)
- AWS CLI configurado com credenciais válidas (ver `AWS_ACADEMY.md` do `infra-kubernetes-fiap`, se estiver usando AWS Academy)
- `kubectl`
- Terraform >= 1.6
- Um bucket S3 + tabela DynamoDB para o state remoto (o mesmo usado pelos outros três repositórios)
- Um Personal Access Token do GitHub (classic) com escopo `read:packages`, para puxar a imagem do GHCR

### Passo a passo

**1. Configure o `kubectl` para falar com o EKS**

```bash
make eks-kubeconfig
# equivalente a: aws eks update-kubeconfig --name techchallenge-eks --region us-east-1
```

**2. Configure as variáveis sensíveis**

`app_key`, `db_password`, `jwt_secret` e `customer_jwt_secret` **não precisam ser gerados de novo**
para testar localmente — reaproveite os mesmos valores do seu `.env` (gerados via `make bootstrap`),
exceto `db_password`, que precisa ser **idêntica** à usada no `infra-database-fiap`:

```bash
grep -E '^(APP_KEY|DB_PASSWORD|JWT_SECRET|CUSTOMER_JWT_SECRET)=' .env
```

Crie `infra/terraform.tfvars` com esses valores (está no `.gitignore` — nunca commitar com dados reais):

```hcl
tf_state_bucket     = "O-MESMO-BUCKET-DOS-OUTROS-3-REPOSITORIOS"

app_key             = "base64:COPIE_DO_SEU_.ENV"
db_password         = "IDENTICA_A_DO_INFRA_DATABASE"
jwt_secret          = "COPIE_DO_SEU_.ENV"
customer_jwt_secret = "IDENTICA_A_DO_LAMBDA_AUTH_CPF"

ghcr_username = "SEU_USUARIO_GITHUB"
ghcr_token    = "SEU_TOKEN_COM_read:packages"

# New Relic (ligado por padrão; use newrelic_enabled = false para subir sem)
newrelic_license_key = "SUA_LICENSE_KEY"

# Opcionais — já têm default em variables.tf, só defina se quiser sobrescrever:
# ghcr_email           = "SEU_EMAIL"
# mail_username        = "SEU_EMAIL_SMTP"
# mail_password        = "SUA_SENHA_DE_APP"
```

**3. Aplique com Terraform**

Via `Makefile` (recomendado — valida `TF_STATE_BUCKET`/`TF_LOCK_TABLE` e se `terraform.tfvars` existe):

```bash
TF_STATE_BUCKET=seu-bucket TF_LOCK_TABLE=sua-tabela make infra-init
make infra-apply
# ou os dois passos de uma vez: TF_STATE_BUCKET=... TF_LOCK_TABLE=... make k8s-up
```

Ou manualmente:

```bash
cd infra
terraform init \
  -backend-config="bucket=seu-bucket" \
  -backend-config="key=app/terraform.tfstate" \
  -backend-config="region=us-east-1" \
  -backend-config="dynamodb_table=sua-tabela"
terraform apply
cd ..
```

Isso cria o namespace, ConfigMaps, Secrets, roda o Job de migration (contra o RDS) e sobe a aplicação
e o Swagger — nessa ordem, controlada pelas dependências entre os resources.

### Acessando a documentação (Swagger)

Os Services da app e do Swagger são `LoadBalancer` de verdade na AWS — sem tunnel, mas o DNS do NLB
pode levar alguns minutos para propagar depois do primeiro `apply`.

```bash
make k8s-urls
```

Isso imprime:

```bash
App:     http://xxxxx.elb.us-east-1.amazonaws.com:8080
Swagger: http://xxxxx.elb.us-east-1.amazonaws.com:8082
```

Abra a URL do **Swagger** no navegador para acessar a documentação interativa
da API (`openapi.yaml` da raiz do projeto).

**Para desligar a stack da aplicação (não afeta o EKS nem o RDS em si):**

Via Makefile: `make k8s-down`

Ou manualmente:
```bash
cd infra && terraform destroy && cd ..
```

### Atualizando a aplicação (nova imagem)

Como a tag da imagem (`image_tag`) faz parte do nome do Deployment e do Job de migration, basta rodar `terraform apply` de novo com uma tag nova para que o Terraform detecte a mudança, recrie o Job de migration e faça o rollout do Deployment automaticamente — sem precisar de `kubectl rollout restart` manual:

```bash
make infra-apply IMAGE_TAG=sha-abc1234
```

### Deploy automático (CI/CD) — homologação e produção

O workflow `.github/workflows/deploy-eks.yml` roda `terraform apply` automaticamente, sem passo
manual, disparado pelo `workflow_run` do `build-ghcr.yml` (ou seja: só depois que a imagem daquele
commit já foi publicada no GHCR com a tag `sha-<7 chars>`):

| Branch | Ambiente | Namespace | State key (S3) |
|---|---|---|---|
| `main` | Produção | `postech` | `app/production/terraform.tfstate` |
| `homologacao` | Homologação | `postech-homolog` | `app/homolog/terraform.tfstate` |

As duas branches usam o **mesmo cluster EKS e o mesmo RDS** (`infra-kubernetes-fiap` e
`infra-database-fiap` não são duplicados) — decisão tomada para não estourar o crédito limitado do
AWS Academy. O isolamento entre ambientes vem só do namespace do Kubernetes e da state key do
Terraform, que o workflow calcula sozinho a partir da branch que disparou o build (step "Resolve
ambiente" em `deploy-eks.yml`).

Um Pull Request pra `main` que mexe em `infra/**` continua rodando só `terraform plan` (sem apply) —
o merge é que dispara o deploy de verdade. `workflow_dispatch` continua disponível como fallback
manual, com um input `environment` (`production`/`homolog`) pra escolher o alvo.

Como no AWS Academy as credenciais (`AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`/`AWS_SESSION_TOKEN`)
expiram em poucas horas, isso ainda exige atualizar esses três secrets a cada sessão nova do Lab —
mas como um secret de nível **organization** (`VinnyTechDevelopment`) é compartilhado pelos 4
repositórios, essa atualização passa a ser feita em um lugar só.

### Referência rápida (make)

| Comando | Descrição |
|---|---|
| `make eks-kubeconfig` | Configura o `kubectl` para falar com o cluster EKS |
| `make infra-init` | `terraform init` em `infra/` com backend S3 (requer `TF_STATE_BUCKET`/`TF_LOCK_TABLE`; valida se `terraform.tfvars` existe) |
| `make infra-plan` | `terraform plan` (aceita `IMAGE_TAG=<tag>`) |
| `make infra-apply` | `terraform apply` (aceita `IMAGE_TAG=<tag>`) |
| `make infra-destroy` | Remove a stack da aplicação provisionada por este repositório (não afeta o EKS nem o RDS) |
| `make k8s-up` | `infra-init` + `infra-apply` em sequência |
| `make k8s-down` | Alias de `infra-destroy` |
| `make k8s-status` | Mostra pods e services do namespace |
| `make k8s-urls` | Exibe as URLs de acesso (Network Load Balancer real da AWS) |

## Observabilidade (New Relic)

O New Relic está **ligado por padrão** em todos os ambientes do EKS (`newrelic_enabled = true` em
`infra/variables.tf`). As credenciais ficam nos secrets da **organization** `VinnyTechDevelopment`,
compartilhados pelos repositórios:

| Secret | Usado por |
|---|---|
| `NEWRELIC_LICENSE_KEY` | Agente PHP (via Secret do K8s) e integração de cluster (`infra-kubernetes-fiap`) |
| `NEW_RELIC_API_KEY` | Provider Terraform `newrelic/newrelic` (dashboards como código) |
| `NEW_RELIC_ACCOUNT_ID` | Provider Terraform |

O `deploy-eks.yml` falha logo no início se algum deles estiver ausente, e o Terraform tem um bloco
`check` que acusa `newrelic_enabled = true` sem license key. A aplicação não sobe "sem
observabilidade" sem que alguém perceba.

O que está ativo:

- **APM + distributed tracing**: agente `newrelic-php5` instalado no `Dockerfile`, com
  `distributed_tracing_enabled` e encaminhamento de logs (logs in context). O `docker/entrypoint.sh`
  grava a license key no `newrelic.ini` antes de subir o PHP; por isso o Deployment não sobrescreve o
  `command` do container (ver comentário em `infra/app.tf`).
- **Dashboards como código** (`infra/newrelic.tf`): *Ordens de Serviço* (volume diário, tempo
  médio por status, erros de integração) e *Infraestrutura* (latência p95, CPU/memória do
  namespace, uptime).
- **Kubernetes**: o `nri-bundle` do repositório `infra-kubernetes-fiap` reporta métricas de
  pods/nodes, eventos e logs do cluster (`Infrastructure → Kubernetes → techchallenge-eks`).

Rodando localmente com Docker Compose, o agente fica inerte (sem `NEWRELIC_LICENSE_KEY`), sem
impacto no desenvolvimento.
