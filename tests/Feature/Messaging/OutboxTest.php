<?php

namespace Tests\Feature\Messaging;

use App\Application\Messaging\Message;
use App\Infrastructure\Messaging\DatabaseProcessedMessageStore;
use App\Infrastructure\Messaging\InMemoryQueueTransport;
use App\Infrastructure\Messaging\OutboxMessagePublisher;
use App\Infrastructure\Messaging\OutboxRelay;
use App\Infrastructure\Messaging\QueueTransportInterface;
use App\Infrastructure\Messaging\ReceivedMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_stores_and_relay_sends_in_order(): void
    {
        $transport = new InMemoryQueueTransport;
        $this->app->instance(QueueTransportInterface::class, $transport);

        $publisher = $this->app->make(OutboxMessagePublisher::class);
        $first = Message::create('t.First', 'saga', ['n' => 1]);
        $second = Message::create('t.Second', 'saga', ['n' => 2]);
        $publisher->publish('q', $first);
        $publisher->publish('q', $second);

        $this->assertDatabaseCount('outbox_messages', 2);
        $this->assertSame([], $transport->pending('q'));

        $this->assertSame(2, $this->app->make(OutboxRelay::class)->relay());

        $sent = array_map(fn ($b) => Message::fromJson($b)->id, $transport->pending('q'));
        $this->assertSame([$first->id, $second->id], $sent);
        $this->assertSame(0, DB::table('outbox_messages')->whereNull('sent_at')->count());
        $this->assertSame(0, $this->app->make(OutboxRelay::class)->relay());
    }

    public function test_outbox_rolls_back_with_transaction(): void
    {
        $publisher = $this->app->make(OutboxMessagePublisher::class);

        try {
            DB::transaction(function () use ($publisher) {
                $publisher->publish('q', Message::create('t', 's', []));
                throw new \RuntimeException('falha no meio');
            });
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_relay_records_failure_and_stops(): void
    {
        $failing = new class implements QueueTransportInterface
        {
            public function send(string $queue, string $body, array $attributes = []): void
            {
                throw new \RuntimeException('sqs fora');
            }

            public function receive(string $queue, int $maxMessages = 10, int $waitSeconds = 20): array
            {
                return [];
            }

            public function delete(string $queue, ReceivedMessage $message): void {}
        };
        $this->app->instance(QueueTransportInterface::class, $failing);

        $publisher = $this->app->make(OutboxMessagePublisher::class);
        $publisher->publish('q', Message::create('t', 's', []));
        $publisher->publish('q', Message::create('t', 's', []));

        $this->assertSame(0, $this->app->make(OutboxRelay::class)->relay());
        $this->assertSame(1, DB::table('outbox_messages')->where('attempts', 1)->count());
        $this->assertSame('sqs fora', DB::table('outbox_messages')->whereNotNull('last_error')->value('last_error'));
    }

    public function test_relay_command_once(): void
    {
        $this->app->instance(QueueTransportInterface::class, new InMemoryQueueTransport);
        $this->app->make(OutboxMessagePublisher::class)->publish('q', Message::create('t', 's', []));

        $this->artisan('outbox:relay', ['--once' => true])
            ->expectsOutputToContain('1 mensagem(ns) enviada(s).')
            ->assertSuccessful();
    }

    public function test_processed_store_runs_handler_once_and_rolls_back_on_failure(): void
    {
        $store = new DatabaseProcessedMessageStore;
        $message = Message::create('t', 's', []);
        $calls = 0;

        try {
            $store->runOnce($message, function () {
                throw new \RuntimeException('handler falhou');
            });
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('processed_messages', 0);

        $this->assertTrue($store->runOnce($message, function () use (&$calls) {
            $calls++;
        }));
        $this->assertFalse($store->runOnce($message, function () use (&$calls) {
            $calls++;
        }));
        $this->assertSame(1, $calls);
    }
}
