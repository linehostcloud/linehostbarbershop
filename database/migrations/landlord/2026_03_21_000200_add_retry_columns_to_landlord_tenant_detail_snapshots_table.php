<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('landlord_tenant_detail_snapshots', function (Blueprint $table) {
            $table->unsignedTinyInteger('retry_attempt')->default(0)->after('last_refresh_error');
            $table->dateTime('next_retry_at')->nullable()->after('retry_attempt');
            $table->dateTime('retry_exhausted_at')->nullable()->after('next_retry_at');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('landlord_tenant_detail_snapshots', function (Blueprint $table) {
            $table->dropColumn(['retry_attempt', 'next_retry_at', 'retry_exhausted_at']);
        });
    }
};
