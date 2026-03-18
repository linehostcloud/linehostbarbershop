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
        Schema::connection('tenant')->create('automations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('created_by_user_id')->nullable()->index();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('trigger_type', 20);
            $table->string('trigger_event', 120)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('channel', 20)->default('none');
            $table->json('conditions_json');
            $table->string('action_type', 40);
            $table->json('action_payload_json');
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->unsignedInteger('cooldown_hours')->default(0);
            $table->boolean('stop_on_response')->default(false);
            $table->unsignedTinyInteger('priority')->default(10);
            $table->dateTime('last_executed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('automations');
    }
};
