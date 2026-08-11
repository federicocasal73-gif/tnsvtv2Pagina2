<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cotizacion de dolar blue/oficial/mep/tarjeta desde dolarapi.com
 * Sin auth, publico, cache 1h en memoria.
 */
#[Route('/api/wallet')]
class DolarController extends AbstractController
{
    private const CACHE_TTL = 3600; // 1 hora
    private const CACHE_KEY = 'tnsvt_dolar_rates';

    private static ?array $cache = null;
    private static ?int $cacheTime = null;

    #[Route('/rates', name: 'api_wallet_rates', methods: ['GET'])]
    public function rates(): JsonResponse
    {
        $now = time();
        if (self::$cache && self::$cacheTime && ($now - self::$cacheTime) < self::CACHE_TTL) {
            return new JsonResponse(self::$cache + ['cached' => true], 200);
        }

        $rates = $this->fetchFromDolarapi();

        if ($rates === null) {
            // Fallback: usar ultimo cache conocido o defaults
            if (self::$cache) {
                return new JsonResponse(self::$cache + ['cached' => true, 'stale' => true], 200);
            }
            $rates = $this->getDefaultRates();
        } else {
            self::$cache = $rates;
            self::$cacheTime = $now;
        }

        return new JsonResponse($rates + ['cached' => false], 200, ['Cache-Control' => 'public, max-age=300']);
    }

    private function fetchFromDolarapi(): ?array
    {
        try {
            $url = 'https://dolarapi.com/v1/dolares';
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'method' => 'GET']]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) return null;

            $data = json_decode($raw, true);
            if (!is_array($data)) return null;

            $out = ['fetched_at' => date('c'), 'source' => 'dolarapi.com'];
            foreach ($data as $entry) {
                $key = strtolower($entry['casa'] ?? 'unknown');
                $out[$key] = [
                    'name' => $entry['nombre'] ?? ucfirst($key),
                    'buy' => (float) ($entry['compra'] ?? 0),
                    'sell' => (float) ($entry['venta'] ?? 0),
                    'date' => $entry['fecha'] ?? null,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getDefaultRates(): array
    {
        return [
            'fetched_at' => date('c'),
            'source' => 'fallback',
            'blue' => ['name' => 'Blue', 'buy' => 1180, 'sell' => 1200, 'date' => null],
            'oficial' => ['name' => 'Oficial', 'buy' => 1100, 'sell' => 1150, 'date' => null],
            'mep' => ['name' => 'Bolsa', 'buy' => 1170, 'sell' => 1190, 'date' => null],
            'ccl' => ['name' => 'Contado con liquidación', 'buy' => 1190, 'sell' => 1210, 'date' => null],
            'tarjeta' => ['name' => 'Tarjeta', 'buy' => 1750, 'sell' => 1750, 'date' => null],
            'cripto' => ['name' => 'Cripto', 'buy' => 1190, 'sell' => 1210, 'date' => null],
        ];
    }
}
