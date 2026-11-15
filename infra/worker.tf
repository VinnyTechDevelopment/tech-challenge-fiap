# Processos de background do os-service (mesma imagem da API, só muda o
# comando). Usamos "args" (não "command") para preservar o ENTRYPOINT da
# imagem, que injeta a License Key do New Relic (ver comentário em app.tf).
#
# - consumer : consome os-saga-replies e aciona o orquestrador da saga
# - outbox   : publica no SQS as mensagens gravadas no outbox transacional
# - scheduler: roda "saga:timeouts" a cada minuto (prazos de aprovação/pagamento)
#
# Uma única réplica: o relay preserva a ordem do outbox e o scheduler não
# duplica execuções. O consumer é seguro com mais réplicas (idempotente e
# com lock por saga) se o volume crescer.

locals {
  worker_processes = {
    consumer  = ["php", "artisan", "messaging:consume"]
    outbox    = ["php", "artisan", "outbox:relay"]
    scheduler = ["php", "artisan", "schedule:work"]
  }
}

resource "kubernetes_deployment_v1" "worker" {
  metadata {
    name      = "postech-worker"
    namespace = kubernetes_namespace_v1.postech.metadata[0].name
    labels    = { app = "postech-worker" }
  }

  spec {
    replicas = 1

    strategy {
      type = "Recreate"
    }

    selector {
      match_labels = { app = "postech-worker" }
    }

    template {
      metadata {
        labels = { app = "postech-worker" }
        annotations = {
          "postech.dev/rollout-id" = var.rollout_id
        }
      }

      spec {
        image_pull_secrets {
          name = kubernetes_secret_v1.ghcr_secret.metadata[0].name
        }

        dynamic "container" {
          for_each = local.worker_processes

          content {
            name              = container.key
            image             = "${var.image_repository}:${var.image_tag}"
            image_pull_policy = "Always"
            args              = container.value

            env {
              name  = "NEWRELIC_APPNAME"
              value = "${var.newrelic_app_name} (worker)"
            }

            env_from {
              config_map_ref {
                name = kubernetes_config_map_v1.app_config.metadata[0].name
              }
            }
            env_from {
              secret_ref {
                name = kubernetes_secret_v1.app_secret.metadata[0].name
              }
            }

            resources {
              requests = {
                cpu    = "50m"
                memory = "128Mi"
              }
              limits = {
                cpu    = "250m"
                memory = "256Mi"
              }
            }
          }
        }
      }
    }
  }

  depends_on = [kubernetes_job_v1.migrate, aws_sqs_queue.saga_replies]
}
