<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('races', function(Blueprint $table){$table->uuid('public_results_token')->nullable()->unique();$table->timestamp('results_published_at')->nullable();});
  Schema::table('users', function(Blueprint $table){$table->timestamp('invitation_sent_at')->nullable();});
 }
 public function down(): void {
  Schema::table('races',fn(Blueprint $table)=>$table->dropColumn(['public_results_token','results_published_at']));
  Schema::table('users',fn(Blueprint $table)=>$table->dropColumn('invitation_sent_at'));
 }
};
