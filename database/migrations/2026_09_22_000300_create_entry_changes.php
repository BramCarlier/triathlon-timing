<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('entry_changes', function(Blueprint $table) {
        $table->id();$table->foreignId('entry_id')->constrained()->cascadeOnDelete();$table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->json('before');$table->json('after');$table->text('reason');$table->timestamps();
    }); }
    public function down(): void {Schema::dropIfExists('entry_changes');}
};
