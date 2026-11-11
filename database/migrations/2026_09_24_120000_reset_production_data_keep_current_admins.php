<?php

use Illuminate\Database\Migrations\Migration;

/**
 * This migration once performed a one-time reset of the production database
 * (24 September 2026). It is intentionally a no-op now: keeping destructive code
 * in the migration history would wipe data again if the database were restored
 * from an older backup or rebuilt. One-off data operations belong in explicit,
 * confirmed artisan commands.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
