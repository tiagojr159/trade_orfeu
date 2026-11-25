<?php
declare(strict_types=1);

namespace Orfeu;

use PDO;
use RuntimeException;
use ZipArchive;

final class HttpFailure extends RuntimeException
{
    public $status;
    public $retryAfter;

    public function __construct(string $message, int $status = 0, int $retryAfter = 0)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->retryAfter = $retryAfter;
    }
}

function https(string $url, array $headers = [], string $ca = ''): string
{
    $allowed = ['brapi.dev', 'api.bcb.gov.br'];
    if (!in_array(parse_url($url, PHP_URL_HOST), $allowed, true) ||
        parse_url($url, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('INVALID_SOURCE');
    }
    $body = '';
    $retryAfter = 0;
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 2 * 1024 * 1024) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$retryAfter): int {
            if (stripos($line, 'Retry-After:') === 0) {
                $value = trim(substr($line, 12));
                $retryAfter = ctype_digit($value) ? (int) $value : max(0, (int) strtotime($value) - time());
            }
            return strlen($line);
        },
    ]);
    if ($ca !== '') {
        curl_setopt($curl, CURLOPT_CAINFO, $ca);
    }
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_errno($curl);
    curl_close($curl);
    if (!$ok || $status !== 200) {
        throw new HttpFailure($error === 28 ? 'SOURCE_TIMEOUT' :
            ($error ? 'SOURCE_TRANSPORT_' . $error : 'SOURCE_HTTP_' . $status), $status, $retryAfter);
    }
    return $body;
}

final class Brapi
{
    private $request;
    private $token;

    public function __construct(string $token, ?callable $request = null, string $ca = '')
    {
        if (preg_match('/[\r\n]/', $token)) {
            throw new RuntimeException('INVALID_TOKEN_FORMAT');
        }
        $this->token = $token;
        $this->request = $request ?? static function (string $url, array $headers) use ($ca): string {
            return https($url, $headers, $ca);
        };
    }

    public function quotes(array $symbols, string $received): array
    {
        if (!$symbols || count($symbols) > 10 || count($symbols) !== count(array_unique($symbols))) {
            throw new RuntimeException('INVALID_SYMBOL_BATCH');
        }
        foreach ($symbols as $symbol) {
            if (!preg_match('/^[A-Z0-9]{4,12}$/D', $symbol)) {
                throw new RuntimeException('INVALID_SYMBOL');
            }
        }
        $raw = ($this->request)('https://brapi.dev/api/quote/' . implode(',', $symbols),
            $this->token === '' ? [] : ['Authorization: Bearer ' . $this->token]);
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('INVALID_QUOTE_SCHEMA', 0, $e);
        }
        if (!is_array($data['results'] ?? null)) {
            throw new RuntimeException('INVALID_QUOTE_SCHEMA');
        }
        $rows = [];
        foreach ($data['results'] as $quote) {
            $symbol = $quote['symbol'] ?? '';
            $price = $quote['regularMarketPrice'] ?? null;
            $event = $quote['regularMarketTime'] ?? null;
            if (!in_array($symbol, $symbols, true) || isset($rows[$symbol]) ||
                ($quote['currency'] ?? '') !== 'BRL' || !is_numeric($price) ||
                !is_finite((float) $price) || $price <= 0 || $price >= 1000000 || !is_string($event)) {
                throw new RuntimeException('INVALID_QUOTE_SCHEMA');
            }
            $eventAt = timestamp($event);
            if ($eventAt > $received) {
                throw new RuntimeException('FUTURE_QUOTE');
            }
            $rows[$symbol] = [
                'symbol' => $symbol, 'event_at' => $eventAt, 'available_at' => $received,
                'price_cents' => (int) round($price * 100),
                'delay_seconds' => strtotime($received . ' UTC') - strtotime($eventAt . ' UTC'),
                'raw_hash' => hash('sha256', json($quote)),
            ];
        }
        return ['rows' => array_values($rows), 'missing' => array_values(array_diff($symbols, array_keys($rows))), 'raw' => $raw];
    }
}

