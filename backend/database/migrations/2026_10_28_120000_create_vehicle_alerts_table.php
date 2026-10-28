<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 32);
            $table->string('status', 16)->default('new');
            $table->timestamp('read_at')->nullable();
            // Minimal abuse-prevention metadata. Only salted hashes are stored,
            // never a raw IP address or raw scanner token.
            $table->string('reporter_ip_hash', 64)->nullable();
            $table->string('reporter_token_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'status']);
            $table->index(['vehicle_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_alerts');
    }
};
