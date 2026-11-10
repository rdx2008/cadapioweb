<?php

namespace App\Support;

use RuntimeException;

class RuntimeEnvironment
{
    public static function validateKey(string $key): void
    {
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new RuntimeException('APP_KEY ausente ou inválida: configure uma chave Laravel de 32 bytes. Preserve a chave original em instalações existentes.');
        }
    }

    public static function url(array $environment): string
    {
        foreach (['APP_URL', 'SERVICE_URL_WEB_8080', 'SERVICE_URL_WEB'] as $name) {
            $value = rtrim((string) ($environment[$name] ?? ''), '/');
            if (filter_var($value, FILTER_VALIDATE_URL) && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true) && ! parse_url($value, PHP_URL_USER) && ! parse_url($value, PHP_URL_PASS)) {
                return $value;
            }
        }

        return 'http://localhost';
    }
}
