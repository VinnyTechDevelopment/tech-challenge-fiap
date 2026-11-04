<?php

namespace App\Application\Saga;

use App\Application\Messaging\Message;
use App\Application\Messaging\MessagePublisherInterface;
use App\Application\Messaging\MessageTypes;
use App\Domain\Saga\Entities\ServiceOrderSaga;
use App\Domain\Saga\Interfaces\ServiceOrderSagaRepositoryInterface;
use App\Domain\ServiceOrder\Entities\ServiceOrder;
use App\Domain\ServiceOrder\Events\ServiceOrderPaymentRequested;
use App\Domain\ServiceOrder\Events\ServiceOrderQuoteSent;
use App\Domain\ServiceOrder\Events\ServiceOrderStatusChanged;
use App\Domain\ServiceOrder\Interfaces\ServiceOrderRepositoryInterface;
use App\Infrastructure\Observability\Tracer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orquestrador central da Saga de Ordem de Serviço (Fase 4).
 *
 *   abrir OS -> CreateQuote (billing) -> cliente aprova -> pagamento (Mercado Pago)
 *   -> ReservePartsAndEnqueue (execution) -> diagnóstico/reparo -> finalizada
 *
 * Cada resposta dos participantes chega pela fila "os-saga-replies" e é
 * tratada dentro de uma transação MySQL junto com o outbox (ver
 * DatabaseProcessedMessageStore/OutboxMessagePublisher): o novo estado da
 * saga, o status da OS, o histórico e os próximos comandos são gravados
 * atomicamente.
 *
 * Compensações (rollback semântico):
 *   - orçamento recusado / falha ao gerar orçamento -> OS cancelada
 *   - pagamento recusado ou prazo expirado          -> CancelQuote          -> OS cancelada
 *   - sem peças em estoque                          -> RefundPayment        -> OS cancelada
 *   - cancelamento pelo atendimento                 -> CancelQuote ou
 *                                                      CancelExecution + RefundPayment -> OS cancelada
 */
class ServiceOrderSagaOrchestrator
{
    private const EXECUTION_STATUS_MAP = [
        'in_diagnosis' => ServiceOrder::STATUS_EM_DIAGNOSTICO,
        'in_repair' => ServiceOrder::STATUS_EM_EXECUCAO,
    ];

    public function __construct(
        private ServiceOrderSagaRepositoryInterface $sagas,
        private ServiceOrderRepositoryInterface $orders,
        private MessagePublisherInterface $publisher,
        private Tracer $tracer,
        private int $approvalTimeoutMinutes = 2880,
        private int $paymentTimeoutMinutes = 1440,
    ) {}

    /**
     * Passo inicial — deve ser chamado dentro da transação que gravou a OS.
     */
    public function start(ServiceOrder $order): ServiceOrderSaga
    {
        $saga = ServiceOrderSaga::start($order->id);
        $this->sagas->save($saga);

        $this->publisher->publish(MessageTypes::QUEUE_BILLING_COMMANDS, Message::create(
            MessageTypes::BILLING_CREATE_QUOTE,
            $saga->id,
            [
                'service_order_id' => $order->id,
                'customer_id' => $order->customerId,
                'services' => $order->services,
                'items' => $order->items,
            ]
        ));

        $this->step($saga, 'create_quote', 'started', MessageTypes::BILLING_CREATE_QUOTE, ['total' => $order->totalBudget]);

        return $saga;
    }

    /**
     * Trata uma resposta/evento de billing-service ou execution-service.
     */
    public function handle(Message $message): void
    {
        $saga = $this->sagas->findForUpdate($message->sagaId);

        if (! $saga) {
            Log::warning('saga.not_found', ['saga_id' => $message->sagaId, 'type' => $message->type]);

            return;
        }

        $order = $this->orders->findById($saga->serviceOrderId);

        if (! $order) {
            $this->step($saga, 'service_order_missing', 'ignored', $message->type);

            return;
        }

        try {
            $this->apply($saga, $order, $message);
        } catch (\DomainException $e) {
            // Mensagem fora de ordem/estado (ex.: status de execução chegando
            // depois da OS finalizada). Registra e segue — reprocessar não
            // mudaria o resultado.
            $this->step($saga, 'unexpected_message', 'ignored', $message->type, ['reason' => $e->getMessage(), 'state' => $saga->state]);

            return;
        }

        $this->sagas->update($saga);
    }

