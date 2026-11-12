<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * At most one recorded (non-voided) timing per entry and checkpoint.
     * The application already enforces this under a row lock; the generated
     * column makes the database enforce it as well.
     */
    public function up(): void
    {
        $duplicates = DB::table('timing_records')
            ->select('entry_id', 'checkpoint_id')
            ->where('status', 'recorded')
            ->groupBy('entry_id', 'checkpoint_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicates > 0) {
            throw new RuntimeException("Found {$duplicates} entry/checkpoint pairs with more than one active timing. Void the extra timings before migrating.");
        }

        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? "CASE WHEN status = 'recorded' THEN entry_id || '-' || checkpoint_id END"
            : "CASE WHEN status = 'recorded' THEN CONCAT(entry_id, '-', checkpoint_id) END";

        Schema::table('timing_records', function (Blueprint $table) use ($expression) {
            $table->string('active_key', 64)->nullable()->virtualAs($expression);
            $table->unique('active_key');
        });
    }

    public function down(): void
    {
        Schema::table('timing_records', function (Blueprint $table) {
            $table->dropUnique(['active_key']);
            $table->dropColumn('active_key');
        });
    }
};
