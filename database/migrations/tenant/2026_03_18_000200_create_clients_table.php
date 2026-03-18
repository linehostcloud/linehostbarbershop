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
        Schema::connection('tenant')->create('clients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('external_code', 40)->nullable();
            $table->string('full_name', 160);
            $table->string('phone_e164', 20)->nullable()->index();
            $table->string('email', 190)->nullable()->index();
            $table->date('birth_date')->nullable();
            $table->ulid('preferred_professional_id')->nullable();
            $table->string('acquisition_channel', 50)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('marketing_opt_in')->default(false);
            $table->boolean('whatsapp_opt_in')->default(false);
            $table->unsignedInteger('visit_count')->default(0);
            $table->unsignedSmallInteger('average_visit_interval_days')->nullable();
            $table->string('retention_status', 20)->default('new');
            $table->timestamp('last_visit_at')->nullable();
            $table->timestamp('inactive_since')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('clients');
    }
};
