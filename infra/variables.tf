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

variable "image_repository" {
  description = "Repositório da imagem da aplicação (sem a tag)"
  type        = string
  default     = "ghcr.io/viniciussalvarenga/tech-challenge"
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
