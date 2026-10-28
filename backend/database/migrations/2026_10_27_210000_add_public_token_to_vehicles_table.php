<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('public_token', 64)->nullable()->unique()->after('license_plate');
        });

        DB::table('vehicles')
            ->whereNull('public_token')
            ->orderBy('id')
            ->chunkById(200, function ($vehicles): void {
                foreach ($vehicles as $vehicle) {
                    do {
                        $token = bin2hex(random_bytes(32));
                    } while (DB::table('vehicles')->where('public_token', $token)->exists());

                    DB::table('vehicles')
                        ->where('id', $vehicle->id)
                        ->update(['public_token' => $token]);
                }
            });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('public_token', 64)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
