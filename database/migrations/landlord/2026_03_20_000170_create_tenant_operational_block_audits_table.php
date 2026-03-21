<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('tenant_operational_block_audits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('tenant_slug', 120)->nullable();
            $table->string('channel', 30);
            $table->string('outcome', 30);
            $table->string('reason_code', 80);
            $table->string('surface', 120)->nullable();
            $table->string('route_name', 120)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('endpoint', 255)->nullable();
            $table->string('host', 120)->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('request_id', 120)->nullable();
            $table->uuid('correlation_id');
            $table->json('context_json')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'channel', 'occurred_at']);
            $table->index(['tenant_id', 'reason_code', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('tenant_operational_block_audits');
    }
};
