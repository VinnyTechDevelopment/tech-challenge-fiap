# Dashboards do New Relic (Fase 3 — Monitoramento e Observabilidade).
#
# Ficam desligados (var.newrelic_enabled = false) até existir conta + License
# Key + User API Key + Account ID configurados — ver README, seção
# Observabilidade, para o passo a passo de como criar a conta free e ligar
# isso de verdade.
#
# As queries NRQL abaixo são um ponto de partida escrito sem uma conta real
# pra validar contra — quando o agente (Dockerfile/docker/entrypoint.sh)
# estiver reportando dados de verdade, revise os nomes de evento/atributo
# (ex.: se o app passar a emitir eventos customizados para "ordem de
# serviço", ajuste a query pra usar esse evento em vez de Transaction/Log).

# (required_providers para "newrelic" já está declarado em providers.tf,
# junto com kubernetes/aws, para não duplicar o bloco terraform{}.)

# Lê NEW_RELIC_API_KEY / NEW_RELIC_ACCOUNT_ID / NEW_RELIC_REGION do ambiente
# automaticamente — não precisa (nem deve) hardcodar nada aqui.
provider "newrelic" {}

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