    /**
     * Cancelamento solicitado pelo atendimento: dispara as compensações
     * adequadas ao ponto em que a saga está.
     */
    public function cancelByStaff(string $serviceOrderId, string $reason): ServiceOrderSaga
    {
        return DB::transaction(function () use ($serviceOrderId, $reason): ServiceOrderSaga {
            $found = $this->sagas->findByServiceOrderId($serviceOrderId)
                ?? throw new \DomainException('Saga da ordem de servico nao encontrada.');
            $saga = $this->sagas->findForUpdate($found->id);

            $compensations = match ($saga->state) {
                ServiceOrderSaga::QUOTE_REQUESTED,
                ServiceOrderSaga::AWAITING_APPROVAL,
                ServiceOrderSaga::AWAITING_PAYMENT => [ServiceOrderSaga::COMPENSATION_CANCEL_QUOTE],
                ServiceOrderSaga::EXECUTION_REQUESTED,
                ServiceOrderSaga::IN_EXECUTION => [ServiceOrderSaga::COMPENSATION_CANCEL_EXECUTION, ServiceOrderSaga::COMPENSATION_REFUND_PAYMENT],
                default => throw new \DomainException("A ordem de servico nao pode ser cancelada (saga em {$saga->state})."),
            };

            $this->startCompensation($saga, "Cancelada pelo atendimento: {$reason}", $compensations, null);
            $this->sagas->update($saga);

            return $saga;
        });
    }

    /**
     * Compensa sagas paradas esperando o cliente (aprovação ou pagamento)
     * além do prazo. Executado pelo scheduler ("saga:timeouts").
     */
    public function expireTimedOut(string $now): int
    {
        $expired = 0;

        foreach ($this->sagas->findExpired($now) as $candidate) {
            $expired += DB::transaction(function () use ($candidate, $now): int {
                $saga = $this->sagas->findForUpdate($candidate->id);

                if (! $saga || ! $saga->isExpired($now)) {
                    return 0;
                }

                $reason = $saga->state === ServiceOrderSaga::AWAITING_APPROVAL
                    ? 'Prazo para aprovacao do orcamento expirou.'
                    : 'Prazo para pagamento expirou.';

                $this->startCompensation($saga, $reason, [ServiceOrderSaga::COMPENSATION_CANCEL_QUOTE], 'timeout');
                $this->sagas->update($saga);

                return 1;
            });
        }

        return $expired;
    }

