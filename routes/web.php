<?php

use App\Http\Controllers\AthleteDashboardController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\CheckpointController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ParticipantImportController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\RaceControlController;
use App\Http\Controllers\RaceController;
use App\Http\Controllers\RaceOfficialController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\TimingController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class);
Route::get('/live/{token}', [ResultController::class,'publicIndex'])->middleware('throttle:120,1')->name('results.public');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/guide', GuideController::class)->name('guide');
    Route::get('/account/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/account/password', [PasswordController::class, 'update'])->name('password.update');
    Route::get('/athlete', AthleteDashboardController::class)->middleware('role:athlete')->name('athlete.dashboard');
    Route::get('/races/{race}/results', [ResultController::class, 'index'])->name('races.results');

    Route::middleware('role:admin,organizer')->group(function () {
        Route::get('/races', [RaceController::class, 'index'])->name('races.index');
        Route::get('/races/create', [RaceController::class, 'create'])->middleware('permission:races.create')->name('races.create');
        Route::post('/races', [RaceController::class, 'store'])->middleware('permission:races.create')->name('races.store');
        Route::get('/races/{race}', [RaceController::class, 'show'])->name('races.show');
        Route::post('/races/{race}/publication', [ResultController::class,'publish'])->middleware('permission:races.setup')->name('results.publish');
        Route::put('/races/{race}', [RaceController::class, 'update'])->middleware('permission:races.setup')->name('races.update');
        Route::post('/races/{race}/official-assignments', [RaceOfficialController::class, 'store'])->name('races.officials.store');
        Route::delete('/races/{race}/official-assignments/{official}', [RaceOfficialController::class, 'destroy'])->name('races.officials.destroy');

        Route::post('/races/{race}/checkpoints', [CheckpointController::class, 'store'])->middleware('permission:races.setup')->name('races.checkpoints.store');
        Route::put('/races/{race}/checkpoints/{checkpoint}', [CheckpointController::class, 'update'])->middleware('permission:races.setup')->name('races.checkpoints.update');
        Route::delete('/races/{race}/checkpoints/{checkpoint}', [CheckpointController::class, 'destroy'])->middleware('permission:races.setup')->name('races.checkpoints.destroy');

        Route::get('/races/{race}/participants', [EntryController::class, 'index'])->middleware('permission:participants.manage')->name('races.entries.index');
        Route::post('/races/{race}/participants', [EntryController::class, 'store'])->middleware('permission:participants.manage')->name('races.entries.store');
        Route::get('/races/{race}/participants/{entry}/edit', [EntryController::class, 'edit'])->middleware('permission:participants.manage')->name('races.entries.edit');
        Route::put('/races/{race}/participants/{entry}', [EntryController::class, 'update'])->middleware('permission:participants.manage')->name('races.entries.update');
        Route::delete('/races/{race}/participants/{entry}', [EntryController::class, 'destroy'])->middleware('permission:participants.manage')->name('races.entries.destroy');
        Route::get('/races/{race}/participants/import', [ParticipantImportController::class, 'create'])->middleware('permission:participants.manage')->name('races.import.create');
        Route::post('/races/{race}/participants/import/preview', [ParticipantImportController::class, 'preview'])->middleware('permission:participants.manage')->name('races.import.preview');
        Route::post('/races/{race}/participants/import/{batch}/confirm', [ParticipantImportController::class, 'confirm'])->middleware('permission:participants.manage')->name('races.import.confirm');
        Route::get('/participant-import-template.csv', [ParticipantImportController::class, 'template'])->middleware('permission:participants.manage')->name('races.import.template');

        Route::get('/races/{race}/control', [RaceControlController::class, 'show'])->middleware('permission:races.control')->name('races.control');
        Route::post('/races/{race}/start', [RaceControlController::class, 'start'])->middleware('permission:races.control')->name('races.start');
        Route::post('/races/{race}/finish', [RaceControlController::class, 'finish'])->middleware('permission:races.control')->name('races.finish');

        Route::post('/races/{race}/checkpoint-selection', [TimingController::class, 'selectCheckpoint'])->middleware('permission:timings.record')->name('races.station.select');
        Route::get('/races/{race}/station', [TimingController::class, 'station'])->middleware('permission:timings.record')->name('races.station');
        Route::get('/races/{race}/station/search', [TimingController::class, 'search'])->middleware('permission:timings.record')->name('races.station.search');
        Route::post('/races/{race}/timings', [TimingController::class, 'record'])->middleware('permission:timings.record')->name('races.timings.store');
        Route::post('/races/{race}/corrections', [TimingController::class, 'correct'])->middleware('permission:races.control')->name('races.timings.correct');
        Route::post('/races/{race}/timings/{timing}/void', [TimingController::class, 'void'])->middleware('permission:timings.record,races.control')->name('races.timings.void');
        Route::post('/races/{race}/presence', [PresenceController::class, 'ping'])->middleware('permission:timings.record')->name('races.presence');

        Route::get('/races/{race}/results.csv', [ResultController::class, 'csv'])->middleware('permission:results.export')->name('races.results.csv');
        Route::get('/races/{race}/results.xlsx', [ResultController::class, 'xlsx'])->middleware('permission:results.export')->name('races.results.xlsx');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/roles', [\App\Http\Controllers\AccessRoleController::class,'index'])->name('roles.index');
        Route::post('/admin/roles', [\App\Http\Controllers\AccessRoleController::class,'store'])->name('roles.store');
        Route::put('/admin/roles/{accessRole}', [\App\Http\Controllers\AccessRoleController::class,'update'])->name('roles.update');
        Route::delete('/admin/roles/{accessRole}', [\App\Http\Controllers\AccessRoleController::class,'destroy'])->name('roles.destroy');
        Route::get('/admin/health', \App\Http\Controllers\HealthController::class)->name('admin.health');
        Route::delete('/races/{race}', [RaceController::class, 'destroy'])->name('races.destroy');
        Route::post('/races/{race}/restore', [RaceController::class, 'restore'])->withTrashed()->name('races.restore');
        Route::get('/users/athletes', [UserController::class, 'athletes'])->name('users.athletes');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::post('/users/{user}/invitation', [UserController::class,'invite'])->middleware('throttle:5,1')->name('users.invite');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    });
});
