<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Painel\PainelController;
use App\Http\Controllers\Painel\PainelReportController;
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
    Route::get('/painel/pecas', [PainelController::class, 'parts'])->name('painel.parts.index');
    Route::get('/painel/pecas/busca', [PainelController::class, 'partsSearch'])->name('painel.parts.search');
    Route::post('/painel/followups/{followup}/approve', [PainelController::class, 'approveFollowup'])->name('painel.followups.approve');
    Route::post('/painel/followups/{followup}/dismiss', [PainelController::class, 'dismissFollowup'])->name('painel.followups.dismiss');
    Route::post('/painel/followups/{followup}/unapprove', [PainelController::class, 'unapproveFollowup'])->name('painel.followups.unapprove');
    Route::post('/painel/leads/{lead}/won', [PainelController::class, 'markWon'])->name('painel.leads.won');
    Route::post('/painel/leads/{lead}/lost', [PainelController::class, 'markLost'])->name('painel.leads.lost');
    Route::post('/painel/leads/{lead}/delete', [PainelController::class, 'destroy'])->name('painel.leads.delete');
    Route::post('/painel/leads/{lead}/restore', [PainelController::class, 'restore'])->name('painel.leads.restore')->withTrashed();
    Route::post('/painel/leads/{lead}/category', [PainelController::class, 'setCategory'])->name('painel.leads.category');
    Route::get('/painel/relatorio', [PainelReportController::class, 'index'])->name('painel.report');
    Route::get('/painel/relatorio/acoes', [PainelReportController::class, 'actionsSummary'])->name('painel.report.actions');
    Route::post('/painel/chats/{chat}/ignore', [PainelReportController::class, 'ignoreChat'])->name('painel.chats.ignore');
    Route::post('/painel/chats/{chat}/restore', [PainelReportController::class, 'restoreChat'])->name('painel.chats.restore');
});
