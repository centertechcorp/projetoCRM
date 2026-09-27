<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Painel\PainelController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/painel', [PainelController::class, 'index'])->name('painel.index');
    Route::post('/painel/followups/{followup}/approve', [PainelController::class, 'approveFollowup'])->name('painel.followups.approve');
    Route::post('/painel/followups/{followup}/dismiss', [PainelController::class, 'dismissFollowup'])->name('painel.followups.dismiss');
    Route::post('/painel/leads/{lead}/won', [PainelController::class, 'markWon'])->name('painel.leads.won');
    Route::post('/painel/leads/{lead}/lost', [PainelController::class, 'markLost'])->name('painel.leads.lost');
});
