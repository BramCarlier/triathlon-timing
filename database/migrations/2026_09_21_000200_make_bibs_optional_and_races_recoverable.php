<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('entries', fn (Blueprint $table) => $table->string('bib_number', 32)->nullable()->change());
        Schema::table('races', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        // Do not invent bibs or silently restore deleted races during rollback.
        if (DB::table('entries')->whereNull('bib_number')->exists() || DB::table('races')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Assign missing bibs and restore deleted races before rolling back this migration.');
        }
        Schema::table('races', fn (Blueprint $table) => $table->dropSoftDeletes());
        Schema::table('entries', fn (Blueprint $table) => $table->string('bib_number', 32)->nullable(false)->change());
    }
};
