<?php
declare(strict_types=1);

namespace Orfeu;

use Throwable;

$privateConfig = __DIR__ . '/private-root.php';
$privateRoot = is_file($privateConfig) ? require $privateConfig : dirname(__DIR__);
if (!is_string($privateRoot) || !is_file($privateRoot . '/app/bootstrap.php')) {
    http_response_code(503);
    exit;
}
require $privateRoot . '/app/bootstrap.php';

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$base = rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
$route = trim(substr($path, strlen($base)), '/');
$route = preg_replace('~^index\.php(?:/|$)~', '', $route);
$api = strpos($route,'api/v1/') === 0;
try {
    $config = new Config();
    if ($config->get('APP_ENV') === 'hostgator') {
        $setupDb = $config->connect();
        if (!$setupDb->query('SHOW FULL TABLES')->fetch()) {
            $setupStore = new Store($setupDb);
            $setupStore->migrate(false);
            $setupStore->importCalendar(Calendar::official2026());
            $setupStore->catalog(ROOT . '/config/seed-catalog.csv');
            $setupStore->season('season-001', cents($config->get('INITIAL_CAPITAL_BRL', '1000.00')), utc());
        }
    }
    if ($config->get('ALLOW_LOCAL_ROOT') === 'true' &&
        !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) {
        http_response_code(403);
        exit;
    }
    $method = $_SERVER['REQUEST_METHOD'];
    if (isset($_GET['mode']) && $_GET['mode'] !== 'PAPER_TRADING') {
        throw new \RuntimeException('PAPER_MODE_REQUIRED');
    }
    if (strpos($route,'api/v1/admin/') === 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json(adminRoute($config,substr($route,13),$method));
        exit;
    }
    if ($method !== 'GET' && $method !== 'HEAD') {
        header('Allow: GET, HEAD');
        http_response_code(405);
        exit;
    }
    if ($api) {
        header('Content-Type: application/json; charset=utf-8');
        $page = filter_var($_GET['page'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000]]);
        $limit = filter_var($_GET['limit'] ?? 50,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>200]]);
        $account = isset($_GET['account']) ? filter_var($_GET['account'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) : null;
        if (!$page || !$limit || $account === false) {
            throw new \RuntimeException('INVALID_FILTER');
        }
        $service = new ReadApi(new Store($config->connect()));
        echo json($service->route(substr($route,7),utc(),$page,$limit,$account));
        exit;
    }
    if ($route !== '') {
        http_response_code(404);
        exit;
    }
    require __DIR__ . '/dashboard.php';
} catch (Throwable $error) {
    $code = publicError($error);
    if ($code === 'NOT_FOUND') {
        http_response_code(404);
    } elseif ($code === 'ADMIN_AUTH_REQUIRED') {
        http_response_code(401);
    } elseif (in_array($code,['ADMIN_INVALID_CSRF','ADMIN_HTTPS_REQUIRED'],true)) {
        http_response_code(403);
    } elseif ($code === 'ADMIN_RATE_LIMIT') {
        http_response_code(429);
    } elseif (in_array($code,['INVALID_FILTER','PAPER_MODE_REQUIRED'],true)) {
        http_response_code(400);
    } else {
        http_response_code(503);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json(['mode'=>'PAPER_TRADING','as_of'=>utc(),'data_status'=>'UNAVAILABLE','code'=>$code,'items'=>[]]);
}
