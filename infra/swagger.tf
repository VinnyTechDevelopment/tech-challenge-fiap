resource "kubernetes_deployment_v1" "swagger" {
  metadata {
    name      = "swagger-ui"
    namespace = kubernetes_namespace_v1.postech.metadata[0].name
    labels    = { app = "swagger-ui" }
  }

  spec {
    replicas = 1

    selector {
      match_labels = { app = "swagger-ui" }
    }

    template {
      metadata {
        labels = { app = "swagger-ui" }
      }

      spec {
        container {
          name  = "swagger-ui"
          image = "swaggerapi/swagger-ui:latest"

          port {
            container_port = 8080
          }

          env {
            name  = "SWAGGER_JSON"
            value = "/app/openapi.yaml"
          }

          volume_mount {
            name       = "openapi-spec"
            mount_path = "/app/openapi.yaml"
            sub_path   = "openapi.yaml"
          }

          resources {
            requests = {
              cpu    = "50m"
              memory = "64Mi"
            }
            limits = {
              cpu    = "100m"
              memory = "128Mi"
            }
          }
        }

        volume {
          name = "openapi-spec"
          config_map {
            name = kubernetes_config_map_v1.openapi_spec.metadata[0].name
          }
        }
      }
    }
  }
}

resource "kubernetes_service_v1" "swagger" {
  metadata {
    name      = "swagger-ui"
    namespace = kubernetes_namespace_v1.postech.metadata[0].name
    labels    = { app = "swagger-ui" }

    annotations = {
      "service.beta.kubernetes.io/aws-load-balancer-type" = "nlb"
    }
  }

  spec {
    type     = "LoadBalancer"
    selector = { app = "swagger-ui" }

    port {
      name        = "http"
      port        = 8082
      target_port = 8080
      protocol    = "TCP"
    }
  }
}