    private function apply(ServiceOrderSaga $saga, ServiceOrder $order, Message $message): void
    {
        $now = now()->toIso8601String();

        switch ($message->type) {
            case MessageTypes::BILLING_QUOTE_CREATED:
                $saga->quoteCreated(
                    (string) $message->get('quote_id'),
                    $message->get('approval_url'),
                    $message->get('reject_url'),
                    now()->addMinutes($this->approvalTimeoutMinutes)->toIso8601String(),
                );
                $this->moveOrder($order, fn () => $order->quoteSent($now), 'billing');
                event(new ServiceOrderQuoteSent($order, $saga->approvalUrl, $saga->rejectUrl));
                $this->step($saga, 'create_quote', 'completed', $message->type, ['quote_id' => $saga->quoteId, 'total' => $message->get('total')]);
                break;

            case MessageTypes::BILLING_QUOTE_CREATION_FAILED:
                $this->finishCancelled($saga, $order, 'Falha ao gerar orcamento: '.$message->get('reason'));
                $this->step($saga, 'create_quote', 'failed', $message->type, ['reason' => $message->get('reason')]);
                break;

            case MessageTypes::BILLING_QUOTE_APPROVED:
                $saga->quoteApproved(
                    (string) $message->get('payment_id'),
                    $message->get('payment_url'),
                    now()->addMinutes($this->paymentTimeoutMinutes)->toIso8601String(),
                );
                $this->moveOrder($order, fn () => $order->quoteApproved($now), 'billing');
                event(new ServiceOrderPaymentRequested($order, $saga->paymentUrl));
                $this->step($saga, 'quote_approval', 'completed', $message->type, ['payment_id' => $saga->paymentId]);
                break;

            case MessageTypes::BILLING_QUOTE_REJECTED:
                $this->finishCancelled($saga, $order, 'Orcamento recusado pelo cliente.');
                $this->step($saga, 'quote_approval', 'failed', $message->type, ['reason' => 'rejected_by_customer']);
                break;

            case MessageTypes::BILLING_PAYMENT_CONFIRMED:
                $saga->paymentConfirmed();
                $order->paymentConfirmed($now);
                $this->orders->update($order);
                $this->publisher->publish(MessageTypes::QUEUE_EXECUTION_COMMANDS, $message->reply(
                    MessageTypes::EXECUTION_RESERVE_AND_ENQUEUE,
                    [
                        'service_order_id' => $order->id,
                        'services' => array_map(static fn (array $s) => [
                            'service_id' => $s['service_id'],
                            'name' => $s['name'] ?? '',
                            'quantity' => $s['quantity'],
                        ], $order->services),
                        'items' => array_map(static fn (array $i) => [
                            'item_id' => $i['item_id'],
                            'quantity' => $i['quantity'],
                        ], $order->items),
                    ]
                ));
                $this->step($saga, 'payment', 'completed', $message->type, ['amount' => $message->get('amount')]);
                $this->step($saga, 'execution_reservation', 'started', MessageTypes::EXECUTION_RESERVE_AND_ENQUEUE);
                break;

            case MessageTypes::BILLING_PAYMENT_FAILED:
                $this->step($saga, 'payment', 'failed', $message->type, ['reason' => $message->get('reason')]);
                $this->startCompensation($saga, 'Pagamento recusado: '.$message->get('reason'), [ServiceOrderSaga::COMPENSATION_CANCEL_QUOTE], $message);
                break;

            case MessageTypes::EXECUTION_QUEUED:
                $saga->executionQueued();
                $this->moveOrder($order, fn () => $order->changeStatus(ServiceOrder::STATUS_AGUARDANDO_EXECUCAO), 'execution');
                $this->step($saga, 'execution_reservation', 'completed', $message->type);
                break;

            case MessageTypes::EXECUTION_RESERVATION_FAILED:
                $this->step($saga, 'execution_reservation', 'failed', $message->type, ['reason' => $message->get('reason')]);
                $this->startCompensation($saga, 'Falha ao reservar pecas: '.$message->get('reason'), [ServiceOrderSaga::COMPENSATION_REFUND_PAYMENT], $message);
                break;

            case MessageTypes::EXECUTION_STATUS_CHANGED:
                $this->ensureInExecution($saga, $order);
                $target = self::EXECUTION_STATUS_MAP[(string) $message->get('status')]
                    ?? throw new \DomainException('Status de execucao desconhecido: '.$message->get('status'));
                $this->advanceOrderTo($order, $target);
                $this->step($saga, 'execution', 'progress', $message->type, ['status' => $message->get('status')]);
                break;

            case MessageTypes::EXECUTION_FINISHED:
                $this->ensureInExecution($saga, $order);
                $this->advanceOrderTo($order, ServiceOrder::STATUS_FINALIZADA);
                $saga->completed();
                $this->step($saga, 'execution', 'completed', $message->type);
                break;

            case MessageTypes::BILLING_QUOTE_CANCELLED:
                $this->confirmCompensation($saga, $order, ServiceOrderSaga::COMPENSATION_CANCEL_QUOTE, $message);
                break;

            case MessageTypes::EXECUTION_CANCELLED:
                $this->confirmCompensation($saga, $order, ServiceOrderSaga::COMPENSATION_CANCEL_EXECUTION, $message);
                break;

            case MessageTypes::BILLING_PAYMENT_REFUNDED:
                if ($message->get('late_payment')) {
                    // Pagamento que chegou depois da saga já compensada: o
                    // billing estornou sozinho. Só auditoria.
                    $this->step($saga, 'late_payment', 'compensated', $message->type, ['amount' => $message->get('amount')]);
                    break;
                }
                $this->confirmCompensation($saga, $order, ServiceOrderSaga::COMPENSATION_REFUND_PAYMENT, $message);
                break;

            case MessageTypes::BILLING_PAYMENT_REFUND_FAILED:
                $saga->compensationFailed((string) $message->get('reason'));
                $this->step($saga, 'compensation:'.ServiceOrderSaga::COMPENSATION_REFUND_PAYMENT, 'failed', $message->type, ['reason' => $message->get('reason')]);
                $this->tracer->recordEvent('SagaCompensationFailed', ['sagaId' => $saga->id, 'serviceOrderId' => $order->id, 'reason' => (string) $message->get('reason')]);
                break;

            default:
                throw new \DomainException("Mensagem nao tratada pelo orquestrador: {$message->type}");
        }
    }

    /**
     * @param  string[]  $compensations
     */
    private function startCompensation(ServiceOrderSaga $saga, string $reason, array $compensations, Message|string|null $trigger): void
    {
        $saga->compensate($reason, $compensations);
        $order = $this->orders->findById($saga->serviceOrderId);

        $commands = [
            ServiceOrderSaga::COMPENSATION_CANCEL_QUOTE => [MessageTypes::QUEUE_BILLING_COMMANDS, MessageTypes::BILLING_CANCEL_QUOTE],
            ServiceOrderSaga::COMPENSATION_REFUND_PAYMENT => [MessageTypes::QUEUE_BILLING_COMMANDS, MessageTypes::BILLING_REFUND_PAYMENT],
            ServiceOrderSaga::COMPENSATION_CANCEL_EXECUTION => [MessageTypes::QUEUE_EXECUTION_COMMANDS, MessageTypes::EXECUTION_CANCEL],
        ];

        foreach ($saga->pendingCompensations as $compensation) {
            [$queue, $type] = $commands[$compensation];
            $payload = ['service_order_id' => $saga->serviceOrderId, 'quote_id' => $saga->quoteId, 'payment_id' => $saga->paymentId, 'reason' => $reason];

            $this->publisher->publish($queue, $trigger instanceof Message
                ? $trigger->reply($type, $payload)
                : Message::create($type, $saga->id, $payload));

            $this->step($saga, "compensation:{$compensation}", 'started', $type, ['reason' => $reason, 'trigger' => is_string($trigger) ? $trigger : $trigger?->type]);
        }

        if ($saga->state === ServiceOrderSaga::CANCELLED && $order) {
            $this->finishCancelled($saga, $order, $reason);
        }
    }

