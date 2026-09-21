<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('races', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->date('event_date');
            $table->string('timezone')->default('Europe/Brussels');
            $table->string('status', 24)->default('draft')->index();
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('race_user', function (Blueprint $table) {
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['race_id', 'user_id']);
        });
        Schema::create('athletes', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable()->index();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 24)->nullable();
            $table->string('club')->nullable();
            $table->string('phone')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('athlete_id')->nullable()->after('role')->unique()->constrained('athletes')->nullOnDelete();
        });
        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->string('bib_number', 32);
            $table->string('type', 16)->default('solo');
            $table->string('team_name')->nullable();
            $table->string('category')->nullable();
            $table->string('status', 24)->default('registered')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['race_id', 'bib_number']);
        });
        Schema::create('entry_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('athlete_id')->constrained()->cascadeOnDelete();
            $table->string('discipline', 16);
            $table->unsignedTinyInteger('position')->default(1);
            $table->timestamps();
            $table->unique(['entry_id', 'discipline']);
        });
        Schema::create('checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 48);
            $table->unsignedSmallInteger('sequence');
            $table->string('discipline', 16)->nullable();
            $table->string('kind', 24)->default('split');
            $table->decimal('distance_km', 8, 3)->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['race_id', 'code']);
            $table->unique(['race_id', 'sequence']);
        });
        Schema::create('timing_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checkpoint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('athlete_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at', 3);
            $table->unsignedBigInteger('elapsed_ms');
            $table->string('source', 16)->default('online');
            $table->string('status', 16)->default('recorded')->index();
            $table->boolean('warning_acknowledged')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('voided_at', 3)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['entry_id', 'checkpoint_id', 'status']);
            $table->index(['race_id', 'recorded_at']);
        });
        Schema::create('operator_presence', function (Blueprint $table) {
            $table->id();
            $table->uuid('device_uuid');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checkpoint_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('pending_count')->default(0);
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['device_uuid', 'race_id']);
        });
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('race_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('status', 24)->default('preview');
            $table->unsignedInteger('row_count')->default(0);
            $table->json('preview')->nullable();
            $table->json('warnings')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('operator_presence');
        Schema::dropIfExists('timing_records');
        Schema::dropIfExists('checkpoints');
        Schema::dropIfExists('entry_members');
        Schema::dropIfExists('entries');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('athlete_id'));
        Schema::dropIfExists('athletes');
        Schema::dropIfExists('race_user');
        Schema::dropIfExists('races');
    }
};
