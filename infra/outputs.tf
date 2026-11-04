output "namespace" {
  description = "Namespace onde a stack foi provisionada"
  value       = kubernetes_namespace_v1.postech.metadata[0].name
}

output "app_deployment_name" {
  description = "Nome do Deployment da aplicação"
  value       = kubernetes_deployment_v1.app.metadata[0].name
}

output "app_image" {
  description = "Imagem efetivamente implantada (repositório:tag)"
  value       = "${var.image_repository}:${var.image_tag}"
}

output "migrate_job_name" {
  description = "Nome do Job de migration executado neste apply"
  value       = kubernetes_job_v1.migrate.metadata[0].name
}

output "hpa_name" {
  description = "Nome do HorizontalPodAutoscaler da aplicação"
  value       = kubernetes_horizontal_pod_autoscaler_v2.app.metadata[0].name
}

output "saga_replies_queue_url" {
  description = "URL da fila SQS de respostas da saga (entrada do os-service)"
  value       = aws_sqs_queue.saga_replies.url
}

output "worker_deployment_name" {
  description = "Deployment dos processos de background (consumer, outbox, scheduler)"
  value       = kubernetes_deployment_v1.worker.metadata[0].name
}
