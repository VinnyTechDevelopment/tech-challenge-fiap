# Justificativa da escolha do banco e modelo relacional

Ver também o [RFC 002 — Escolha do banco de dados](./rfcs/002-escolha-do-banco-de-dados.md) para a
comparação entre alternativas (MySQL vs. PostgreSQL vs. DynamoDB).

## Diagrama ER

Cobre as tabelas de domínio (Vehicle, Customer, Service, Item/Estoque, ServiceOrder, Notification).
Tabelas de infraestrutura do próprio Laravel (`cache`, `jobs`, `sessions`, `personal_access_tokens`,
`password_reset_tokens`) foram omitidas por não fazerem parte do domínio de negócio. As colunas de
auditoria `created_user_id`/`updated_user_id` (presentes em quase toda tabela, sempre referenciando
`users`) também foram omitidas do diagrama para não poluir a leitura — estão listadas em texto abaixo
de cada entidade.

```mermaid
erDiagram
    USERS ||--o{ CUSTOMERS : "audita (created/updated_user_id)"
    USERS ||--o{ SERVICE_ORDER_SERVICES : "inicia/finaliza"
    CUSTOMERS ||--o{ SERVICE_ORDERS : "abre"
    VEHICLES ||--o{ SERVICE_ORDERS : "é atendido em"
    SERVICE_ORDERS ||--o{ SERVICE_ORDER_ITEMS : "consome"
    ITEMS ||--o{ SERVICE_ORDER_ITEMS : "é usado em"
    SERVICE_ORDERS ||--o{ SERVICE_ORDER_SERVICES : "inclui"
    SERVICES ||--o{ SERVICE_ORDER_SERVICES : "é executado em"
    ITEMS ||--o{ STOCK_MOVEMENTS : "movimenta"
    SERVICE_ORDERS ||--o{ STOCK_MOVEMENTS : "gera (opcional)"

    USERS {
        bigint id PK
        string name
        string email UK
        string document UK "CPF, nullable"
        enum role "atendente | mecanico | almoxarifado"
        string password
    }

    CUSTOMERS {
        uuid id PK
        string name
        string email UK
        string phone
        string document UK "CPF/CNPJ, 14 chars"
        enum status "active | inactive"
    }

    VEHICLES {
        uuid id PK
        string brand
        string model
        int year
        string plate UK
    }

    SERVICES {
        uuid id PK
        string name
        decimal price
    }

    ITEMS {
        uuid id PK
        string name
        string code UK
        enum type "part | supply"
        string measure_unit
        decimal stock_quantity
        decimal minimum_quantity
        decimal unit_price
    }

    STOCK_MOVEMENTS {
        uuid id PK
        uuid item_id FK
        uuid service_order_id FK "nullable"
        enum movement_type "entry | withdrawal"
        decimal quantity
        decimal previous_quantity
        decimal current_quantity
        string reason
    }

    SERVICE_ORDERS {
        uuid id PK
        uuid customer_id FK
        uuid vehicle_id FK
        string status "recebida..entregue"
        decimal services_total
        decimal parts_total
        decimal total_budget
        string approval_token UK "nullable"
        timestamp deleted_at "soft delete"
    }

    SERVICE_ORDER_ITEMS {
        uuid id PK
        uuid service_order_id FK
        uuid item_id FK
        int quantity
        decimal price
    }

    SERVICE_ORDER_SERVICES {
        uuid id PK
        uuid service_order_id FK
        uuid service_id FK
        int quantity
        decimal price
        datetime started_at
        datetime finished_at
        bigint started_user_id FK
        bigint finished_user_id FK
    }

    NOTIFICATIONS {
        uuid id PK
        uuid recipient_id "id do Customer, sem FK"
        string type "email | sms | push"
        string status "pending | sent | failed"
    }
```

## Por que UUID como chave primária nas tabelas de domínio

Todas as entidades de domínio (`customers`, `vehicles`, `services`, `items`, `service_orders`, etc.)
usam `uuid` como chave primária, gerado na **Entity** (`Str::uuid()->toString()`), nunca no banco —
regra explícita do projeto (ver `CLAUDE.md`). Isso existe porque:

- O id precisa existir **antes** do INSERT (a camada de Aplicação já usa o id da Entity para montar
  respostas, eventos e relações entre agregados, ex.: `ServiceOrder` referenciando `Customer`/`Vehicle`
  por id, sem round-trip ao banco para descobrir o id gerado).
- IDs não sequenciais evitam enumeração (ex.: `GET /vehicle/1`, `/vehicle/2`, ...) e não vazam volume
  de negócio (quantos clientes/veículos existem) pela URL.
- `users` (a única tabela de staff/autenticação) continua com `bigint auto_increment` — é a exceção
  deliberada: o pacote `php-open-source-saver/jwt-auth` e o `Illuminate\Foundation\Auth\User` do
  Laravel assumem chave numérica por convenção, e não há benefício de segurança em esconder o id de
  um usuário interno da própria oficina.

## Colunas de auditoria (`created_user_id`/`updated_user_id`)

Praticamente toda tabela de domínio guarda quem criou/alterou o registro, como `foreignId` para
`users`, **not null**. Isso é o que hoje impede, por exemplo, rodar o `DevDataSeeder` sem antes
autenticar um usuário (`Auth::guard('api')->login($user)`) — é intencional: nenhum registro de
domínio deveria existir sem um responsável identificável, dado que a oficina é auditável (várias
unidades, vários atendentes).

## Enums vs. tabelas de lookup

`status` (em `customers`, `items.type`, `stock_movements.movement_type`, `service_orders.status`) e
`role` (em `users`) são `enum` nativo do MySQL, não tabelas de lookup separadas. Trade-off consciente
para um domínio pequeno e estável (a lista de status de uma OS, por exemplo, é uma regra de negócio
do próprio código — `ServiceOrder::assertStatus()` — não algo que um usuário final cadastra em tempo
de execução). O custo é que adicionar um novo valor exige migration + deploy, não um INSERT — aceitável
neste estágio do projeto.

## `service_order_items` / `service_order_services` como tabelas associativas com atributos

Não são muitos-para-muitos "puros" (`service_order_id` + `item_id` como PK composta) porque cada
associação carrega dados próprios do momento da venda: `quantity` e `price` são um **snapshot** do
preço no momento em que o item/serviço entrou na OS — se o preço do catálogo mudar depois, a OS já
fechada não deve ser afetada. Por isso cada linha tem seu próprio `uuid` como PK, e não uma chave
composta.
