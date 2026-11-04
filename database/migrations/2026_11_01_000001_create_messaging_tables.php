<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            // sequence garante a ordem de envio (timestamps podem empatar).
            $table->id('sequence');
            $table->uuid('id')->unique();
            $table->string('queue', 100);
            $table->string('type', 100);
            $table->string('saga_id', 64)->index();
            $table->longText('body');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->timestamp('sent_at')->nullable();

            $table->index(['sent_at', 'sequence']);
        });

        Schema::create('processed_messages', function (Blueprint $table) {
            $table->string('message_id', 64)->primary();
            $table->string('type', 100);
            $table->string('saga_id', 64)->index();
            $table->timestamp('processed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_messages');
        Schema::dropIfExists('outbox_messages');
    }
};
