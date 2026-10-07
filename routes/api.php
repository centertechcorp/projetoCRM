<?php

use App\Http\Controllers\Whatsapp\MetaWebhookController;
use App\Http\Controllers\Whatsapp\WahaTestController;
use App\Http\Controllers\Whatsapp\WahaWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/whatsapp/meta', [MetaWebhookController::class, 'verify'])->name('whatsapp.meta.verify');
Route::post('/whatsapp/meta', [MetaWebhookController::class, 'receive'])->name('whatsapp.meta.receive');

// Etapa 1 do teste do WAHA: só loga o payload (storage/logs/waha-test.log), não grava no banco.
Route::post('/whatsapp/waha-test/{store}', [WahaTestController::class, 'receive'])->name('whatsapp.waha.test');

// Etapa 2: grava de verdade no banco (em paralelo com o daemon), via MessageRecorder.
Route::post('/whatsapp/waha/{store}', [WahaWebhookController::class, 'receive'])->name('whatsapp.waha.receive');
