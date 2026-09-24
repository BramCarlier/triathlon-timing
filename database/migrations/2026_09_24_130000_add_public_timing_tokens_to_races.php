<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('races', function (Blueprint $table) {
            $table->uuid('public_timing_token')->nullable()->unique();
        });

        DB::table('races')
            ->select('id')
            ->whereNull('public_timing_token')
            ->orderBy('id')
            ->chunkById(100, function ($races) {
                foreach ($races as $race) {
                    DB::table('races')
                        ->where('id', $race->id)
                        ->update(['public_timing_token' => (string) Str::uuid()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('races', function (Blueprint $table) {
            $table->dropColumn('public_timing_token');
        });
    }
};
