<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('clients', function (Blueprint $table): void {
            $table->timestamp('whatsapp_reactivation_snoozed_until')->nullable()->after('inactive_since');
            $table->index('whatsapp_reactivation_snoozed_until', 'clients_whatsapp_reactivation_snoozed_until_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('clients', function (Blueprint $table): void {
            $table->dropIndex('clients_whatsapp_reactivation_snoozed_until_idx');
            $table->dropColumn('whatsapp_reactivation_snoozed_until');
        });
    }
};
