<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — separação em microsserviços.
 *
 * - Catálogo (services/items), estoque (stock_movements) e execução
 *   (service_order_services) passam a pertencer ao execution-service
 *   (DynamoDB); orçamento/aprovação ao billing-service (MySQL próprio).
 *   Nenhum serviço acessa o banco de outro, então essas tabelas saem daqui.
 * - A OS guarda um snapshot dos serviços/peças (nome e preço na abertura).
 * - Novas tabelas: estado da saga, passos da saga e histórico de status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->json('services')->nullable()->after('vehicle_id');
            $table->json('items')->nullable()->after('services');
            $table->timestamp('paid_at')->nullable()->after('quote_approved_at');
            $table->string('cancellation_reason', 500)->nullable()->after('paid_at');
        });

        $this->copyLinesToSnapshot();

        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropUnique(['approval_token']);
            $table->dropColumn('approval_token');
        });

        Schema::dropIfExists('service_order_items');
        Schema::dropIfExists('service_order_services');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('items');
        Schema::dropIfExists('services');

        Schema::create('service_order_status_history', function (Blueprint $table) {
            $table->id();
            $table->uuid('service_order_id');
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('source', 30);
            $table->timestamp('created_at', 6)->useCurrent();

            $table->foreign('service_order_id')->references('id')->on('service_orders')->cascadeOnDelete();
            $table->index(['service_order_id', 'id']);
        });

        Schema::create('service_order_sagas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_order_id')->unique();
            $table->string('state', 30)->index();
            $table->uuid('quote_id')->nullable();
            $table->uuid('payment_id')->nullable();
            $table->text('approval_url')->nullable();
            $table->text('reject_url')->nullable();
            $table->text('payment_url')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('pending_compensations')->nullable();
            $table->timestamp('deadline_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('service_order_id')->references('id')->on('service_orders')->cascadeOnDelete();
        });

        Schema::create('saga_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('saga_id');
            $table->uuid('service_order_id')->index();
            $table->string('step', 60);
            $table->string('outcome', 20);
            $table->string('message_type', 60)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->foreign('saga_id')->references('id')->on('service_order_sagas')->cascadeOnDelete();
            $table->index(['saga_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saga_steps');
        Schema::dropIfExists('service_order_sagas');
        Schema::dropIfExists('service_order_status_history');

        Schema::table('service_orders', function (Blueprint $table) {
            $table->string('approval_token', 64)->nullable()->unique();
            $table->dropColumn(['services', 'items', 'paid_at', 'cancellation_reason']);
        });
        // As tabelas de catálogo/estoque não são recriadas: os dados agora
        // vivem no execution-service.
    }

    /**
     * Converte as linhas normalizadas (service_order_services/items) no
     * snapshot JSON, preservando OS já existentes em produção.
     */
    private function copyLinesToSnapshot(): void
    {
        if (! Schema::hasTable('service_order_services')) {
            return;
        }

        foreach (DB::table('service_orders')->pluck('id') as $orderId) {
            $services = DB::table('service_order_services')
                ->leftJoin('services', 'services.id', '=', 'service_order_services.service_id')
                ->where('service_order_id', $orderId)
                ->get(['service_order_services.service_id', 'services.name', 'service_order_services.quantity', 'service_order_services.price'])
                ->map(fn ($s) => [
                    'service_id' => $s->service_id,
                    'name' => (string) $s->name,
                    'quantity' => (float) $s->quantity,
                    'unit_price' => (float) $s->price,
                ])->all();

            $items = DB::table('service_order_items')
                ->leftJoin('items', 'items.id', '=', 'service_order_items.item_id')
                ->where('service_order_id', $orderId)
                ->get(['service_order_items.item_id', 'items.name', 'service_order_items.quantity', 'service_order_items.price'])
                ->map(fn ($i) => [
                    'item_id' => $i->item_id,
                    'name' => (string) $i->name,
                    'quantity' => (float) $i->quantity,
                    'unit_price' => (float) $i->price,
                ])->all();

            DB::table('service_orders')->where('id', $orderId)->update([
                'services' => json_encode($services),
                'items' => json_encode($items),
            ]);
        }
    }
};
