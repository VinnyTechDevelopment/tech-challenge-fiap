resource "kubernetes_namespace_v1" "postech" {
  metadata {
    name = var.namespace

    labels = {
      app         = "postech-pos"
      environment = var.environment
    }
  }
}
