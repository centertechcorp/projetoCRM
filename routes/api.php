<?php

use App\Http\Controllers\Whatsapp\MetaWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/whatsapp/meta', [MetaWebhookController::class, 'verify'])->name('whatsapp.meta.verify');
Route::post('/whatsapp/meta', [MetaWebhookController::class, 'receive'])->name('whatsapp.meta.receive');
