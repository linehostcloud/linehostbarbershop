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
        Schema::connection('landlord')->create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('legal_name', 160);
            $table->string('trade_name', 160);
            $table->string('slug', 80)->unique();
            $table->string('niche', 50)->default('barbershop');
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->char('currency', 3)->default('BRL');
            $table->string('status', 30)->default('trial');
            $table->string('onboarding_stage', 50)->default('created');
            $table->string('database_name', 128)->unique();
            $table->string('database_host', 128)->nullable();
            $table->unsignedSmallInteger('database_port')->nullable();
            $table->string('database_username', 128)->nullable();
            $table->text('database_password_encrypted')->nullable();
            $table->string('plan_code', 50)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('tenants');
    }
};
