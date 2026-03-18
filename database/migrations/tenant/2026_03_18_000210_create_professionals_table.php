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
        Schema::connection('tenant')->create('professionals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('user_id')->nullable()->index();
            $table->string('display_name', 120);
            $table->string('role', 30)->default('barber');
            $table->string('commission_model', 30)->default('fixed_percent');
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->char('color_hex', 7)->nullable();
            $table->json('workday_calendar_json')->nullable();
            $table->boolean('active')->default(true);
            $table->date('hired_at')->nullable();
            $table->date('terminated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('professionals');
    }
};
