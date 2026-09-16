# RFC 003 — Estratégia de autenticação do cliente

- **Status:** Aceito
- **Autores:** Equipe POS Tech

## Contexto

A Fase 3 exige "proteger rotas sensíveis da aplicação com autenticação via CPF", usando uma Function
Serverless que valida o CPF, consulta existência/status do cliente e emite um JWT. A aplicação já tinha
uma autenticação por usuário/senha para **staff** (atendente, mecânico, almoxarifado), via
`php-open-source-saver/jwt-auth` na guard `api`, resolvendo `App\Models\User`.

A pergunta central: o token do cliente deveria **reaproveitar** essa guard/segredo existente, ou ser
um mecanismo totalmente separado?

## Alternativas consideradas

### 1. Reaproveitar a guard `api` / jwt-auth existente (rejeitada)

Faria o cliente "logar" como se fosse um `User`. Rejeitada porque:

- `User::getJWTIdentifier()` retorna o `id` numérico do usuário, e o jwt-auth valida o `sub` do
  token contra `App\Models\User::find($sub)` — um cliente autenticado por CPF não é (e não deveria
  virar) uma linha na tabela `users`, que é especificamente de staff com senha.
- O jwt-auth, por padrão, adiciona um claim `prv` (hash da classe do model) e valida esse claim
  contra o provider configurado (`lock_subject`) — a Lambda teria que conhecer esse detalhe interno
  do pacote PHP só para emitir um token compatível, acoplando um serviço Node.js/Lambda a uma
  particularidade de uma lib PHP.
- Misturaria, na mesma guard, dois níveis de acesso completamente diferentes (funcionário da oficina
  vs. cliente final) — um bug de autorização ali teria blast radius maior.

### 2. Cognito ou outro IdP gerenciado (rejeitada por escopo)

Delegar a autenticação do cliente a um Cognito User Pool (ou similar) é uma opção legítima em
produção, mas: exigiria cadastro prévio de senha do cliente (a Fase 3 pede autenticação **via CPF**,
implicitamente sem senha — o cliente só prova que "sabe o próprio CPF" e já está cadastrado como
cliente ativo), e adicionaria mais um serviço gerenciado à conta AWS Academy (com as mesmas
restrições de IAM do [RFC 001](./001-escolha-da-nuvem.md)) sem necessidade clara para o escopo do
desafio.

### 3. Guard/segredo separado, emitido por uma Lambda dedicada (escolhida)

## Decisão

A Lambda `lambda-auth-cpf` (repositório próprio) é a única responsável por: validar o formato/dígito
verificador do CPF, consultar `customers.status` no RDS, e assinar um JWT com:

- **Segredo próprio** (`CUSTOMER_JWT_SECRET`), diferente do `JWT_SECRET` da guard `api`.
- Claim `type: "customer"` (em vez de depender de um claim proprietário de alguma lib).
- `sub` = id do `Customer` (UUID), não de um `User`.

Do lado do Laravel, um middleware dedicado (`AuthenticateCustomerJwt`, alias de rota `auth.customer`)
decodifica esse token com `firebase/php-jwt` — deliberadamente **sem** subir uma segunda instância do
`php-open-source-saver/jwt-auth` só para isso, já que o único recurso necessário é `decode()` de um
HS256 simples.

## Consequências

- Duas guards, dois segredos, dois pontos de configuração (`JWT_SECRET` e `CUSTOMER_JWT_SECRET`) —
  mais uma variável para manter sincronizada entre `tech-challenge-fiap` (quem valida) e
  `lambda-auth-cpf-fiap` (quem emite), documentado nos dois READMEs e nos comentários dos
  `variables.tf` de cada repositório.
- Rotas de cliente (ex.: `GET /customer/me`) e rotas de staff (`/customer`, `/vehicle`,
  `/service-order`, ...) nunca compartilham middleware de autenticação — um bug num guard não
  compromete o outro.
- Se o cliente precisar de mais operações de self-service (ex.: consultar o andamento da própria
  ordem de serviço), o padrão já está pronto — basta registrar a rota sob `middleware('auth.customer')`
  e ler `$request->attributes->get('customer_id')`, como já feito em `GET /customer/me`.
