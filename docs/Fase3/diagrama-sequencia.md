# Diagrama de Sequência — Fase 3

Ver também o [Diagrama de Componentes](./workflow.jpg) para a visão geral de infraestrutura.

## 1. Autenticação do cliente via CPF

O cliente nunca fala diretamente com o Laravel para se autenticar — quem valida o CPF, confere o
status do cliente e emite o token é a Function Serverless (`lambda-auth-cpf`), atrás do API Gateway.
O Laravel só entra depois, para validar esse token nas rotas que aceitam cliente (ex.: `GET /customer/me`).

```mermaid
sequenceDiagram
    actor Cliente
    participant GW as API Gateway
    participant Lambda as Lambda (lambda-auth-cpf)
    participant RDS as RDS MySQL
    participant App as Laravel (EKS)

    Cliente->>GW: POST /auth/cpf { cpf }
    GW->>Lambda: invoca (AWS_PROXY)
    Lambda->>Lambda: valida formato/dígitos verificadores do CPF
    alt CPF com formato inválido
        Lambda-->>GW: 422 CPF inválido
        GW-->>Cliente: 422
    else CPF válido
        Lambda->>RDS: SELECT id, document, status FROM customers WHERE document = ?
        alt cliente não encontrado
            RDS-->>Lambda: 0 linhas
            Lambda-->>GW: 404 Cliente não encontrado
            GW-->>Cliente: 404
        else cliente inativo
            RDS-->>Lambda: status = inactive
            Lambda-->>GW: 403 Cliente inativo
            GW-->>Cliente: 403
        else cliente ativo
            RDS-->>Lambda: status = active
            Lambda->>Lambda: assina JWT (CUSTOMER_JWT_SECRET, claim type=customer)
            Lambda-->>GW: 200 { token, token_type, expires_in }
            GW-->>Cliente: 200
        end
    end

    Note over Cliente,App: Chamadas seguintes usam Authorization: Bearer <token>

    Cliente->>App: GET /api/customer/me (Bearer <token>)
    App->>App: AuthenticateCustomerJwt (decodifica com CUSTOMER_JWT_SECRET,\nconfere claim type=customer)
    alt token ausente/inválido/expirado
        App-->>Cliente: 401
    else token válido
        App->>RDS: SELECT * FROM customers WHERE id = sub
        RDS-->>App: dados do cliente
        App-->>Cliente: 200 { customer }
    end
```

Pontos de decisão relevantes (ver [RFC 003](./rfcs/003-estrategia-de-autenticacao.md)):

- O JWT do cliente usa um segredo (`CUSTOMER_JWT_SECRET`) e uma guard **diferentes** do JWT de
  staff (`JWT_SECRET`/`php-open-source-saver/jwt-auth`) — são identidades e propósitos distintos
  (cliente por CPF vs. usuário de staff com senha).
- A Lambda consulta o RDS diretamente (mesma VPC), não através da API Laravel — evita acoplar a
  autenticação à disponibilidade do cluster EKS.

## 2. Abertura de uma Ordem de Serviço (fluxo de staff)

Este fluxo já existe na aplicação (não é novidade da Fase 3), mas documentamos aqui porque o
requisito pede explicitamente o diagrama de sequência desse caso.

```mermaid
sequenceDiagram
    actor Atendente
    participant App as Laravel (EKS)
    participant DB as RDS MySQL

    Atendente->>App: POST /api/auth/login { email, password }
    App->>DB: valida credenciais (guard api / jwt-auth)
    DB-->>App: usuário válido
    App-->>Atendente: 200 { access_token }

    Atendente->>App: POST /api/service-order (Bearer access_token)\n{ vehicleId, customerId, services[], items[] }
    App->>App: CreateServiceOrderUseCase
    App->>DB: valida customer_id e vehicle_id existem
    App->>DB: valida estoque suficiente para cada item (Domain: Item.removeStock)
    alt estoque insuficiente ou cliente/veículo inexistente
        App-->>Atendente: 422 (DomainException)
    else dados válidos
        App->>DB: INSERT service_orders (status=recebida)
        App->>DB: INSERT service_order_services (por serviço)
        App->>DB: INSERT service_order_items (por peça) + INSERT stock_movements (withdrawal)
        App-->>Atendente: 201 { serviceOrder }
    end

    Note over App: Estados seguintes da OS (recebida → em_diagnostico →\naguardando_aprovacao → em_execucao → finalizada → entregue)\nsão avançados via PATCH /api/service-order/{id}/status
```
