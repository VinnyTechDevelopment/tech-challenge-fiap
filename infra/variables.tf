variable "aws_region" {
  type    = string
  default = "us-east-1"
}

variable "tf_state_bucket" {
  description = "Bucket S3 onde os states de infra-kubernetes e infra-database ficam guardados"
  type        = string
}

variable "namespace" {
  description = "Namespace onde toda a aplicação é provisionada"
  type        = string
  default     = "postech"
}

variable "environment" {
  description = "Ambiente de destino do apply. Controla apenas labels/observabilidade — o isolamento real entre produção e homologação vem de var.namespace e da state key usadas em cada apply (ver infra/deploy-eks.yml)."
  type        = string
  default     = "production"

  validation {
    condition     = contains(["production", "homolog"], var.environment)
    error_message = "environment precisa ser \"production\" ou \"homolog\"."
  }
}

variable "image_repository" {
  description = "Repositório da imagem da aplicação (sem a tag). Em deploy-eks.yml isso é sempre sobrescrito via TF_VAR_image_repository, calculado a partir de github.repository (minúsculo) — este default só vale pra apply manual local."
  type        = string
  default     = "ghcr.io/vinnytechdevelopment/tech-challenge-fiap"
}

variable "image_tag" {
  description = "Tag da imagem a ser implantada. Usar uma tag única por build (ex.: sha-<7 chars>) garante que o Deployment e o Job de migration sejam recriados a cada apply."
  type        = string
  default     = "latest"
}

variable "rollout_id" {
  description = "Identificador de rollout para forcar atualizacao de app/migrate mesmo com image_tag fixa (ex.: latest)."
  type        = string
  default     = ""
}

variable "app_key" {
  description = "APP_KEY do Laravel (gerado via 'php artisan key:generate --show')"
  type        = string
  sensitive   = true
}

variable "db_password" {
  description = "Senha do usuário de banco da aplicação (DB_PASSWORD). PRECISA ser idêntica ao var.db_password usado no repositório infra-database, já que é o mesmo usuário do RDS."
  type        = string
  sensitive   = true
}

variable "jwt_secret" {
  description = "Segredo usado para assinar os JWTs (gerado via 'php artisan jwt:secret --show')"
  type        = string
  sensitive   = true
}

variable "customer_jwt_secret" {
  description = "Segredo do JWT de cliente emitido pela Lambda lambda-auth-cpf (repositório separado). PRECISA ser idêntico ao var.customer_jwt_secret usado lá — NÃO é o mesmo valor de var.jwt_secret."
  type        = string
  sensitive   = true
}

variable "newrelic_license_key" {
  description = "License key do New Relic. Opcional por enquanto — a app ainda não tem o agente instalado na imagem Docker, só a variável já fica disponível pro Secret."
  type        = string
  sensitive   = true
  default     = ""
}

variable "newrelic_app_name" {
  type    = string
  default = "POS Tech"
}

variable "newrelic_enabled" {
  description = "Liga os dashboards New Relic provisionados via infra/newrelic.tf (newrelic_one_dashboard). Fica false até existir conta/License Key/User API Key configuradas — ver README, seção Observabilidade."
  type        = bool
  default     = false
}

variable "mail_username" {
  type      = string
  sensitive = true
  default   = ""
}

variable "mail_password" {
  type      = string
  sensitive = true
  default   = ""
}

variable "ghcr_username" {
  type      = string
  sensitive = true
}

variable "ghcr_token" {
  type      = string
  sensitive = true
}

variable "ghcr_email" {
  type    = string
  default = "deploy@example.com"
}

# --- Removidas em relação ao setup Minikube ---
# kubeconfig_path / kube_context   -> substituídos pela autenticação via
#                                      remote state + aws_eks_cluster_auth
#                                      em providers.tf
# mysql_root_password              -> não existe mais MySQL local; o RDS é
#                                      gerenciado pelo repositório infra-database
# deploy_metrics_server /
# metrics_server_chart_version     -> o metrics-server agora é provisionado
#                                      uma única vez em infra-kubernetes,
#                                      não por aplicação
