<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The Official role was stored as "organizer", which collided with the Organizer (admin) label. */
    public function up(): void
    {
        DB::table('users')->where('role', 'organizer')->update(['role' => 'official']);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'official')->update(['role' => 'organizer']);
    }
};
