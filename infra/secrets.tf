resource "kubernetes_secret_v1" "app_secret" {
  metadata {
    name      = "app-secret"
    namespace = kubernetes_namespace_v1.postech.metadata[0].name
  }

  type = "Opaque"

  data = {
    APP_KEY              = var.app_key
    DB_PASSWORD          = var.db_password
    JWT_SECRET           = var.jwt_secret
    CUSTOMER_JWT_SECRET  = var.customer_jwt_secret
    MAIL_USERNAME        = var.mail_username
    MAIL_PASSWORD        = var.mail_password
    NEWRELIC_LICENSE_KEY = var.newrelic_license_key
  }
}

resource "kubernetes_secret_v1" "ghcr_secret" {
  metadata {
    name      = "ghcr-secret"
    namespace = kubernetes_namespace_v1.postech.metadata[0].name
  }

  type = "kubernetes.io/dockerconfigjson"

  data = {
    ".dockerconfigjson" = jsonencode({
      auths = {
        "ghcr.io" = {
          username = var.ghcr_username
          password = var.ghcr_token
          email    = var.ghcr_email
          auth     = base64encode("${var.ghcr_username}:${var.ghcr_token}")
        }
      }
    })
  }
}
