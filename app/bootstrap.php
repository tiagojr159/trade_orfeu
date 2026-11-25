<?php
declare(strict_types=1);

namespace Orfeu;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

const ROOT = __DIR__ . '/..';

final class Config
{
    private $values;
    private $hostgatorInferred = false;

    public function __construct(?array $values = null)
    {
        if ($values === null) {
            $file = ROOT . '/.env';
            $values = is_file($file) ? parse_ini_file($file, false, INI_SCANNER_RAW) : [];
            if ($values === false) {
                throw new RuntimeException('INVALID_ENV');
            }
        }
        $this->values = $values;
        if ($this->get('PAPER_TRADING_ONLY', 'true') !== 'true') {
            throw new RuntimeException('PAPER_MODE_REQUIRED');
        }
        $configuredEnvironment = getenv('APP_ENV');
        if ($configuredEnvironment === false) {
            $configuredEnvironment = $values['APP_ENV'] ?? 'local';
        }
        if ($configuredEnvironment === 'local' &&
            preg_match('/^[a-zA-Z0-9]{1,24}_orfeu$/D', $this->get('DB_DATABASE'))) {
            $this->hostgatorInferred = true;
        }
        $environment = $this->get('APP_ENV', 'local');
        $database = $this->get('DB_DATABASE', 'orfeu');
        if (!in_array($environment, ['local','hostgator'], true)) {
            throw new RuntimeException('INVALID_APP_ENV');
        }
        if ($environment === 'local' ? $database !== 'orfeu' :
            !preg_match('/^[a-zA-Z0-9]{1,24}_orfeu$/D',$database)) {
            throw new RuntimeException('DB_NOT_ORFEU');
        }
        if ($environment !== 'local' && $this->get('ALLOW_LOCAL_ROOT') === 'true') {
            throw new RuntimeException('ROOT_LOCAL_ONLY');
        }
    }

    public function get(string $key, string $default = ''): string
    {
        if ($key === 'APP_ENV' && $this->hostgatorInferred) {
            return 'hostgator';
        }
        $value = getenv($key);
        return $value === false ? (string) ($this->values[$key] ?? $default) : $value;
    }

    public function connect(): PDO
    {
        $host = $this->get('DB_HOST', 'localhost');
        $port = filter_var($this->get('DB_PORT', '3306'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $user = $this->get('DB_USERNAME', 'orfeu_app');
        $password = $this->get('DB_PASSWORD');
        $database = $this->get('DB_DATABASE','orfeu');
        $localRoot = $this->get('APP_ENV','local') === 'local' && $this->get('ALLOW_LOCAL_ROOT') === 'true' &&
            strtolower($user) === 'root';
        if (!in_array($host, ['localhost', '127.0.0.1'], true) || !$port || $user === '' ||
            (!$localRoot && (strtolower($user) === 'root' || $password === ''))) {
            throw new RuntimeException('BLOCKED_DB_CREDENTIALS');
        }
        try {
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4", $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 4,
            ]);
            $pdo->exec("SET time_zone = '+00:00'");
            if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
                throw new RuntimeException('DB_NOT_ORFEU');
            }
            return $pdo;
        } catch (\PDOException $e) {
            throw new RuntimeException('BLOCKED_DB_CONNECTION', 0, $e);
        }
    }
}

function query(PDO $db, string $sql, array $params = []): \PDOStatement
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function transaction(PDO $db, callable $work)
{
    $db->beginTransaction();
    try {
        $result = $work();
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function lock(PDO $db, string $key, callable $work)
{
    $lockKey = $db->query('SELECT DATABASE()')->fetchColumn() . ':' . $key;
    if ((int) query($db, 'SELECT GET_LOCK(?,0)', [$lockKey])->fetchColumn() !== 1) {
        throw new RuntimeException('SKIPPED_LOCKED');
    }
    try {
        return $work();
    } finally {
        query($db, 'SELECT RELEASE_LOCK(?)', [$lockKey]);
    }
}

function json($value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}

function utc(?string $value = null): string
{
    $zone = new DateTimeZone('UTC');
    return (new DateTimeImmutable($value ?? 'now', $zone))->setTimezone($zone)->format('Y-m-d H:i:s.u');
}

function timestamp(string $value): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)?$/D', $value)) {
        throw new RuntimeException('INVALID_TIMESTAMP');
    }
    $result = utc($value);
    if (substr($result, 0, 19) !== str_replace('T', ' ', substr($value, 0, 19))) {
        throw new RuntimeException('INVALID_TIMESTAMP');
    }
    return $result;
}

function day(string $value): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ||
        !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
        throw new RuntimeException('INVALID_DATE');
    }
    return $value;
}

function cents(string $value): int
{
    if (!preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/D', $value, $m)) {
        throw new RuntimeException('INVALID_MONEY');
    }
    return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
}

foreach (['Market', 'Sources', 'Quant', 'StrategyTrial', 'StrategyEvidence', 'ExecutionCost', 'Trading', 'Store', 'SessionJobs', 'Pipeline',
    'Research', 'Prediction', 'Replay', 'Intraday', 'Admin', 'Http'] as $module) {
    require_once __DIR__ . '/' . $module . '.php';
}
