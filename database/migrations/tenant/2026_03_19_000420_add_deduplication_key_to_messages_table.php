<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('messages', function (Blueprint $table): void {
            $table->string('deduplication_key', 64)->nullable()->after('external_message_id');
            $table->index('deduplication_key', 'messages_deduplication_key_index');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_deduplication_key_index');
            $table->dropColumn('deduplication_key');
        });
    }
};
