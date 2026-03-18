<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('tenant')->create('messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignUlid('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->foreignUlid('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignUlid('automation_id')->nullable()->constrained('automations')->nullOnDelete();
            $table->string('direction', 20);
            $table->string('channel', 20);
            $table->string('provider', 50)->nullable();
            $table->string('external_message_id', 191)->nullable();
            $table->string('thread_key', 120);
            $table->string('type', 30);
            $table->string('status', 20)->default('queued');
            $table->text('body_text')->nullable();
            $table->json('payload_json');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['thread_key', 'channel']);
            $table->index(['status', 'channel']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('messages');
    }
};
