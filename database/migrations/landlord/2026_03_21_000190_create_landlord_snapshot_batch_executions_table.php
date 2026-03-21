<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('landlord')->hasTable('landlord_snapshot_batch_executions')) {
            $this->ensureIndex(
                'landlord_snapshot_batch_executions',
                ['status', 'started_at'],
                'lsbe_status_started_idx',
            );

            return;
        }

        Schema::connection('landlord')->create('landlord_snapshot_batch_executions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 30);
            $table->string('type_label', 60);
            $table->foreignUlid('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('running');
            $table->unsignedInteger('total_target')->default(0);
            $table->unsignedInteger('total_queued')->default(0);
            $table->unsignedInteger('total_succeeded')->default(0);
            $table->unsignedInteger('total_failed')->default(0);
            $table->unsignedInteger('total_skipped')->default(0);
            $table->json('metadata_json')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at'], 'lsbe_status_started_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('landlord_snapshot_batch_executions');
    }

    private function ensureIndex(string $tableName, array $columns, string $indexName): void
    {
        if ($this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::connection('landlord')->table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
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
