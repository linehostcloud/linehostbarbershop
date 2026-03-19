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
        Schema::connection('tenant')->create('whatsapp_provider_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slot', 20);
            $table->string('provider', 50);
            $table->string('fallback_provider', 50)->nullable();
            $table->string('base_url', 255)->nullable();
            $table->string('api_version', 40)->nullable();
            $table->text('api_key')->nullable();
            $table->text('access_token')->nullable();
            $table->string('phone_number_id', 120)->nullable();
            $table->string('business_account_id', 120)->nullable();
            $table->string('instance_name', 120)->nullable();
            $table->text('webhook_secret')->nullable();
            $table->text('verify_token')->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->default(10);
            $table->json('retry_profile_json')->nullable();
            $table->json('enabled_capabilities_json')->nullable();
            $table->json('settings_json')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();

            $table->unique('slot');
            $table->index(['provider', 'enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('whatsapp_provider_configs');
    }
};
