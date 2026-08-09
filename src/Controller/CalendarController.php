<?php

namespace App\Controller;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class CalendarController extends AbstractController
{
    private const TV_EVENTS_URL = 'https://chartevents-reuters.tradingview.com/events';

    private const FALLBACK_EVENTS = [
        ['time' => '08:30', 'country_code' => 'US', 'currency' => 'USD', 'title' => 'IPC Mensual (CPI MM)', 'original_title' => 'CPI MM', 'importance' => 3, 'actual' => '—', 'forecast' => '0.2%', 'previous' => '0.1%'],
        ['time' => '08:30', 'country_code' => 'US', 'currency' => 'USD', 'title' => 'IPC Anual (CPI YY)', 'original_title' => 'CPI YY', 'importance' => 3, 'actual' => '—', 'forecast' => '3.1%', 'previous' => '3.2%'],
        ['time' => '08:30', 'country_code' => 'US', 'currency' => 'USD', 'title' => 'Nóminas No Agrícolas (NFP)', 'original_title' => 'Non-Farm Payrolls', 'importance' => 3, 'actual' => '—', 'forecast' => '180K', 'previous' => '175K'],
        ['time' => '14:00', 'country_code' => 'US', 'currency' => 'USD', 'title' => 'Decisión Tasa de Interés (FOMC)', 'original_title' => 'Interest Rate Decision', 'importance' => 3, 'actual' => '—', 'forecast' => '5.50%', 'previous' => '5.50%'],
        ['time' => '10:00', 'country_code' => 'EU', 'currency' => 'EUR', 'title' => 'PMI Manufacturero', 'original_title' => 'Manufacturing PMI', 'importance' => 2, 'actual' => '—', 'forecast' => '47.5', 'previous' => '47.0'],
        ['time' => '14:30', 'country_code' => 'US', 'currency' => 'USD', 'title' => 'Conferencia de Prensa FOMC', 'original_title' => 'FOMC Press Conference', 'importance' => 3, 'actual' => '—', 'forecast' => '—', 'previous' => '—'],
        ['time' => '03:00', 'country_code' => 'US', 'currency' => 'USD', 'title' => 'Cambio Empleo ADP', 'original_title' => 'ADP Non-Farm Employment Change', 'importance' => 3, 'actual' => '—', 'forecast' => '150K', 'previous' => '145K'],
    ];

    private const COUNTRY_MAP = [
        'US' => '🇺🇸 USD', 'EU' => '🇪🇺 EUR', 'GB' => '🇬🇧 GBP',
        'JP' => '🇯🇵 JPY', 'CA' => '🇨🇦 CAD', 'AU' => '🇦🇺 AUD',
        'CH' => '🇨🇭 CHF', 'CN' => '🇨🇳 CNY', 'MX' => '🇲🇽 MXN',
        'BR' => '🇧🇷 BRL', 'IN' => '🇮🇳 INR', 'NZ' => '🇳🇿 NZD',
        'DE' => '🇩🇪 EUR', 'FR' => '🇫🇷 EUR', 'IT' => '🇮🇹 EUR',
        'ES' => '🇪🇸 EUR', 'AR' => '🇦🇷 ARS',
    ];

