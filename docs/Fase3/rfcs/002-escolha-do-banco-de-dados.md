# RFC 002 — Escolha do banco de dados gerenciado

- **Status:** Aceito
- **Autores:** Equipe POS Tech

## Contexto

A Fase 3 pede um banco de dados **gerenciado** (RDS, Cloud SQL, etc.) no lugar do MySQL rodando como
`Deployment` dentro do próprio cluster Kubernetes (era assim até a Fase 2 — ver `infra/mysql.tf`,
removido nesta fase). Precisamos escolher o motor e o serviço gerenciado.

## Alternativas consideradas

| Opção | Prós | Contras |
|---|---|---|
| **MySQL 8.0 no RDS** (escolhida) | Já era o motor usado desde a Fase 1; schema, migrations e queries (`enum`, `foreignId`, etc.) já escritos e testados para MySQL; `RDS` é o serviço gerenciado nativo da AWS (nuvem já escolhida no [RFC 001](./001-escolha-da-nuvem.md)) | Menos recursos avançados que Postgres (ex.: JSONB, extensões) — não usados pelo domínio hoje |
| PostgreSQL no RDS | Tipagem mais rica, `JSONB`, extensões (`pgcrypto` p/ UUID nativo) | Trocar de motor significaria reescrever/validar de novo todas as 20 migrations existentes e o comportamento de `enum`/collation, sem ganho concreto para o domínio atual |
| SQL Server no RDS | Nenhum, dado o contexto do projeto | Licenciamento mais caro, motor menos comum no ecossistema Laravel/comunidade do curso |
| DynamoDB (NoSQL, serverless) | Escala automaticamente, sem servidor pra gerenciar | O domínio é fortemente relacional (Customer → ServiceOrder → Services/Items, com totais calculados e integridade referencial via FK) — modelar isso em NoSQL exigiria desnormalização manual e reescrever toda a camada `Infrastructure/Persistence/Eloquent` |

## Decisão

**MySQL 8.0 via Amazon RDS** (`infra-database-fiap/rds.tf`), mantendo o mesmo motor usado desde a
Fase 1, só trocando "quem hospeda o processo" (de um `Deployment` no cluster para um serviço gerenciado).

## Ajustes feitos no modelo relacional para esta fase

- **Nova coluna `customers.status`** (`enum('active','inactive')`, default `active`) — necessária
  para a Lambda de autenticação poder "consultar a existência **e o status** do cliente", requisito
  explícito da Fase 3 que não existia até então (ver migration
  `2026_09_14_120000_add_status_to_customers_table.php`).
- **Nenhuma outra mudança de schema** foi necessária para a migração Minikube → RDS: o Eloquent já
  falava com MySQL via `DB_HOST`/`DB_PORT` configuráveis, então só mudou o valor dessas variáveis
  (de um Service interno do cluster para `data.terraform_remote_state.database.outputs.rds_address`).

## Consequências

- `infra-database-fiap` só existe **depois** de `infra-kubernetes-fiap` (lê `vpc_id` e
  `private_subnet_ids` de lá via `terraform_remote_state`, para colocar o RDS na mesma VPC do EKS).
- O security group do RDS libera a porta 3306 para todo o CIDR da VPC (não para IPs específicos),
  porque três consumidores diferentes precisam falar com o banco: os pods do Laravel no EKS, o Job
  de migration, e a Lambda `lambda-auth-cpf` — todos dentro da mesma VPC, nenhum exposto publicamente.
- Sem Multi-AZ, sem Enhanced Monitoring, backup retido por só 1 dia — trade-offs aceitáveis para um
  projeto de estudo, documentados como tal no próprio `rds.tf` (`# projeto de estudo, não produção real`).