final class Cotahist
{
    public static function marketRecords(iterable $lines): \Generator
    {
        $count = 0;
        $header = null;
        $closed = false;
        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            $type = substr($line, 0, 2);
            $count++;
            if (strlen($line) !== 245 || $closed) {
                throw new RuntimeException('INVALID_COTAHIST_ENVELOPE');
            }
            if ($count === 1) {
                if ($type !== '00' || substr($line, 2, 8) !== 'COTAHIST') {
                    throw new RuntimeException('INVALID_COTAHIST_HEADER');
                }
                $header = substr($line, 2, 29);
            } elseif ($type === '99') {
                $total = substr($line, 31, 11);
                if ($count < 3 || substr($line, 2, 29) !== $header || !ctype_digit($total) || (int) $total !== $count) {
                    throw new RuntimeException('INVALID_COTAHIST_TRAILER');
                }
                $closed = true;
            } elseif ($type !== '01') {
                throw new RuntimeException('INVALID_COTAHIST_RECORD');
            } else {
                yield $line;
            }
        }
        if (!$closed) {
            throw new RuntimeException('COTAHIST_TRAILER_MISSING');
        }
    }

    public static function parse(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        if (strlen($line) !== 245) {
            throw new RuntimeException('INVALID_COTAHIST_LENGTH');
        }
        if (in_array(substr($line, 0, 2), ['00', '99'], true)) {
            return null;
        }
        if (substr($line, 0, 2) !== '01') {
            throw new RuntimeException('INVALID_COTAHIST_RECORD');
        }
        $market = substr($line, 24, 3);
        $kind = trim(substr($line, 39, 10));
        if (!in_array($market, ['010', '020'], true) || !preg_match('/^(ON|PN[A-Z]?)(?:\s|$)/', $kind)) {
            return null;
        }
        $rawDate = substr($line, 2, 8);
        $date = day(substr($rawDate, 0, 4) . '-' . substr($rawDate, 4, 2) . '-' . substr($rawDate, 6));
        $number = static function (int $start, int $length) use ($line): int {
            $value = substr($line, $start, $length);
            if (!ctype_digit($value)) {
                throw new RuntimeException('INVALID_COTAHIST_NUMBER');
            }
            return (int) $value;
        };
        if ($date < '2015-01-01' || trim(substr($line, 52, 4)) !== 'R$' || $number(210, 7) !== 1) {
            throw new RuntimeException('UNSUPPORTED_COTAHIST_PRICE_UNIT');
        }
        return [
            'session_date' => $date, 'symbol' => trim(substr($line, 12, 12)),
            'isin' => substr($line, 230, 12), 'market' => $market,
            'open_px' => $number(56, 13), 'high_px' => $number(69, 13),
            'low_px' => $number(82, 13), 'close_px' => $number(108, 13),
            'volume' => $number(152, 18), 'financial_volume' => $number(170, 18),
            'raw_hash' => hash('sha256', $line),
        ];
    }

    public static function lines(string $file): \Generator
    {
        $zip = null;
        $stream = null;
        try {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'zip') {
                $zip = new ZipArchive();
                if ($zip->open($file, ZipArchive::RDONLY) !== true) {
                    throw new RuntimeException('INVALID_COTAHIST_ZIP');
                }
                $entry = $zip->statIndex(0);
                if ($zip->numFiles !== 1 || !$entry || $entry['size'] > 512 * 1024 * 1024 ||
                    !preg_match('/^COTAHIST[A-Z0-9_.-]*\.TXT$/iD', $entry['name'])) {
                    throw new RuntimeException('UNSAFE_COTAHIST_ZIP');
                }
                $stream = $zip->getStream($entry['name']);
            } else {
                $stream = fopen($file, 'rb');
            }
            if (!$stream) {
                throw new RuntimeException('INVALID_COTAHIST_FILE');
            }
            $total = 0;
            while (($line = fgets($stream, 260)) !== false) {
                $total += strlen($line);
                if ($total > 512 * 1024 * 1024 || strlen($line) > 247) {
                    throw new RuntimeException('COTAHIST_FILE_LIMIT');
                }
                yield $line;
            }
            if (!feof($stream)) {
                throw new RuntimeException('COTAHIST_READ_ERROR');
            }
        } finally {
            if ($stream) {
                fclose($stream);
            }
            if ($zip instanceof ZipArchive) {
                $zip->close();
            }
        }
    }
}

function csvRows(string $file, string $separator = ','): \Generator
{
    $stream = fopen($file, 'rb');
    if (!$stream) {
        throw new RuntimeException('CSV_UNAVAILABLE');
    }
    try {
        $keys = fgetcsv($stream, 0, $separator, '"', '');
        if (!$keys || count($keys) !== count(array_unique($keys))) {
            throw new RuntimeException('INVALID_CSV_HEADER');
        }
        $keys[0] = preg_replace('/^\xEF\xBB\xBF/', '', $keys[0]);
        while (($fields = fgetcsv($stream, 0, $separator, '"', '')) !== false) {
            if ($fields === [null]) {
                continue;
            }
            if (count($fields) !== count($keys)) {
                throw new RuntimeException('INVALID_CSV_ROW');
            }
            yield array_combine($keys, $fields);
        }
    } finally {
        fclose($stream);
    }
}

function saveRaw(string $source, string $raw): string
{
    $hash = hash('sha256', $raw);
    $dir = ROOT . '/storage/raw/' . $source;
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('RAW_DIRECTORY_UNAVAILABLE');
    }
    $path = $dir . '/' . $hash . '.json';
    if (!is_file($path)) {
        $tmp = tempnam($dir, 'raw');
        if (!$tmp) {
            throw new RuntimeException('RAW_WRITE_FAILED');
        }
        try {
            if (file_put_contents($tmp, $raw, LOCK_EX) !== strlen($raw) || !rename($tmp, $path)) {
                throw new RuntimeException('RAW_WRITE_FAILED');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
    return $hash;
}
