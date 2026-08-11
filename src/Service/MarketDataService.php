<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * MarketDataService - server-authoritative price source para torneos.
 *
 * Estrategia (A3):
 * - Intenta primero precio REAL via APIs free CORS-open (xaus, CoinGecko, er-api, Yahoo).
 * - Si la API falla, cae a un candle deterministico server-side con seed
 *   (tournament_id + trade_count) para que el server sea source of truth,
 *   no el cliente.
 *
 * El server siempre emite entry_price + exit_price. El cliente solo envia
 * {symbol, direction, timeframe} - nunca el resultado.
 *
 * Para uso futuro: integrar feeds live premium (Binance, Kraken, etc).
 */
class MarketDataService
{
    public function __construct(
        private HttpClientInterface $http,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Devuelve un snapshot de precio actual para $symbol.
     * @return array{price: float, source: string}  o null si falla todo.
     */
    public function snapshot(string $symbol): ?array
    {
        $symbol = strtoupper($symbol);

        // Crypto via CoinGecko (no requiere key)
        $cgId = match ($symbol) {
            'BTC'  => 'bitcoin',
            'ETH'  => 'ethereum',
            'SOL'  => 'solana',
            'BNB'  => 'binancecoin',
            'XRP'  => 'ripple',
            default => null,
        };
        if ($cgId) {
            $r = $this->fetchJson("https://api.coingecko.com/api/v3/simple/price?ids={$cgId}&vs_currencies=usd", 5);
            if ($r && isset($r[$cgId]['usd'])) {
                return ['price' => (float) $r[$cgId]['usd'], 'source' => 'coingecko'];
            }
        }

        // GOLD via xaus.com
        if ($symbol === 'GOLD') {
            $r = $this->fetchJson('https://xaus.com/api/v1/spot', 5);
            if ($r && isset($r['xau']['price'])) {
                return ['price' => (float) $r['xau']['price'], 'source' => 'xaus'];
            }
        }

        // EURUSD via er-api.com
        if ($symbol === 'EURUSD') {
            $r = $this->fetchJson('https://open.er-api.com/v6/latest/EUR', 5);
            if ($r && isset($r['rates']['USD'])) {
                return ['price' => (float) $r['rates']['USD'], 'source' => 'erapi'];
            }
        }

        // Fallback: precio deterministico (server-authoritative sin feed real)
        return $this->fallbackSnapshot($symbol);
    }

    /**
     * Genera un candle deterministico para exit_price a partir de un entry_price.
     * El seed = hash(tournament_id + trade_count) hace el resultado reproducible
     * por trade especifico pero unico entre trades.
     */
    public function generateCandle(string $symbol, float $entryPrice, string $timeframe, int $tournamentId, int $tradeIndex): array
    {
        $vol = match (strtoupper($symbol)) {
            'BTC','ETH'  => 0.015,
            'GOLD'       => 0.008,
            'EURUSD'     => 0.004,
            default      => 0.012,
        };
        // Drift por timeframe: 1M mas pequeno que 1D
        $tfMult = match ($timeframe) {
            '1M'  => 0.3,
            '5M'  => 0.6,
            '15M' => 1.0,
            '1H'  => 1.6,
            '4H'  => 2.4,
            '1D'  => 4.0,
            default => 1.0,
        };

        // Seed deterministico: hash del (tournament_id, trade_index, symbol)
        $seed = hexdec(substr(hash('sha256', $tournamentId . ':' . $tradeIndex . ':' . $symbol), 0, 8));
        mt_srand($seed);

        // Bias aleatorio entre -vol y +vol
        $bias = (mt_rand() / mt_getrandmax() - 0.5) * 2 * $vol * $tfMult;
        $exit = $entryPrice * (1 + $bias);

        // Reset seed para no contaminar otros RNG
        mt_srand();

        return [
            'price' => round($exit, 4),
            'source' => 'deterministic-candle',
            'seed' => $seed,
            'tf_mult' => $tfMult,
            'vol' => $vol,
        ];
    }

    /**
     * Fallback cuando todas las APIs fallan.
     * Devuelve un precio base "razonable" por simbolo (referencia 2026-Q3).
     */
    private function fallbackSnapshot(string $symbol): ?array
    {
        $fallbacks = [
            'BTC'     => 65000,
            'ETH'     => 3500,
            'SOL'     => 180,
            'BNB'     => 620,
            'XRP'     => 0.55,
            'GOLD'    => 4121,
            'SP500'   => 7575,
            'EURUSD'  => 1.142,
        ];
        if (isset($fallbacks[$symbol])) {
            // Anade jitter pequeno basado en timestamp
            $jitter = (microtime(true) % 60) / 60000; // +/- 0.1%
            $price = $fallbacks[$symbol] * (1 + ($jitter - 0.0005));
            return ['price' => round($price, 4), 'source' => 'fallback'];
        }
        return null;
    }

    private function fetchJson(string $url, int $timeoutSec = 5): ?array
    {
        try {
            $resp = $this->http->request('GET', $url, ['timeout' => $timeoutSec]);
            if ($resp->getStatusCode() !== 200) return null;
            $data = $resp->toArray(false);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            if ($this->logger) $this->logger->warning('MarketData fetch failed', ['url' => $url, 'err' => $e->getMessage()]);
            return null;
        }
    }
}