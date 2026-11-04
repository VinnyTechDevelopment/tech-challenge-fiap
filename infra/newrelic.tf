# Dashboards do New Relic (Fase 3 — Monitoramento e Observabilidade).
#
# Ligados por padrão (var.newrelic_enabled = true). Credenciais vêm dos
# secrets de organização NEWRELIC_LICENSE_KEY (agente), NEW_RELIC_API_KEY e
# NEW_RELIC_ACCOUNT_ID (provider) — ver README, seção Observabilidade.

# (required_providers para "newrelic" já está declarado em providers.tf,
# junto com kubernetes/aws, para não duplicar o bloco terraform{}.)

# Lê NEW_RELIC_API_KEY / NEW_RELIC_ACCOUNT_ID / NEW_RELIC_REGION do ambiente
# automaticamente — não precisa (nem deve) hardcodar nada aqui.
provider "newrelic" {}

# Falha no plan em vez de subir a app "com New Relic" e ninguém reportar nada.
check "newrelic_license_key_presente" {
  assert {
    condition     = !var.newrelic_enabled || length(var.newrelic_license_key) > 0
    error_message = "newrelic_enabled = true exige newrelic_license_key (secret NEWRELIC_LICENSE_KEY)."
  }
}

resource "newrelic_one_dashboard" "service_orders" {
  count = var.newrelic_enabled ? 1 : 0

  name = "POS Tech — Ordens de Serviço"

  page {
    name = "Ordens de Serviço"

    widget_bar {
      title  = "Volume diário de ordens de serviço"
      row    = 1
      column = 1
      width  = 4
      height = 3

      nrql_query {
        query = "SELECT count(*) FROM Transaction WHERE appName = '${var.newrelic_app_name}' AND request.uri LIKE '%/service-order%' FACET dateOf(timestamp) SINCE 30 days ago"
      }
    }

    widget_bar {
      title  = "Tempo médio de execução por status (Diagnóstico/Execução/Finalização)"
      row    = 1
      column = 5
      width  = 4
      height = 3

      nrql_query {
        query = "SELECT average(duration) FROM Transaction WHERE appName = '${var.newrelic_app_name}' AND request.uri LIKE '%/service-order%' FACET request.parameters.status SINCE 7 days ago"
      }
    }

    widget_table {
      title  = "Erros e falhas nas integrações"
      row    = 1
      column = 9
      width  = 4
      height = 3

      nrql_query {
        query = "SELECT count(*), latest(error.message) FROM TransactionError WHERE appName = '${var.newrelic_app_name}' FACET error.class SINCE 7 days ago"
      }
    }
  }
}

resource "newrelic_one_dashboard" "infrastructure" {
  count = var.newrelic_enabled ? 1 : 0

  name = "POS Tech — Infraestrutura"

  page {
    name = "Infraestrutura"

    widget_line {
      title  = "Latência das APIs (p95)"
      row    = 1
      column = 1
      width  = 4
      height = 3

      nrql_query {
        query = "SELECT percentile(duration, 95) FROM Transaction WHERE appName = '${var.newrelic_app_name}' TIMESERIES SINCE 24 hours ago"
      }
    }

    widget_line {
      title  = "CPU e memória do Kubernetes (namespace ${var.namespace})"
      row    = 1
      column = 5
      width  = 4
      height = 3

      nrql_query {
        query = "SELECT average(cpuUsedCores), average(memoryUsedBytes) FROM K8sContainerSample WHERE namespaceName = '${var.namespace}' TIMESERIES SINCE 24 hours ago"
      }
    }

    widget_billboard {
      title  = "Healthcheck / uptime"
      row    = 1
      column = 9
      width  = 4
      height = 3

      nrql_query {
        query = "SELECT percentage(count(*), WHERE result = 'SUCCESS') FROM SyntheticCheck WHERE monitorName LIKE '%postech%' SINCE 24 hours ago"
      }
    }
  }
}

# Fase 4 — rastreamento dos fluxos distribuídos (saga entre os 3 serviços).
# Os eventos customizados "SagaStep" / "SagaCompensationFailed" são
# registrados pelo orquestrador (App\Application\Saga); os traces atravessam
# os serviços porque os headers de distributed tracing viajam dentro das
# mensagens SQS (App\Infrastructure\Observability\Tracer).
locals {
  microservices_nrql = "appName IN ('${var.newrelic_app_name}', '${var.newrelic_app_name} (worker)', 'billing-service', 'billing-service (worker)', 'execution-service', 'execution-service (worker)')"
}

resource "newrelic_one_dashboard" "saga" {
  count = var.newrelic_enabled ? 1 : 0

  name = "POS Tech — Saga de Ordens de Serviço (microsserviços)"

  page {
    name = "Saga"

    widget_billboard {
      title  = "Sagas por desfecho (24h)"
      row    = 1
      column = 1
      width  = 4
      height = 3

      nrql_query {
        query = "SELECT filter(uniqueCount(sagaId), WHERE step = 'execution' AND outcome = 'completed') AS 'Concluidas', filter(uniqueCount(sagaId), WHERE step = 'saga' AND outcome = 'cancelled') AS 'Canceladas', filter(uniqueCount(sagaId), WHERE outcome = 'compensated') AS 'Com compensacao' FROM SagaStep SINCE 24 hours ago"
      }
    }

    widget_bar {
      title  = "Passos da saga por desfecho"
      row    = 1
      column = 5
      width  = 4
      height = 3

      nrql_query {
        query = "SELECT count(*) FROM SagaStep FACET step, outcome SINCE 24 hours ago"
      }
    }

    widget_table {
      title  = "Compensações que falharam (ação manual)"
      row    = 1
      column = 9
      width  = 4
      height = 3

      nrql_query {
        query = "SELECT latest(reason) FROM SagaCompensationFailed FACET sagaId, serviceOrderId SINCE 7 days ago"
      }
    }

    widget_line {
      title  = "Throughput por microsserviço (req/min)"
      row    = 4
      column = 1
      width  = 6
      height = 3

      nrql_query {
        query = "SELECT rate(count(*), 1 minute) FROM Transaction WHERE ${local.microservices_nrql} FACET appName TIMESERIES SINCE 3 hours ago"
      }
    }

    widget_line {
      title  = "Latência do consumo de mensagens (p95, por tipo)"
      row    = 4
      column = 7
      width  = 6
      height = 3

      nrql_query {
        query = "SELECT percentile(duration, 95) FROM Transaction WHERE name LIKE '%Consume/%' FACET name TIMESERIES SINCE 3 hours ago"
      }
    }

    widget_table {
      title  = "Traces distribuídos recentes de OS (os -> billing -> execution)"
      row    = 7
      column = 1
      width  = 6
      height = 3

      nrql_query {
        query = "SELECT uniqueCount(appName) AS 'servicos', count(*) AS 'spans', max(timestamp) FROM Span WHERE ${local.microservices_nrql} FACET trace.id SINCE 1 hour ago LIMIT 20"
      }
    }

    widget_line {
      title  = "Falhas de processamento de mensagens (vão para DLQ após 5)"
      row    = 7
      column = 7
      width  = 6
      height = 3

      nrql_query {
        query = "SELECT count(*) FROM Log WHERE message = 'messaging.handler_failed' FACET entity.name TIMESERIES SINCE 24 hours ago"
      }
    }
  }
}
