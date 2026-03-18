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
        Schema::connection('tenant')->create('services', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('category', 80);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedBigInteger('price_cents');
            $table->unsignedBigInteger('cost_cents')->nullable();
            $table->boolean('commissionable')->default(true);
            $table->decimal('default_commission_percent', 5, 2)->nullable();
            $table->boolean('requires_subscription')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('services');
    }
};
