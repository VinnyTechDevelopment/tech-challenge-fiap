# customer-auth-lambda

Function Serverless (AWS Lambda, Node.js) responsável pela autenticação de clientes via CPF, conforme
o requisito "Autenticação e API Gateway" do Tech Challenge Fase 3. Pensada para viver, no futuro, em
seu próprio repositório (repositório 1 dos 4 exigidos pelo desafio); por ora está neste monorepo junto
com a aplicação principal.

## O que ela faz

1. Recebe o CPF do cliente (via API Gateway).
2. Valida o formato/dígitos verificadores do CPF.
3. Consulta a tabela `customers` do mesmo banco usado pela API Laravel, verificando existência e `status`.
4. Se o cliente existir e estiver `active`, emite um JWT (HS256) assinado com um segredo **próprio**
   (`CUSTOMER_JWT_SECRET`), diferente do `JWT_SECRET` usado pela guard `api` do Laravel — são tokens
   com propósitos e provedores diferentes, não devem ser intercambiáveis.

## Contrato HTTP

`POST /auth/customer`

```json
{ "document": "529.982.247-25" }
```

Respostas:

| Status | Quando |
|---|---|
| 200 | CPF válido, cliente existe e está ativo → `{ token, tokenType, expiresIn }` |
| 400 | Campo `document`/`cpf` ausente ou corpo inválido |
| 422 | CPF com formato ou dígito verificador inválido |
| 404 | Nenhum cliente com esse CPF |
| 403 | Cliente existe mas está com `status != active` |
| 500 | Falha inesperada (ex.: banco indisponível) |

Claims do JWT emitido: `sub` (id do cliente), `document`, `type: "customer"`, `iat`, `exp`.

## Variáveis de ambiente

Veja `.env.example`. Em produção, `DB_PASSWORD` e `CUSTOMER_JWT_SECRET` devem vir do AWS Secrets
Manager / SSM Parameter Store, nunca de variáveis de ambiente em texto plano no console do Lambda.

## Rodando os testes

```bash
npm install
npm test
```

## Empacotando para deploy

```bash
npm install --omit=dev
zip -r customer-auth-lambda.zip src node_modules package.json
```

Handler a configurar no Lambda: `src/handler.handler`.

## Pendências conhecidas / próximos passos

- **Coluna `status` em `customers`**: não existia no schema; foi adicionada pela migration
  `database/migrations/2026_09_14_120000_add_status_to_customers_table.php` (`enum('active','inactive')`,
  default `active`). Ajustar os valores/semântica se o time tiver outra definição de "status do cliente".
- **Rede**: o RDS hoje só é alcançável de dentro da VPC (o Job de migration do Kubernetes usa `nc` para
  esperar o RDS antes de migrar). Para este Lambda alcançar o banco diretamente em produção, ele precisa
  ser provisionado nas mesmas subnets/security group do RDS — isso fica para o repositório de infra do
  Lambda (Terraform), ainda não criado.
- **Validação do token no Laravel**: esta function só emite o JWT. As rotas "sensíveis" do lado do
  Laravel ainda precisam de uma guard/middleware novo que valide `CUSTOMER_JWT_SECRET` e o claim
  `type: "customer"` — deliberadamente não reaproveitando a guard `api` (que é para usuários/staff via
  `tymon`/`php-open-source-saver` jwt-auth).
- **Infraestrutura do Gateway/Lambda (Terraform)**: não incluída aqui; este diretório contém só o
  código da function.
