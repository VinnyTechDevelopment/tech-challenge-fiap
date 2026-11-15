<?php

namespace App\Domain\Saga\Entities;

use Illuminate\Support\Str;

/**
 * Estado persistido da Saga orquestrada de uma OS.
 *
 * Caminho feliz:
 *   QUOTE_REQUESTED -> AWAITING_APPROVAL -> AWAITING_PAYMENT
 *   -> EXECUTION_REQUESTED -> IN_EXECUTION -> COMPLETED
 *
 * Falhas: COMPENSATING (aguardando confirmação das compensações enviadas)
 *   -> CANCELLED; ou FAILED quando uma compensação não pôde ser concluída
 *   (ex.: estorno recusado pelo provedor) e precisa de intervenção manual.
 */
class ServiceOrderSaga
{
    public const QUOTE_REQUESTED = 'QUOTE_REQUESTED';

    public const AWAITING_APPROVAL = 'AWAITING_APPROVAL';

    public const AWAITING_PAYMENT = 'AWAITING_PAYMENT';

    public const EXECUTION_REQUESTED = 'EXECUTION_REQUESTED';

    public const IN_EXECUTION = 'IN_EXECUTION';

    public const COMPLETED = 'COMPLETED';

    public const COMPENSATING = 'COMPENSATING';

    public const CANCELLED = 'CANCELLED';

    public const FAILED = 'FAILED';

    // Compensações que podem estar pendentes de confirmação.
    public const COMPENSATION_CANCEL_QUOTE = 'cancel_quote';

    public const COMPENSATION_REFUND_PAYMENT = 'refund_payment';

    public const COMPENSATION_CANCEL_EXECUTION = 'cancel_execution';

    private const STATES = [
        self::QUOTE_REQUESTED, self::AWAITING_APPROVAL, self::AWAITING_PAYMENT,
        self::EXECUTION_REQUESTED, self::IN_EXECUTION, self::COMPLETED,
        self::COMPENSATING, self::CANCELLED, self::FAILED,
    ];

    /**
     * @param  string[]  $pendingCompensations
     */
    public function __construct(
        public readonly string $id,
        public readonly string $serviceOrderId,
        public string $state,
        public ?string $quoteId = null,
        public ?string $paymentId = null,
        public ?string $approvalUrl = null,
        public ?string $rejectUrl = null,
        public ?string $paymentUrl = null,
        public ?string $failureReason = null,
        public array $pendingCompensations = [],
        public ?string $deadlineAt = null,
    ) {
        if (! in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException("Estado de saga invalido: {$state}");
        }
    }

    public static function start(string $serviceOrderId): self
    {
        return new self(Str::uuid()->toString(), $serviceOrderId, self::QUOTE_REQUESTED);
    }

    public function quoteCreated(string $quoteId, ?string $approvalUrl, ?string $rejectUrl, string $deadlineAt): void
    {
        $this->expect(self::QUOTE_REQUESTED);
        $this->state = self::AWAITING_APPROVAL;
        $this->quoteId = $quoteId;
        $this->approvalUrl = $approvalUrl;
        $this->rejectUrl = $rejectUrl;
        $this->deadlineAt = $deadlineAt;
    }

    public function quoteApproved(string $paymentId, ?string $paymentUrl, string $deadlineAt): void
    {
        $this->expect(self::AWAITING_APPROVAL);
        $this->state = self::AWAITING_PAYMENT;
        $this->paymentId = $paymentId;
        $this->paymentUrl = $paymentUrl;
        $this->deadlineAt = $deadlineAt;
    }

    public function paymentConfirmed(): void
    {
        $this->expect(self::AWAITING_PAYMENT);
        $this->state = self::EXECUTION_REQUESTED;
        $this->deadlineAt = null;
    }

    public function executionQueued(): void
    {
        $this->expect(self::EXECUTION_REQUESTED);
        $this->state = self::IN_EXECUTION;
    }

    public function completed(): void
    {
        $this->expect(self::IN_EXECUTION);
        $this->state = self::COMPLETED;
    }

    /**
     * Encerra a saga sem compensações pendentes (ex.: orçamento recusado —
     * o billing já registrou a recusa; nada a desfazer em outros serviços).
     */
    public function cancel(string $reason): void
    {
        $this->assertNotFinished();
        $this->state = self::CANCELLED;
        $this->failureReason ??= $reason;
        $this->deadlineAt = null;
        $this->pendingCompensations = [];
    }

    /**
     * Inicia a compensação: a saga só termina (CANCELLED) quando todas as
     * compensações enviadas forem confirmadas.
     *
     * @param  string[]  $compensations
     */
    public function compensate(string $reason, array $compensations): void
    {
        $this->assertNotFinished();

        if ($compensations === []) {
            $this->cancel($reason);

            return;
        }

        $this->state = self::COMPENSATING;
        $this->failureReason = $reason;
        $this->deadlineAt = null;
        $this->pendingCompensations = array_values(array_unique($compensations));
    }

    /**
     * @return bool true quando não resta compensação pendente (saga cancelada)
     */
    public function compensationConfirmed(string $compensation): bool
    {
        $this->expect(self::COMPENSATING);
        $this->pendingCompensations = array_values(array_diff($this->pendingCompensations, [$compensation]));

        if ($this->pendingCompensations === []) {
            $this->state = self::CANCELLED;

            return true;
        }

        return false;
    }

    public function compensationFailed(string $reason): void
    {
        $this->expect(self::COMPENSATING);
        $this->state = self::FAILED;
        $this->failureReason = "{$this->failureReason} | compensacao falhou: {$reason}";
    }

    public function isWaitingFor(string $compensation): bool
    {
        return $this->state === self::COMPENSATING && in_array($compensation, $this->pendingCompensations, true);
    }

    public function isFinished(): bool
    {
        return in_array($this->state, [self::COMPLETED, self::CANCELLED, self::FAILED], true);
    }

    public function isExpired(string $now): bool
    {
        return $this->deadlineAt !== null
            && in_array($this->state, [self::AWAITING_APPROVAL, self::AWAITING_PAYMENT], true)
            && strtotime($this->deadlineAt) <= strtotime($now);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'service_order_id' => $this->serviceOrderId,
            'state' => $this->state,
            'quote_id' => $this->quoteId,
            'payment_id' => $this->paymentId,
            'approval_url' => $this->approvalUrl,
            'reject_url' => $this->rejectUrl,
            'payment_url' => $this->paymentUrl,
            'failure_reason' => $this->failureReason,
            'pending_compensations' => $this->pendingCompensations,
            'deadline_at' => $this->deadlineAt,
        ];
    }

    private function expect(string $state): void
    {
        if ($this->state !== $state) {
            throw new \DomainException("Saga em '{$this->state}', esperado '{$state}'.");
        }
    }

    private function assertNotFinished(): void
    {
        if ($this->isFinished()) {
            throw new \DomainException("Saga ja encerrada ({$this->state}).");
        }
    }
}