    private const SPANISH_TITLES = [
        'CPI MM' => 'IPC Mensual',
        'CPI YY' => 'IPC Anual',
        'Core CPI MM' => 'IPC Subyacente Mensual',
        'Core CPI YY' => 'IPC Subyacente Anual',
        'PPI MM' => 'IPP Mensual',
        'PPI YY' => 'IPP Anual',
        'Unemployment Rate' => 'Tasa de Desempleo',
        'Non-Farm Payrolls' => 'Nóminas No Agrícolas (NFP)',
        'Nonfarm Payrolls' => 'Nóminas No Agrícolas (NFP)',
        'Non-Farm Employment Change' => 'Cambio Empleo No Agrícola',
        'GDP' => 'PIB',
        'GDP MM' => 'PIB Mensual',
        'GDP QQ' => 'PIB Trimestral',
        'GDP YY' => 'PIB Anual',
        'Interest Rate Decision' => 'Decisión Tasa de Interés',
        'FOMC Statement' => 'Declaración FOMC',
        'FOMC Press Conference' => 'Conferencia de Prensa FOMC',
        'Fed Chair Press Conference' => 'Conferencia Presidente Fed',
        'ECB Interest Rate Decision' => 'Decisión Tasa BCE',
        'ECB Press Conference' => 'Conferencia de Prensa BCE',
        'Retail Sales MM' => 'Ventas Minoristas Mensual',
        'Retail Sales YY' => 'Ventas Minoristas Anual',
        'Industrial Production MM' => 'Producción Industrial Mensual',
        'Manufacturing PMI' => 'PMI Manufacturero',
        'Services PMI' => 'PMI Servicios',
        'Composite PMI' => 'PMI Compuesto',
        'Trade Balance' => 'Balanza Comercial',
        'Current Account' => 'Cuenta Corriente',
        'Consumer Confidence' => 'Confianza del Consumidor',
        'Business Confidence' => 'Confianza Empresarial',
        'Inflation Rate YY' => 'Tasa de Inflación Anual',
        'Inflation Rate MM' => 'Tasa de Inflación Mensual',
        'Core Inflation Rate YY' => 'Inflación Subyacente Anual',
        'Employment Change' => 'Cambio de Empleo',
        'Unemployment Claims' => 'Solicitudes de Desempleo',
        'Building Permits' => 'Permisos de Construcción',
        'Housing Starts' => 'Inicio de Viviendas',
        'Existing Home Sales' => 'Ventas de Viviendas Existentes',
        'New Home Sales' => 'Ventas de Viviendas Nuevas',
        'Consumer Credit' => 'Crédito al Consumidor',
        'Factory Orders MM' => 'Pedidos de Fábrica Mensual',
        'Durable Goods Orders MM' => 'Pedidos Bienes Duraderos',
        'ISM Manufacturing PMI' => 'PMI Manufacturero ISM',
        'ISM Services PMI' => 'PMI Servicios ISM',
        'JOLTS Job Openings' => 'Ofertas de Empleo JOLTS',
        'ADP Non-Farm Employment Change' => 'Cambio Empleo ADP',
        'Average Hourly Earnings MM' => 'Salario Por Hora Promedio',
        'Producer Price Index MM' => 'Índice Precios Productor',
        'BoE Interest Rate Decision' => 'Decisión Tasa BoE',
        'BoJ Interest Rate Decision' => 'Decisión Tasa BoJ',
        'SNB Interest Rate Decision' => 'Decisión Tasa SNB',
        'RBA Interest Rate Decision' => 'Decisión Tasa RBA',
        'BOC Interest Rate Decision' => 'Decisión Tasa BOC',
        'RBNZ Interest Rate Decision' => 'Decisión Tasa RBNZ',
        'PCE Price Index MM' => 'PCE Mensual',
        'PCE Price Index YY' => 'PCE Anual',
        'Core PCE Price Index MM' => 'PCE Subyacente Mensual',
        'Core PCE Price Index YY' => 'PCE Subyacente Anual',
    ];

    private const HIGH_IMPACT_KEYWORDS = [
        'NFP', 'NON-FARM', 'NONFARM', 'PAYROLL',
        'CPI', 'IPC',
        'PCE',
        'PMI',
        'FOMC', 'FED', 'INTEREST RATE',
    ];

    #[Route('/calendar/widget', name: 'app_calendar_widget', methods: ['GET'])]
    public function widget(\Symfony\Component\HttpFoundation\Request $request): Response
    {
        $cacheFile = sys_get_temp_dir() . '/tnsvt_calendar_cache.json';
        $cacheTtl = 900;

        $tz = $this->parseTimezone($request->query->get('tz'));
        $countriesFilter = $this->parseCountriesFilter($request->query->get('countries'));
        $impactFilter = $this->parseImpactFilter($request->query->get('impact'));

        $events = $this->loadFromCache($cacheFile, $cacheTtl);
        if ($events === null) {
            $events = $this->fetchFromTradingView();
        }
        if ($events === null || count($events) < 3) {
            $events = $this->mergeWithFallback($events);
        }

        $this->saveToCache($cacheFile, $events);

        $filtered = $this->applyFilters($events, $countriesFilter, $impactFilter);
        $filtered = $this->applyTimezone($filtered, $tz);

        return $this->render('calendar/widget.html.twig', [
            'events' => $filtered,
            'has_data' => count($filtered) > 0,
            'total_count' => count($events),
            'filtered_count' => count($filtered),
            'countries' => $countriesFilter,
            'impact' => $impactFilter,
        ]);
    }

