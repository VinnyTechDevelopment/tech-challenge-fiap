<?php

namespace Tests\Support;

use App\Application\Messaging\Message;
use App\Application\Messaging\MessageTypes;
use App\Application\ServiceOrder\Ports\CatalogClientInterface;
use App\Infrastructure\Messaging\InMemoryQueueTransport;
use App\Infrastructure\Messaging\MessageConsumer;
use App\Infrastructure\Messaging\OutboxRelay;
use App\Infrastructure\Messaging\QueueTransportInterface;
use App\Infrastructure\Persistence\Eloquent\Models\CustomerModel;
use App\Infrastructure\Persistence\Eloquent\Models\VehicleModel;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;

/**
 * Infra de teste da saga no os-service: transporte SQS em memória, catálogo
 * fake (execution-service) e "simuladores" que respondem como o
 * billing-service e o execution-service responderiam.
 */
class SagaTestHarness
{
    public InMemoryQueueTransport $transport;

    public FakeCatalogClient $catalog;

    public function __construct(private Application $app)
    {
        $this->transport = new InMemoryQueueTransport;
        $this->catalog = new FakeCatalogClient;
        $app->instance(QueueTransportInterface::class, $this->transport);
        $app->instance(CatalogClientInterface::class, $this->catalog);
    }

    public function givenStaff(): User
    {
        return User::factory()->create(['role' => 'atendente']);
    }

    /**
     * @return array{customer_id: string, vehicle_id: string}
     */
    public function givenCustomerWithVehicle(?User $staff = null, ?string $document = null): array
    {
        $staff ??= $this->givenStaff();
        $audit = ['created_user_id' => $staff->id, 'updated_user_id' => $staff->id];

        $customer = CustomerModel::create($audit + [
            'id' => Str::uuid()->toString(),
            'name' => 'Maria Cliente',
            'email' => 'maria'.Str::random(5).'@example.com',
            'phone' => '11999990000',
            'document' => $document ?? (string) random_int(10000000000, 99999999999),
        ]);

        $vehicle = VehicleModel::create($audit + [
            'id' => Str::uuid()->toString(),
            'brand' => 'Fiat',
            'model' => 'Argo',
            'year' => 2022,
            'plate' => 'BRA'.random_int(1, 9).'E'.random_int(10, 99),
        ]);

        return ['customer_id' => $customer->id, 'vehicle_id' => $vehicle->id];
    }

    /**
     * Envia ao SQS o que está no outbox e devolve (consumindo) os comandos
     * publicados na fila informada.
     *
     * @return Message[]
     */
    public function takeCommands(string $queue): array
    {
        $this->app->make(OutboxRelay::class)->relay();

        $messages = [];
        foreach ($this->transport->receive($queue, 100, 0) as $received) {
            $messages[] = Message::fromJson($received->body);
            $this->transport->delete($queue, $received);
        }

        return $messages;
    }

    public function lastCommand(string $queue, string $type): Message
    {
        $commands = array_values(array_filter($this->takeCommands($queue), fn (Message $m) => $m->type === $type));

        if ($commands === []) {
            throw new \RuntimeException("Nenhum comando {$type} publicado em {$queue}.");
        }

        return end($commands);
    }

    /**
     * Simula a resposta de um participante e a processa no orquestrador
     * (exatamente como o worker "messaging:consume" faria).
     */
    public function reply(Message $command, string $type, array $payload = []): Message
    {
        $reply = $command->reply($type, ['service_order_id' => $command->get('service_order_id')] + $payload)
            ->withSource(str_starts_with($type, 'billing.') ? 'billing-service' : 'execution-service');

        return $this->deliver($reply);
    }

    /**
     * Evento iniciado pelo participante (não é resposta direta a um comando).
     */
    public function event(string $sagaId, string $serviceOrderId, string $type, array $payload = []): Message
    {
        return $this->deliver(Message::create($type, $sagaId, ['service_order_id' => $serviceOrderId] + $payload));
    }

    public function deliver(Message $message): Message
    {
        $this->transport->send(MessageTypes::QUEUE_OS_SAGA_REPLIES, $message->toJson());
        $this->app->make(MessageConsumer::class)->consumeBatch(MessageTypes::QUEUE_OS_SAGA_REPLIES, 10, 0);

        return $message;
    }
}
