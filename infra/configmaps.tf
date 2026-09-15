resource "kubernetes_config_map_v1" "app_config" {
  metadata {
    name      = "app-config"
    namespace = kubernetes_namespace_v1.postech.metadata[0].name
  }

  data = {
    APP_NAME               = "POS Tech"
    APP_ENV                = "production"
    APP_DEBUG               = "false"
    APP_URL                 = "http://localhost"
    APP_LOCALE              = "pt_BR"
    APP_FALLBACK_LOCALE     = "en"
    APP_FAKER_LOCALE        = "pt_BR"
    APP_MAINTENANCE_DRIVER  = "file"
    # stack -> stderr: logs em JSON no stdout/stderr do container, o único
    # lugar que "kubectl logs" e um coletor externo (New Relic etc.) enxergam.
    LOG_CHANNEL             = "stack"
    LOG_STACK               = "stderr"
    DB_CONNECTION           = "mysql"
    # Antes apontava pro Service "mysql" dentro do cluster (Minikube).
    # Agora aponta pro RDS provisionado no repositório infra-database.
    DB_HOST                 = data.terraform_remote_state.database.outputs.rds_address
    DB_PORT                 = tostring(data.terraform_remote_state.database.outputs.rds_port)
    DB_DATABASE             = data.terraform_remote_state.database.outputs.db_name
    DB_USERNAME             = "techchallenge"
    QUEUE_CONNECTION        = "database"
    CACHE_STORE             = "database"
    MAIL_MAILER             = "log"
    MAIL_HOST               = "smtp.gmail.com"
    MAIL_PORT               = "587"
    MAIL_FROM_ADDRESS       = "evdwsoat15@gmail.com"
    MAIL_FROM_NAME          = "POS Tech"
  }
}

# Lê o openapi.yaml da raiz do projeto. Ajuste o caminho caso a estrutura de
# pastas seja diferente (por padrão, assume infra/ na raiz do repo e
# openapi.yaml um nível acima, em ../openapi.yaml).
resource "kubernetes_config_map_v1" "openapi_spec" {
  metadata {
    name      = "openapi-spec"
    namespace = kubernetes_namespace_v1.postech.metadata[0].name
  }

  data = {
    "openapi.yaml" = file("${path.module}/../openapi.yaml")
  }
}
