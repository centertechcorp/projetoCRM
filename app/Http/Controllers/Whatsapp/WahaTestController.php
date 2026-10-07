<?php

namespace App\Http\Controllers\Whatsapp;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Etapa 1 do teste do WAHA (ver plano): só confirma que os eventos chegam e grava o payload
 * cru em storage/logs/waha-test.log, sem gravar nada no banco. Não usa MessageRecorder ainda —
 * é só pra validar o formato do payload antes de ligar de verdade.
 */
class WahaTestController extends Controller
{
    public function receive(Request $request, string $store): Response
    {
        Log::channel('waha_test')->info("evento recebido ({$store})", $request->json()->all());

        return response('', 200);
    }
}
