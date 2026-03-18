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
        Schema::connection('tenant')->create('campaigns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('automation_id')->nullable()->constrained('automations')->nullOnDelete();
            $table->ulid('created_by_user_id')->nullable()->index();
            $table->string('name', 120);
            $table->string('channel', 20);
            $table->string('objective', 30);
            $table->json('audience_definition_json');
            $table->string('template_key', 80);
            $table->string('status', 20)->default('draft');
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->json('metrics_json')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('campaigns');
    }
};