    #[Route('/api/calendar/events', name: 'app_calendar_api_events', methods: ['GET'])]
    public function apiEvents(\Symfony\Component\HttpFoundation\Request $request): JsonResponse
    {
        $cacheFile = sys_get_temp_dir() . '/tnsvt_calendar_cache.json';
        $cacheTtl = 900;

        $tz = $this->parseTimezone($request->query->get('tz'));
        $countriesFilter = $this->parseCountriesFilter($request->query->get('countries'));
        $impactFilter = $this->parseImpactFilter($request->query->get('impact'));

        $events = $this->loadFromCache($cacheFile, $cacheTtl);
        if ($events === null) {
            $events = $this->fetchFromTradingView();
        }
        if ($events === null || count($events) < 3) {
            $events = $this->mergeWithFallback($events);
        }

        $this->saveToCache($cacheFile, $events);

        $filtered = $this->applyFilters($events, $countriesFilter, $impactFilter);
        $filtered = $this->applyTimezone($filtered, $tz);

        foreach ($filtered as &$e) {
            $e['is_critical'] = $this->isHighImpact($e['title'] ?? '', $e['original_title'] ?? '');
        }
        unset($e);

        $response = new JsonResponse(['events' => $filtered, 'tz' => $tz], Response::HTTP_OK);
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE);
        return $response;
    }

    private function parseTimezone(?string $raw): string
    {
        $raw = trim((string)$raw);
        if ($raw === '') return 'America/Argentina/Buenos_Aires';

        // Aceptar cualquier formato UTC±N o UTC±H:M (con fracciones)
        if (preg_match('/^UTC([+-])(\d{1,2})(?::?(\d{1,2}))?$/i', $raw)) {
            return $raw;
        }

        // Si es un IANA name valido, devolverlo tal cual
        try {
            new \DateTimeZone($raw);
            return $raw;
        } catch (\Throwable $e) {
            return 'America/Argentina/Buenos_Aires';
        }
    }

    private function getOffsetMinutes(string $tzRaw): int
    {
        $tzRaw = trim($tzRaw);

        // Parsear string UTC±H o UTC±H:M del input crudo
        if (preg_match('/^UTC([+-])(\d{1,2})(?::?(\d{1,2}))?$/i', $tzRaw, $m)) {
            $sign = $m[1] === '-' ? -1 : 1;
            $hours = (int)$m[2];
            $mins = isset($m[3]) ? (int)$m[3] : 0;
            return $sign * ($hours * 60 + $mins);
        }

        // Si es un IANA name, calcular offset actual (maneja DST)
        try {
            $tz = new \DateTimeZone($tzRaw);
            $now = new \DateTimeImmutable('now', $tz);
            return (int)$now->format('Z') / 60;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getTzLabel(string $tzRaw, int $offsetMinutes): string
    {
        // Si viene como UTC±N, devolver label directo
        if (preg_match('/^UTC/i', $tzRaw)) {
            $sign = $offsetMinutes >= 0 ? '+' : '';
            $h = intdiv(abs($offsetMinutes), 60);
            $m = abs($offsetMinutes) % 60;
            return $m === 0
                ? "UTC{$sign}{$h}"
                : "UTC{$sign}{$h}:" . sprintf('%02d', $m);
        }

        // Map de ciudades comunes
        $map = [
            'America/Argentina/Buenos_Aires' => '🇦🇷 Buenos Aires',
            'America/New_York' => '🇺🇸 NY',
            'America/Los_Angeles' => '🇺🇸 LA',
            'America/Mexico_City' => '🇲🇽 CDMX',
            'America/Sao_Paulo' => '🇧🇷 São Paulo',
            'America/Chicago' => '🇺🇸 Chicago',
            'Europe/London' => '🇬🇧 London',
            'Europe/Berlin' => '🇩🇪 Berlin',
            'Europe/Madrid' => '🇪🇸 Madrid',
            'Europe/Moscow' => '🇷🇺 Moscow',
            'Asia/Tokyo' => '🇯🇵 Tokyo',
            'Asia/Shanghai' => '🇨🇳 Shanghai',
            'Asia/Kolkata' => '🇮🇳 Kolkata',
            'Asia/Dubai' => '🇦🇪 Dubai',
            'Australia/Sydney' => '🇦🇺 Sydney',
            'Pacific/Auckland' => '🇳🇿 Auckland',
        ];
        return $map[$tzRaw] ?? str_replace('_', ' ', $tzRaw);
    }

    private function parseCountriesFilter(?string $raw): array
    {
        if ($raw === null || $raw === '') return [];
        $ccyToCountry = [
            'USD' => 'US', 'EUR' => 'EU', 'GBP' => 'GB', 'JPY' => 'JP',
            'CAD' => 'CA', 'AUD' => 'AU', 'CHF' => 'CH', 'CNY' => 'CN',
            'MXN' => 'MX', 'BRL' => 'BR', 'INR' => 'IN', 'NZD' => 'NZ',
        ];
        $parts = array_filter(array_map('trim', explode(',', strtoupper($raw))));
        $countries = [];
        foreach ($parts as $ccy) {
            if (isset($ccyToCountry[$ccy])) {
                $countries[] = $ccyToCountry[$ccy];
            } else {
                $countries[] = $ccy;
            }
        }
        return array_values(array_unique($countries));
    }

    private function parseImpactFilter(?string $raw): array
    {
        if ($raw === null || $raw === '') return [];
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $impact = [];
        foreach ($parts as $p) {
            $n = (int)$p;
            if ($n >= 1 && $n <= 3) $impact[] = $n;
        }
        return array_values(array_unique($impact));
    }

    private function isHighImpact(string $titleEs, string $titleEn): bool
    {
        $haystack = ' ' . strtoupper($titleEs . ' ' . $titleEn) . ' ';
        foreach (self::HIGH_IMPACT_KEYWORDS as $kw) {
            if (str_contains($haystack, ' ' . $kw . ' ') || str_contains($haystack, $kw)) {
                return true;
            }
        }
        return false;
    }

    private function applyFilters(?array $events, array $countries, array $impact): array
    {
        if (!is_array($events)) return [];
        if (empty($countries) && empty($impact)) return $events;

        return array_values(array_filter($events, function ($e) use ($countries, $impact) {
            if (!empty($countries)) {
                $code = $e['country_code'] ?? null;
                if (!$code || !in_array($code, $countries, true)) return false;
            }
            if (!empty($impact)) {
                $imp = (int)($e['importance'] ?? 0);
                if ($imp <= 0 || !in_array($imp, $impact, true)) return false;
            }
            return true;
        }));
    }

    private function loadFromCache(string $cacheFile, int $ttl): ?array
    {
        if (!file_exists($cacheFile)) return null;
        if (time() - filemtime($cacheFile) > $ttl) return null;
        $content = @file_get_contents($cacheFile);
        if ($content === false) return null;
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    private function saveToCache(string $cacheFile, array $events): void
    {
        @file_put_contents($cacheFile, json_encode($events, JSON_UNESCAPED_UNICODE));
    }

    private function mergeWithFallback(?array $existing): array
    {
        $fb = $this->getFallbackEvents();
        if (empty($existing)) return $fb;

        $seen = [];
        foreach ($existing as $e) {
            $key = $this->dedupKey($e);
            $seen[$key] = $e;
        }
        foreach ($fb as $e) {
            $key = $this->dedupKey($e);
            if (!isset($seen[$key])) $seen[$key] = $e;
        }
        $merged = array_values($seen);
        usort($merged, fn($a, $b) => strcmp($a['datetime_utc'] ?? '', $b['datetime_utc'] ?? ''));
        return $merged;
    }

    private function dedupKey(array $e): string
    {
        $titleNorm = strtoupper(preg_replace('/[^A-Z0-9]/', '', $this->translateTitle($e['original_title'] ?? $e['title'] ?? '')));
        return ($e['datetime_utc'] ?? '') . '|' . ($e['country_code'] ?? '') . '|' . $titleNorm;
    }

    private function getFallbackEvents(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $events = [];
        $offsets = [0, 0, 0, 0, 1, 7, 1];
        foreach (self::FALLBACK_EVENTS as $i => $fb) {
            $d = $now->modify('+' . ($offsets[$i] ?? 0) . ' days');
            $time = $fb['time'] ?? '00:00';
            $code = $fb['country_code'] ?? '';
            $countryLabel = self::COUNTRY_MAP[$code] ?? ($code . ' ' . ($fb['currency'] ?? ''));
            $events[] = array_merge($fb, [
                'datetime_utc' => $d->format('Y-m-d') . 'T' . $time . ':00Z',
                'country' => $countryLabel,
            ]);
        }
        usort($events, fn($a, $b) => strcmp($a['datetime_utc'] ?? '', $b['datetime_utc'] ?? ''));
        return $events;
    }

    private function applyTimezone(array $events, string $tzRaw): array
    {
        $offsetMinutes = $this->getOffsetMinutes($tzRaw);
        $tzLabel = $this->getTzLabel($tzRaw, $offsetMinutes);
        foreach ($events as &$e) {
            $utc = $e['datetime_utc'] ?? null;
            if (!$utc) continue;
            try {
                $dt = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
                if ($offsetMinutes !== 0) {
                    $local = $dt->modify(($offsetMinutes >= 0 ? '+' : '-') . abs($offsetMinutes) . ' minutes');
                } else {
                    $local = $dt;
                }
                $e['date'] = $local->format('Y-m-d');
                $e['time'] = $local->format('H:i');
                $e['tz_offset_minutes'] = $offsetMinutes;
                $e['tz_label'] = $tzLabel;
            } catch (\Throwable $ex) {
                $e['date'] = substr($utc, 0, 10);
                $e['time'] = substr($utc, 11, 5);
                $e['tz_offset_minutes'] = 0;
                $e['tz_label'] = 'UTC';
            }
        }
        unset($e);
        return $events;
    }

    private function fetchFromTradingView(): ?array
    {
        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $from = $now->format('Y-m-d');
            $to = $now->modify('+14 days')->format('Y-m-d');

            $client = HttpClient::create([
                'timeout' => 8,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                    'Accept' => 'application/json',
                    'Origin' => 'https://www.tradingview.com',
                    'Referer' => 'https://www.tradingview.com/',
                ],
            ]);

            $url = self::TV_EVENTS_URL . '?from=' . $from . '&to=' . $to;
            $response = $client->request('GET', $url);
            if ($response->getStatusCode() !== 200) return null;

            $data = $response->toArray();
            $rows = $data['result'] ?? null;
            if (!is_array($rows) || count($rows) === 0) return null;

            return $this->normalizeEvents($rows);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeEvents(array $rows): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $cutoff = $now->modify('-1 day');
        $max = $now->modify('+14 days');

        $events = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;

            $title = trim($row['title'] ?? $row['indicator'] ?? '');
            if (!$title) continue;

            $dateStr = $row['date'] ?? null;
            if (!$dateStr) continue;

            $dt = $this->parseTvDate($dateStr);
            if ($dt === null) continue;

            $dt = $dt->setTimezone(new \DateTimeZone('UTC'));
            if ($dt < $cutoff || $dt > $max) continue;

            $country = strtoupper($row['country'] ?? '');
            $currency = strtoupper($row['currency'] ?? '');
            $tvImportance = (int)($row['importance'] ?? 0);

            $events[] = [
                'datetime_utc' => $dt->format('Y-m-d\TH:i:s\Z'),
                'country' => self::COUNTRY_MAP[$country] ?? (trim($country) . ' ' . $currency),
                'country_code' => $country,
                'currency' => $currency,
                'title' => $this->translateTitle($title),
                'original_title' => $title,
                'importance' => $this->normalizeImportance($tvImportance),
                'actual' => $this->formatValue($row['actual'] ?? null, $row['unit'] ?? ''),
                'forecast' => $this->formatValue($row['forecast'] ?? null, $row['unit'] ?? ''),
                'previous' => $this->formatValue($row['previous'] ?? null, $row['unit'] ?? ''),
                'period' => $row['period'] ?? null,
            ];
        }

        usort($events, fn($a, $b) => strcmp($a['datetime_utc'] ?? '', $b['datetime_utc'] ?? ''));
        return $events;
    }

    private function parseTvDate(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        $fmts = [
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s\Z',
            'Y-m-d\TH:i:s',
            'm/d/Y H:i:s',
            'm/d/Y H:i',
            'Y-m-d H:i:s',
        ];
        foreach ($fmts as $fmt) {
            try {
                return new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
            } catch (\Throwable $e) {
                continue;
            }
        }
        $ts = strtotime($raw);
        if ($ts !== false) {
            return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('UTC'));
        }
        return null;
    }

    private function normalizeImportance(int $tvRaw): int
    {
        return match (true) {
            $tvRaw >= 1 => 3,
            $tvRaw === 0 => 2,
            $tvRaw < 0 => 1,
            default => 1,
        };
    }

    private function translateTitle(string $title): string
    {
        $clean = trim($title);
        if (isset(self::SPANISH_TITLES[$clean])) {
            return self::SPANISH_TITLES[$clean];
        }
        foreach (self::SPANISH_TITLES as $en => $es) {
            if (stripos($clean, $en) !== false) return $es;
        }
        return $clean;
    }

    private function formatValue($value, string $unit): string
    {
        if ($value === null || $value === '' || $value === '-') return '—';
        if (is_numeric($value)) {
            $f = (float)$value;
            $formatted = abs($f - round($f)) < 0.0001 ? number_format($f, 0) : rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
            return $formatted . ($unit ? $unit : '');
        }
        return (string)$value;
    }
}
