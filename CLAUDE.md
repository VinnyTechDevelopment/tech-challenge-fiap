# CLAUDE.md — projeto-pos

## Visão Geral

Projeto Laravel 13 (PHP 8.2+) para um sistema POS (ponto de venda / gestão). API RESTful com autenticação JWT, arquitetura DDD completa e documentação OpenAPI.

## Stack Tecnológica

- **Backend:** Laravel 13, PHP 8.2+
- **Banco de dados:** MySQL 8.0 (Docker)
- **Autenticação:** JWT via `php-open-source-saver/jwt-auth`
- **ORM:** Eloquent
- **Testes:** PHPUnit 12.5 + Mockery
- **Qualidade de código:** Laravel Pint
- **Documentação API:** OpenAPI/Swagger (porta 8082)
- **Frontend tooling:** Vite 8 + Tailwind CSS 4
- **Containers:** Docker Compose (app-php :8080, db :3306, swagger-ui :8082)
- **Testes de API:** Bruno (`/bruno`)

## Arquitetura — Domain-Driven Design (DDD)

O projeto segue DDD estrito com quatro camadas dentro de `app/`:

```
app/
├── Domain/           # Regras de negócio puras (sem dependências de framework)
│   └── {Domínio}/
│       ├── Entities/        # Entidades com identidade e ciclo de vida
│       ├── ValueObjects/    # Objetos de valor imutáveis com validação
│       └── Interfaces/      # Contratos de repositório (abstrações)
│
├── Application/      # Casos de uso e orquestração
│   └── {Domínio}/
│       ├── UseCases/        # Um arquivo por operação (Create, List, Show, Update, Delete)
│       └── DTOs/            # Transferência de dados entre camadas
│
├── Infrastructure/   # Implementações concretas (persistência, serviços externos)
│   └── Persistence/
│       └── Eloquent/
│           ├── Models/       # Modelos Eloquent (não são entidades de domínio)
│           └── Repositories/ # Implementações dos contratos de repositório
│
└── Presentation/     # Camada HTTP (entrada/saída)
    └── Http/
        ├── Controllers/     # Recebe HTTP, chama UseCase, retorna JSON
        └── Requests/        # Validação de entrada (Form Requests do Laravel)
```

### Regras de Ouro da Arquitetura

1. **Domain** nunca importa classes de Laravel, Eloquent ou qualquer framework.
2. **Application** depende apenas de Domain (interfaces). Nunca acessa banco diretamente.
3. **Infrastructure** implementa as interfaces do Domain usando Eloquent.
4. **Presentation** recebe a requisição HTTP, converte para DTO e chama o UseCase correspondente.
5. Injeção de dependência é configurada em `app/Providers/RepositoryServiceProvider.php` — toda nova interface deve ser vinculada aqui.
6. UUIDs são gerados nas Entities (`Str::uuid()->toString()`), nunca no banco.

### Fluxo de uma Requisição

```
HTTP Request
  → Presentation/Http/Requests/{Domínio}/{Action}Request.php   (validação)
  → Presentation/Http/Controllers/{Domínio}Controller.php      (monta DTO, chama UseCase)
  → Application/{Domínio}/DTOs/{Action}{Domínio}DTO.php        (transporte de dados)
  → Application/{Domínio}/UseCases/{Action}{Domínio}UseCase.php (orquestração)
  → Domain/{Domínio}/Interfaces/{Domínio}RepositoryInterface.php (contrato)
  → Infrastructure/Persistence/Eloquent/Repositories/{Domínio}RepositoryEloquent.php (Eloquent)
  → Domain/{Domínio}/Entities/{Domínio}.php                    (entidade retornada)
  ← JSON Response
```

## Domínios Existentes (os-service — Fase 4)

| Domínio | Observação |
|---|---|
| `ServiceOrder` | OS com snapshot de serviços/peças; status conduzido pela saga |
| `Saga` | `ServiceOrderSaga` + `App\Application\Saga\ServiceOrderSagaOrchestrator` (orquestrador) |
| `Customer`, `Vehicle`, `Notification` | inalterados |

Catálogo/estoque/execução estão no **execution-service** e orçamento/pagamento no **billing-service** (repositórios próprios). Comunicação: SQS (`app/Application/Messaging`, `app/Infrastructure/Messaging`, outbox transacional) e REST para o catálogo (`CatalogClientInterface`). Nunca acesse o banco de outro serviço.

## Convenções de Nomenclatura

- **Entidades:** `{Dominio}.php` — ex: `Customer.php`, `Vehicle.php`
- **Value Objects:** conceito do valor — ex: `Document.php`, `Plate.php`
- **Interfaces de repositório:** `{Dominio}RepositoryInterface.php`
- **Repositórios Eloquent:** `{Dominio}RepositoryEloquent.php`
- **Models Eloquent:** `{Dominio}Model.php`
- **UseCases:** `{Acao}{Dominio}UseCase.php` — ex: `CreateCustomerUseCase.php`
- **DTOs:** `{Acao}{Dominio}DTO.php` — ex: `CreateCustomerDTO.php`
- **Controllers:** `{Dominio}Controller.php`
- **Requests:** `{Acao}{Dominio}Request.php`
- **Migrations:** padrao Laravel com timestamps

