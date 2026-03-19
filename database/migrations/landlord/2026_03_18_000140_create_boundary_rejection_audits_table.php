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
        Schema::connection('landlord')->create('boundary_rejection_audits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('tenant_slug', 120)->nullable();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email')->nullable();
            $table->string('direction', 20);
            $table->string('endpoint', 255);
            $table->string('route_name', 120)->nullable();
            $table->string('method', 10);
            $table->string('host', 120)->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('slot', 20)->nullable();
            $table->string('code', 80);
            $table->string('message', 255);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('request_id', 120)->nullable();
            $table->uuid('correlation_id');
            $table->json('payload_json')->nullable();
            $table->json('headers_json')->nullable();
            $table->json('context_json')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_slug', 'occurred_at']);
            $table->index(['code', 'occurred_at']);
            $table->index(['provider', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('boundary_rejection_audits');
    }
};
