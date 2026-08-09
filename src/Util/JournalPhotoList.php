<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Normaliza y parsea la columna `photos` de un JournalEntry (TEXT).
 *
 * Por qué existe:
 *   El código original usaba una cadena separada por comas para almacenar
 *   una lista de URIs. Eso rompe los data-URI base64, que ya contienen
 *   una coma en `data:image/jpeg;base64,<payload>`: al hacer `explode(',')`
 *   se partía cada foto en dos fragmentos (prefijo y payload), generando
 *   `<img src="data:image/jpeg;base64">` (URL inválida) y
 *   `<img src="/9j/4AAQ...">` (URL relativa hacia `https://tnsvt.com/9j/...`).
 *
 * Esta clase:
 *   - En escritura: JSON-encodea el array (round-trip intacto).
 *   - En lectura: json_decode primero; si no es JSON y contiene `data:`,
 *     divide solo en comas seguidas de `data:` (cada data-URI queda
 *     entera); si no contiene `data:`, cae al split por comas viejo
 *     (compatibilidad con URLs u otros valores legacy).
 */
final class JournalPhotoList
{
    /**
     * Normaliza un valor de "lista de fotos" a un string almacenable.
     * Devuelve null si el valor queda vacío.
     */
    public static function normalize(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_string($v)) {
            $decoded = json_decode($v, true);
            if (is_array($decoded)) {
                $v = $decoded;
            } else {
                $trim = trim($v);
                return $trim === '' ? null : $trim;
            }
        }
        if (!is_array($v)) {
            return null;
        }
        $flat = [];
        foreach ($v as $item) {
            if (is_scalar($item)) {
                $flat[] = (string) $item;
            }
        }
        if (empty($flat)) {
            return null;
        }
        // JSON-encode para preservar data-URIs que contienen comas.
        return json_encode(array_values($flat), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Parsea la columna `photos` a un array de strings.
     *
     * Acepta:
     *   - JSON array (formato nuevo)
     *   - Data-URI(s) separados por coma (formato legacy ya guardado)
     *   - Lista separada por coma (URLs u otros valores simples)
     */
    public static function parse(?string $v): array
    {
        if ($v === null || $v === '') {
            return [];
        }
        $try = json_decode($v, true);
        if (is_array($try)) {
            return array_values(array_map('strval', $try));
        }
        // Data-URI legacy: dividir solo en comas seguidas de `data:`
        // (la payload base64 no contiene comas, así cada URI queda entera).
        if (str_contains($v, 'data:')) {
            $parts = preg_split('/,\s*(?=data:)/', $v);
            if ($parts !== false) {
                return array_values(array_map('strval', $parts));
            }
        }
        // Fallback: split por coma tradicional (URLs, tags, etc).
        return array_values(array_filter(array_map('trim', explode(',', $v)), static fn($x) => $x !== ''));
    }
}