## Comandos Uteis

```bash
# Setup inicial
composer run setup

# Desenvolvimento (servidor + queue + logs + vite)
composer run dev

# Subir containers Docker
docker compose up -d

# Executar testes
composer run test
# ou
php artisan test

# Formatar codigo (Pint)
./vendor/bin/pint

# Criar migration
php artisan make:migration create_{tabela}_table

# Rodar migrations
php artisan migrate

# Gerar JWT secret
php artisan jwt:secret
```

## Rotas da API

Ver `routes/api.php` e `openapi.yaml`. Principais: `POST /service-order` (202, inicia a saga), `GET /service-order/{id}/history`, `POST /service-order/{id}/cancel`, `PATCH /service-order/{id}/status` (só `entregue`), `GET /internal/customers/by-document/{cpf}` (X-Internal-Token).

Testes: `make test` (PHPUnit + MySQL) e `vendor/bin/behat` (BDD da saga). Workers: `messaging:consume`, `outbox:relay`, `schedule:work` (`saga:timeouts`).

## Adicionando um Novo Dominio (Checklist)

Ao criar um novo domínio, siga esta ordem:

- [ ] `app/Domain/{Dominio}/Entities/{Dominio}.php` — com `create()` e metodos de negocio
- [ ] `app/Domain/{Dominio}/ValueObjects/*.php` — para cada conceito de valor com validacao
- [ ] `app/Domain/{Dominio}/Interfaces/{Dominio}RepositoryInterface.php`
- [ ] `app/Infrastructure/Persistence/Eloquent/Models/{Dominio}Model.php`
- [ ] `database/migrations/YYYY_MM_DD_HHMMSS_create_{tabela}_table.php`
- [ ] `app/Infrastructure/Persistence/Eloquent/Repositories/{Dominio}RepositoryEloquent.php`
- [ ] `app/Application/{Dominio}/DTOs/{Acao}{Dominio}DTO.php` (um por operacao)
- [ ] `app/Application/{Dominio}/UseCases/{Acao}{Dominio}UseCase.php` (um por operacao)
- [ ] `app/Presentation/Http/Requests/{Acao}{Dominio}Request.php`
- [ ] `app/Presentation/Http/Controllers/{Dominio}Controller.php`
- [ ] Registrar binding em `app/Providers/RepositoryServiceProvider.php`
- [ ] Registrar rotas em `routes/api.php`
- [ ] Documentar em `openapi.yaml`
- [ ] Testes em `tests/Unit/` (entidades, value objects) e `tests/Feature/` (endpoints)

## Estrutura de Testes

- `tests/Unit/` — testes de Entidades e Value Objects (sem banco, sem HTTP)
- `tests/Feature/` — testes de endpoints (usa banco de testes ou mocks)
- Factories em `database/factories/`
- Use Mockery para mockar repositorios em testes de UseCase

## Tratamento de Erros (Exception Handling)

O projeto usa um **handler global** em `bootstrap/app.php` para converter excecoes de dominio em respostas JSON amigaveis. **Nao use try-catch nos controllers ou UseCases.**

### Mapeamento de excecoes → HTTP

| Excecao | Status | Quando usar |
|---|---|---|
| `\DomainException` | 422 | Regras de negocio violadas (estoque insuficiente, codigo duplicado, exclusao bloqueada) |
| `\InvalidArgumentException` | 422 | Value Object com valor invalido (tipo, unidade, codigo fora do formato) |
| `AuthenticationException` | 401 | Token JWT ausente, invalido ou expirado (ja configurado) |
| Qualquer outra | 500 | Erro inesperado (stack trace visivel apenas com `APP_DEBUG=true`) |

### Regras

- **UseCases:** lancam `\DomainException` ou `\InvalidArgumentException` — nunca capturam
- **Value Objects:** lancam `\InvalidArgumentException` no construtor — nunca capturam
- **Controllers:** nao tem try-catch — deixam as excecoes subirem para o handler global
- **Novos tipos de erro** (ex: 404 `NotFoundException`): registrar um novo `$exceptions->render()` em `bootstrap/app.php`

### Exemplo correto

```php
// UseCase — apenas lanca, nunca captura
public function execute(StockWithdrawalDTO $dto): StockMovement
{
    $item = $this->itemRepository->findById($dto->itemId);

    if (!$item) {
        throw new \DomainException('Item not found.');  // → 422
    }

    $item->removeStock($dto->quantity);  // lanca DomainException se insuficiente → 422
    // ...
}

// Controller — sem try-catch
public function withdrawal(string $id, StockWithdrawalRequest $request, StockWithdrawalUseCase $useCase): JsonResponse
{
    $movement = $useCase->execute(new StockWithdrawalDTO(...));
    return response()->json([...], 201);
}
```

## Observacoes Importantes

- **Namespace inconsistente:** `Domain/Customer/interfaces/` usa `i` minusculo — padronizar para `Interfaces/` com `I` maiusculo nos novos dominios.
- **Customer sem rotas:** o CRUD de Customer esta implementado mas nao ha rotas em `routes/api.php`.
- **Soft Delete:** nao implementado; avaliar necessidade por dominio.
- **Autorizacao:** apenas autenticacao JWT implementada; RBAC nao presente.
