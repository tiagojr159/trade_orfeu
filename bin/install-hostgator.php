<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;
use Throwable;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

try {
    $config = new Config();
    if ($config->get('APP_ENV') !== 'hostgator' || $config->get('ALLOW_LOCAL_ROOT') !== 'false') {
        throw new RuntimeException('HOSTGATOR_CONFIG_REQUIRED');
    }

    $db = $config->connect();
    $schema = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    if (!preg_match('/^[a-zA-Z0-9]{1,24}_orfeu$/D', $schema)) {
        throw new RuntimeException('DB_NOT_ORFEU');
    }

    $store = new Store($db);
    $result = [
        'schema' => $schema,
        'migrations' => $store->migrate(false),
        'calendar_sessions_and_closures' => $store->importCalendar(Calendar::official2026()),
        'catalog_rows' => $store->catalog(ROOT . '/config/seed-catalog.csv'),
        'season' => $store->season('season-001', cents($config->get('INITIAL_CAPITAL_BRL', '1000.00')), utc()),
    ];
    echo json($result) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json(['status' => 'ERROR', 'code' => publicError($error)]) . PHP_EOL);
    exit(1);
}
