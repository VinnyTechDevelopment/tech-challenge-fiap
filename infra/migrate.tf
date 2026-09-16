resource "kubernetes_job_v1" "migrate" {
  metadata {
    name      = var.rollout_id != "" ? "app-migrate-${var.image_tag}-${var.rollout_id}" : "app-migrate-${var.image_tag}"
    namespace = kubernetes_namespace_v1.postech.metadata[0].name
    labels = {
      app       = "postech-app"
      component = "migrate"
    }
  }

  spec {
    backoff_limit             = 3
    ttl_seconds_after_finished = 300

    template {
      metadata {
        labels = {
          app       = "postech-app"
          component = "migrate"
        }
      }

      spec {
        restart_policy = "OnFailure"

        image_pull_secrets {
          name = kubernetes_secret_v1.ghcr_secret.metadata[0].name
        }

        init_container {
          name    = "wait-for-db"
          image   = "busybox:1.36"
          command = ["sh", "-c", <<-EOT
            until nc -z ${data.terraform_remote_state.database.outputs.rds_address} ${data.terraform_remote_state.database.outputs.rds_port}; do
              echo "aguardando RDS...";
              sleep 3;
            done;
            echo "RDS disponivel."
          EOT
          ]
        }

        container {
          name              = "migrate"
          image             = "${var.image_repository}:${var.image_tag}"
          image_pull_policy = "Always"
          command           = ["php", "artisan", "migrate", "--force"]

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
              cpu    = "100m"
              memory = "256Mi"
            }
            limits = {
              cpu    = "200m"
              memory = "512Mi"
            }
          }
        }
      }
    }
  }

  wait_for_completion = true

  timeouts {
    create = "4m"
  }

  # Antes também dependia do Deployment/Service do MySQL dentro do cluster;
  # agora o banco é externo (RDS), então a dependência é só o init container
  # acima checando a disponibilidade.
}
