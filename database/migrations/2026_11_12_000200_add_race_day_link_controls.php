<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('races', function (Blueprint $table) {
            $table->boolean('public_timing_enabled')->default(true)->after('public_timing_token');
            $table->timestamp('public_timing_rotated_at')->nullable()->after('public_timing_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('races', function (Blueprint $table) {
            $table->dropColumn(['public_timing_enabled', 'public_timing_rotated_at']);
        });
    }
};
