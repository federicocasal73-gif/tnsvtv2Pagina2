<?php

declare(strict_types=1);

namespace App\Service\LinkPreview\SiteEnrichers;

final class TradingViewEnricher implements SiteEnricherInterface
{
    private const TICKER_MAP = [
        'XAUUSD' => 'Gold Spot / USD',
        'XAGUSD' => 'Silver Spot / USD',
        'BTCUSDT' => 'Bitcoin / USDT',
        'ETHUSDT' => 'Ethereum / USDT',
        'EURUSD' => 'Euro / US Dollar',
        'GBPUSD' => 'British Pound / US Dollar',
        'USDJPY' => 'US Dollar / Japanese Yen',
        'USDCAD' => 'US Dollar / Canadian Dollar',
        'AUDUSD' => 'Australian Dollar / US Dollar',
        'NZDUSD' => 'New Zealand Dollar / US Dollar',
        'USDMXN' => 'US Dollar / Mexican Peso',
        'USOIL' => 'Crude Oil (WTI)',
        'UKOIL' => 'Brent Crude Oil',
        'SPY' => 'S&P 500 ETF',
        'QQQ' => 'Nasdaq 100 ETF',
        'DXY' => 'US Dollar Index',
        'GOLD' => 'Gold Futures',
        'SILVER' => 'Silver Futures',
        'SPX' => 'S&P 500 Index',
        'NDX' => 'Nasdaq 100 Index',
        'DJI' => 'Dow Jones Index',
        'VIX' => 'Volatility Index',
        'DAX' => 'DAX Index',
        'FTSE' => 'FTSE 100 Index',
        'NKY' => 'Nikkei 225 Index',
    ];

    public function supports(string $url, string $domain): bool
    {
        return str_contains($domain, 'tradingview.com');
    }

    public function enrich(string $url, array $metadata): array
    {
        $ticker = $this->extractTicker($url);
        $result = ['kind' => 'tradingview'];

        if ($ticker !== null) {
            $result['ticker'] = $ticker;
            $assetName = self::TICKER_MAP[$ticker] ?? $ticker;
            $result['title'] = $assetName . ' — TradingView';
            if (empty($metadata['description'])) {
                $result['description'] = 'Gráfico interactivo de ' . $assetName . ' en TradingView.';
            }
            if (empty($metadata['image'])) {
                $result['image'] = null;
            }
        }

        return $result;
    }

    private function extractTicker(string $url): ?string
    {
        $parts = parse_url($url);
        if (!isset($parts['query'])) {
            return null;
        }
        parse_str($parts['query'], $query);
        $symbol = $query['symbol'] ?? '';
        if ($symbol === '') {
            return null;
        }
        // symbol format can be "BROKER:TICKER" (e.g. "FX:XAUUSD", "BINANCE:BTCUSDT")
        if (str_contains($symbol, ':')) {
            $parts = explode(':', $symbol);
            return strtoupper(end($parts));
        }
        return strtoupper($symbol);
    }
}
