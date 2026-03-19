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
        Schema::connection('tenant')->table('outbox_events', function (Blueprint $table) {
            $table->unsignedSmallInteger('reclaim_count')->default(0)->after('attempt_count');
            $table->dateTime('last_reclaimed_at')->nullable()->after('failed_at');
            $table->string('last_reclaim_reason', 255)->nullable()->after('last_reclaimed_at');

            $table->index(['status', 'reserved_at'], 'outbox_events_status_reserved_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('outbox_events', function (Blueprint $table) {
            $table->dropIndex('outbox_events_status_reserved_at_index');
            $table->dropColumn([
                'reclaim_count',
                'last_reclaimed_at',
                'last_reclaim_reason',
            ]);
        });
    }
};
