<?php

namespace App\Services\Whatsapp;

/**
 * Normaliza telefones brasileiros para o formato gravado em customers.phone.
 *
 * O chat_key de uma conversa (App\Services\Whatsapp\MessageStore::contactKey) já vem
 * com o DDI completo, do jeito que o WhatsApp entrega o JID. Por isso esta classe só
 * corrige um caso ambíguo real — celular brasileiro sem o 9º dígito — e não tenta
 * adivinhar o DDI de números que não comecem com 55, para não estragar um telefone
 * estrangeiro (ex.: 1 6505551234, dos EUA, tem o mesmo tamanho de um celular brasileiro
 * sem DDI).
 */
class PhoneNormalizer
{
    public static function normalize(string $digits): ?string
    {
        $digits = preg_replace('/\D/', '', $digits) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '55') && strlen($digits) === 12 && preg_match('/^55\d{2}[6-9]/', $digits) === 1) {
            return substr($digits, 0, 4).'9'.substr($digits, 4);
        }

        return strlen($digits) >= 8 ? $digits : null;
    }
}
