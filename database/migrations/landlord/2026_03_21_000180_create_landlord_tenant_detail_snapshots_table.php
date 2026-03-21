<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('landlord')->hasTable('landlord_tenant_detail_snapshots')) {
            $this->ensureUniqueIndex(
                'landlord_tenant_detail_snapshots',
                ['tenant_id'],
                'landlord_tenant_detail_snapshots_tenant_id_unique',
            );
            $this->ensureIndex(
                'landlord_tenant_detail_snapshots',
                ['refresh_status', 'generated_at'],
                'ldts_status_generated_idx',
            );

            return;
        }

        Schema::connection('landlord')->create('landlord_tenant_detail_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete()->unique();
            $table->string('refresh_status', 30)->default('ready');
            $table->string('last_refresh_source', 30)->nullable();
            $table->string('last_refresh_error', 255)->nullable();
            $table->json('payload_json')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->dateTime('last_refresh_started_at')->nullable();
            $table->dateTime('last_refresh_completed_at')->nullable();
            $table->dateTime('last_refresh_failed_at')->nullable();
            $table->timestamps();

            $table->index(['refresh_status', 'generated_at'], 'ldts_status_generated_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('landlord_tenant_detail_snapshots');
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureIndex(string $tableName, array $columns, string $indexName): void
    {
        if ($this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::connection('landlord')->table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureUniqueIndex(string $tableName, array $columns, string $indexName): void
    {
        if ($this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::connection('landlord')->table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->unique($columns, $indexName);
        });
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        return DB::connection('landlord')
            ->table('information_schema.statistics')
            ->where('table_schema', DB::connection('landlord')->getDatabaseName())
            ->where('table_name', $tableName)
            ->where('index_name', $indexName)
            ->exists();
    }
};
