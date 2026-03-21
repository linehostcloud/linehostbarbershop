<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->create('landlord_tenant_detail_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete()->unique();
            $table->string('refresh_status', 30)->default('ready');
            $table->string('last_refresh_source', 30)->nullable();
            $table->string('last_refresh_error', 255)->nullable();
            $table->json('payload_json')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->dateTime('last_refresh_started_at')->nullable();
            $table->dateTime('last_refresh_completed_at')->nullable();
            $table->dateTime('last_refresh_failed_at')->nullable();
            $table->timestamps();

            $table->index(['refresh_status', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('landlord_tenant_detail_snapshots');
    }
};
