<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('started_by_user_id')->nullable()->change();
        });

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->foreignId('sender_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('conversations')->whereNull('started_by_user_id')->exists()
            || DB::table('conversation_messages')->whereNull('sender_user_id')->exists()) {
            throw new RuntimeException('Guest conversations exist and must be removed before rolling back.');
        }

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->foreignId('sender_user_id')->nullable(false)->change();
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('started_by_user_id')->nullable(false)->change();
        });
    }
};