    private function confirmCompensation(ServiceOrderSaga $saga, ServiceOrder $order, string $compensation, Message $message): void
    {
        if (! $saga->isWaitingFor($compensation)) {
            $this->step($saga, "compensation:{$compensation}", 'ignored', $message->type, ['state' => $saga->state]);

            return;
        }

        $done = $saga->compensationConfirmed($compensation);
        $this->step($saga, "compensation:{$compensation}", 'compensated', $message->type, ['amount' => $message->get('amount')]);

        if ($done) {
            $this->finishCancelled($saga, $order, (string) $saga->failureReason);
        }
    }

    private function finishCancelled(ServiceOrderSaga $saga, ServiceOrder $order, string $reason): void
    {
        if ($saga->state !== ServiceOrderSaga::CANCELLED) {
            $saga->cancel($reason);
        }

        if ($order->status !== ServiceOrder::STATUS_CANCELADA) {
            $this->moveOrder($order, fn () => $order->cancel($reason), 'saga');
        }

        $this->step($saga, 'saga', 'cancelled', null, ['reason' => $reason]);
    }

    /**
     * SQS Standard não garante ordem: se um status de execução chegar antes
     * do ExecutionQueued, considera a OS enfileirada.
     */
    private function ensureInExecution(ServiceOrderSaga $saga, ServiceOrder $order): void
    {
        if ($saga->state === ServiceOrderSaga::EXECUTION_REQUESTED) {
            $saga->executionQueued();
            $this->advanceOrderTo($order, ServiceOrder::STATUS_AGUARDANDO_EXECUCAO);
        }

        if ($saga->state !== ServiceOrderSaga::IN_EXECUTION) {
            throw new \DomainException("Saga em '{$saga->state}' nao esta em execucao.");
        }
    }

    /**
     * Avança a OS pelo caminho de execução até o status alvo, aplicando os
     * passos intermediários (mensagens podem chegar fora de ordem).
     */
    private function advanceOrderTo(ServiceOrder $order, string $target): void
    {
        $path = [
            ServiceOrder::STATUS_AGUARDANDO_EXECUCAO,
            ServiceOrder::STATUS_EM_DIAGNOSTICO,
            ServiceOrder::STATUS_EM_EXECUCAO,
            ServiceOrder::STATUS_FINALIZADA,
        ];

        $targetIndex = array_search($target, $path, true);
        $currentIndex = array_search($order->status, $path, true);

        if ($currentIndex !== false && $currentIndex >= $targetIndex) {
            return; // já está nesse ponto (mensagem atrasada)
        }

        $startIndex = $currentIndex === false ? 0 : $currentIndex + 1;
        for ($i = $startIndex; $i <= $targetIndex; $i++) {
            $this->moveOrder($order, fn () => $order->changeStatus($path[$i]), 'execution');
        }
    }

    private function moveOrder(ServiceOrder $order, callable $transition, string $source): void
    {
        $from = $order->status;
        $transition();
        $this->orders->update($order);
        $this->orders->recordStatusChange($order->id, $from, $order->status, $source);
        event(new ServiceOrderStatusChanged($order, $from));
    }

    private function step(ServiceOrderSaga $saga, string $step, string $outcome, ?string $messageType, array $details = []): void
    {
        $this->sagas->recordStep($saga->id, $saga->serviceOrderId, $step, $outcome, $messageType, $details);

        $this->tracer->recordEvent('SagaStep', [
            'sagaId' => $saga->id,
            'serviceOrderId' => $saga->serviceOrderId,
            'step' => $step,
            'outcome' => $outcome,
            'state' => $saga->state,
            'messageType' => $messageType,
        ]);

        Log::info('saga.step', [
            'saga_id' => $saga->id,
            'service_order_id' => $saga->serviceOrderId,
            'step' => $step,
            'outcome' => $outcome,
            'state' => $saga->state,
            'message_type' => $messageType,
        ]);
    }
}
