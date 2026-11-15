# language: pt
Funcionalidade: Saga da Ordem de Serviço entre os microsserviços
  Para atender a oficina em escala nacional
  Como atendente
  Quero que a OS percorra orçamento, pagamento e execução de forma consistente
  E que qualquer falha desfaça o que já foi feito nos outros serviços (compensação)

  Contexto:
    Dado que estou autenticado como atendente
    E que existe um cliente com um veículo cadastrado

  Cenário: Fluxo completo de uma OS passando pelos três microsserviços
    Quando eu abro uma OS com o serviço "svc-revisao" e 2 unidades da peça "item-filtro"
    Então a OS deve estar com status "recebida"
    E o os-service deve ter enviado o comando "billing.CreateQuote" ao billing-service
    Quando o billing-service responder "billing.QuoteCreated"
    Então a OS deve estar com status "aguardando_aprovacao"
    Quando o cliente aprovar o orçamento
    Então a OS deve estar com status "aguardando_pagamento"
    Quando o Mercado Pago confirmar o pagamento
    Então o os-service deve ter enviado o comando "execution.ReservePartsAndEnqueue" ao execution-service
    Quando o execution-service responder "execution.ExecutionQueued"
    Então a OS deve estar com status "aguardando_execucao"
    Quando o mecânico iniciar o diagnóstico
    E o mecânico iniciar o reparo
    E o mecânico finalizar a execução
    Então a OS deve estar com status "finalizada"
    E a saga deve estar no estado "COMPLETED"
    Quando o atendente registrar a entrega do veículo
    Então a OS deve estar com status "entregue"
    E o histórico da OS deve conter os status "recebida, aguardando_aprovacao, aguardando_pagamento, aguardando_execucao, em_diagnostico, em_execucao, finalizada, entregue"

  Cenário: Falta de peças no estoque estorna o pagamento e cancela a OS
    Dado uma OS com pagamento confirmado
    Quando o execution-service responder "execution.PartsReservationFailed"
    Então a saga deve estar no estado "COMPENSATING"
    E o os-service deve ter enviado o comando "billing.RefundPayment" ao billing-service
    Quando o billing-service responder "billing.PaymentRefunded"
    Então a OS deve estar com status "cancelada"
    E a saga deve estar no estado "CANCELLED"
    E a saga deve registrar a compensação "compensation:refund_payment"

  Cenário: Cliente recusa o orçamento
    Quando eu abro uma OS com o serviço "svc-alinhamento" e 1 unidades da peça "item-pastilha"
    E o billing-service responder "billing.QuoteCreated"
    E o cliente recusar o orçamento
    Então a OS deve estar com status "cancelada"
    E a saga deve estar no estado "CANCELLED"

  Cenário: Pagamento recusado cancela o orçamento antes de cancelar a OS
    Quando eu abro uma OS com o serviço "svc-revisao" e 1 unidades da peça "item-filtro"
    E o billing-service responder "billing.QuoteCreated"
    E o cliente aprovar o orçamento
    E o Mercado Pago recusar o pagamento
    Então a OS deve estar com status "aguardando_pagamento"
    E o os-service deve ter enviado o comando "billing.CancelQuote" ao billing-service
    Quando o billing-service responder "billing.QuoteCancelled"
    Então a OS deve estar com status "cancelada"

  Cenário: Cancelamento pelo atendimento durante a execução devolve peças e estorna
    Dado uma OS com pagamento confirmado
    E o execution-service responder "execution.ExecutionQueued"
    Quando o atendente cancelar a OS informando "cliente desistiu"
    Então o os-service deve ter enviado o comando "execution.CancelExecution" ao execution-service
    E o os-service deve ter enviado o comando "billing.RefundPayment" ao billing-service
    Quando o execution-service responder "execution.ExecutionCancelled"
    E o billing-service responder "billing.PaymentRefunded"
    Então a OS deve estar com status "cancelada"
