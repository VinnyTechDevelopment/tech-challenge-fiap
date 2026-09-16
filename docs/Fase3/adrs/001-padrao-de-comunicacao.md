# ADR 001 — Padrão de comunicação entre os serviços

- **Status:** Aceito

## Contexto

A partir da Fase 3 o sistema deixa de ser um monólito único e passa a ter peças provisionadas e
implantadas por repositórios/pipelines separados: a API Laravel (EKS), a Function Serverless
(`lambda-auth-cpf`) e dois bancos de infraestrutura (`infra-kubernetes`, `infra-database`). É preciso
decidir como essas peças trocam dados entre si em tempo de execução (não em tempo de deploy — isso é
coberto pelo `terraform_remote_state`, tratado à parte).

## Decisão

**HTTP síncrono (REST via API Gateway)** entre cliente final e a Lambda, e entre cliente final e o
Laravel — sem fila/mensageria assíncrona no caminho de autenticação nem no de abertura de ordem de
serviço.

- `Cliente → API Gateway → Lambda`: request/response HTTP simples (`AWS_PROXY`, `payload_format_version
  = "2.0"`). A autenticação é, por natureza, uma operação síncrona — o cliente precisa do token na
  mesma resposta para continuar.
- `Cliente → API Gateway → Laravel (EKS)`: mesma lógica; rota autenticada ainda não integrada no API
  Gateway (ver pendência registrada no README do `lambda-auth-cpf-fiap`), mas o padrão já definido é
  proxy HTTP direto, não fila.
- Dentro do Laravel, os handlers de notificação (`SendWelcomeNotification`,
  `NotifyCustomerOnQuoteSent`, etc., registrados em `EventServiceProvider`) reagem a eventos de
  domínio (`CustomerCreated`, `ServiceOrderStatusChanged`, ...), mas **de forma síncrona** — nenhum
  implementa `ShouldQueue`, então rodam na mesma requisição HTTP que disparou o evento. A tabela
  `jobs` existe no schema (padrão do Laravel), mas nada a usa hoje. Fica registrado aqui como um
  ponto de atenção: se o envio de notificação (e-mail/SMS) começar a impactar a latência percebida
  pelo atendente, esses handlers são candidatos naturais a virar `ShouldQueue` — não foi feito nesta
  fase por não fazer parte do escopo pedido.

## Alternativas consideradas

| Opção | Por que não |
|---|---|
| Fila (SQS) entre API Gateway e Lambda | Adicionaria latência e complexidade a um fluxo que é inerentemente request/response (login) |
| gRPC entre Laravel e Lambda | Nenhuma comunicação direta Laravel↔Lambda existe hoje (a Lambda fala só com o RDS); não haveria onde usar |
| Service Mesh (Istio/Linkerd) para tráfego dentro do cluster | Sobre-engenharia para um cluster com um único serviço HTTP (`postech-app`) hoje; reavaliar se o número de serviços dentro do EKS crescer |

## Consequências

- Acoplamento temporal: se a Lambda estiver fria (cold start) ou o RDS lento, o cliente espera —
  não há um "processar depois" possível para autenticação.
- A integração ainda pendente (rota autenticada no API Gateway apontando pro Laravel) deve seguir o
  mesmo padrão: **VPC Link HTTP** (mantém tráfego dentro da VPC) é preferível a uma rota pública
  `ANY /{proxy+}`, justamente para não expor o Service do Laravel publicamente só por causa do
  API Gateway — decisão ainda não fechada, registrada como TODO nos READMEs de
  `lambda-auth-cpf-fiap` e (implicitamente) `tech-challenge-fiap`.
