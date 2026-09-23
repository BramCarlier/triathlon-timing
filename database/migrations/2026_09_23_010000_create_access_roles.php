<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::create('access_roles', function (Blueprint $table) {
            $table->id(); $table->string('name',100)->unique(); $table->string('description',500)->nullable();
            $table->boolean('is_default')->default(false); $table->json('permissions'); $table->timestamps();
        });
        DB::table('access_roles')->insert(['name'=>'Official','description'=>'Default access for race officials.','is_default'=>true,'permissions'=>json_encode(\App\Support\Permissions::all()),'created_at'=>now(),'updated_at'=>now()]);
        Schema::table('users', fn(Blueprint $table) => $table->foreignId('access_role_id')->nullable()->constrained('access_roles')->restrictOnDelete());
    }
    public function down(): void {
        Schema::table('users', fn(Blueprint $table) => $table->dropConstrainedForeignId('access_role_id'));
        Schema::dropIfExists('access_roles');
    }
};
