<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('landlord')->table('boundary_rejection_audits', function (Blueprint $table) {
            $table->index(['tenant_id', 'code', 'occurred_at'], 'boundary_rejection_audits_tenant_code_occurred_at_index');
            $table->index(['tenant_id', 'direction', 'occurred_at'], 'boundary_rejection_audits_tenant_direction_occurred_at_index');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('boundary_rejection_audits', function (Blueprint $table) {
            $table->dropIndex('boundary_rejection_audits_tenant_code_occurred_at_index');
            $table->dropIndex('boundary_rejection_audits_tenant_direction_occurred_at_index');
        });
    }
};
