# Fila de entrada do os-service (orquestrador da saga): respostas e eventos
# do billing-service e do execution-service. Cada microsserviço é dono da
# própria fila de entrada; os outros só publicam nela (por nome).
#
# Nome físico: "<ambiente>-os-saga-replies" (MESSAGING_QUEUE_PREFIX), para
# produção e homologação não misturarem mensagens na mesma conta AWS.

resource "aws_sqs_queue" "saga_replies_dlq" {
  name                      = "${var.environment}-os-saga-replies-dlq"
  message_retention_seconds = 1209600 # 14 dias para investigar
}

resource "aws_sqs_queue" "saga_replies" {
  name                       = "${var.environment}-os-saga-replies"
  visibility_timeout_seconds = 60
  message_retention_seconds  = 345600 # 4 dias
  receive_wait_time_seconds  = 20     # long polling

  # Depois de 5 falhas de processamento a mensagem vai para a DLQ (alerta no
  # dashboard New Relic) em vez de ficar em loop.
  redrive_policy = jsonencode({
    deadLetterTargetArn = aws_sqs_queue.saga_replies_dlq.arn
    maxReceiveCount     = 5
  })
}
