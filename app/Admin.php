<?php
declare(strict_types=1);

namespace Orfeu;

use PDO;
use RuntimeException;

function adminSession(Config $config): void
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if ($config->get('APP_ENV','local') === 'hostgator' && !$secure) {
        throw new RuntimeException('ADMIN_HTTPS_REQUIRED');
    }
    if ($config->get('ADMIN_PASSWORD_HASH') === '') {
        throw new RuntimeException('NOT_FOUND');
    }
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    session_name('ORFEUADMIN');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
    if (!session_start()) {
        throw new RuntimeException('ADMIN_SESSION_UNAVAILABLE');
    }
    if (isset($_SESSION['role']) && ($_SESSION['expires'] ?? 0) <= time()) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function adminAuthorize(array $session, string $csrf, bool $login = false): void
{
    if (!$login && (($session['role'] ?? '') !== 'admin' || ($session['expires'] ?? 0) <= time())) {
        throw new RuntimeException('ADMIN_AUTH_REQUIRED');
    }
    if ($csrf === '' || !isset($session['csrf']) || !hash_equals($session['csrf'],$csrf)) {
        throw new RuntimeException('ADMIN_INVALID_CSRF');
    }
}

function adminRoute(Config $config, string $route, string $method): array
{
    adminSession($config);
    if ($route === 'session' && $method === 'GET') {
        return ['authenticated'=>($_SESSION['role'] ?? '') === 'admin','csrf'=>$_SESSION['csrf']];
    }
    if ($method !== 'POST' || !in_array($route,['login','logout','pause','resume','season','promote'],true)) {
        throw new RuntimeException('NOT_FOUND');
    }
    adminAuthorize($_SESSION,$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '',$route === 'login');
    $raw = file_get_contents('php://input',false,null,0,4097);
    if (!$raw || strlen($raw) > 4096) {
        throw new RuntimeException('INVALID_ADMIN_PAYLOAD');
    }
    $input = json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new RuntimeException('INVALID_ADMIN_PAYLOAD');
    }
    $db = $config->connect();
    if ($route === 'login') {
        $client = hash('sha256',$_SERVER['REMOTE_ADDR'] ?? '');
        $window = intdiv(time(),600);
        $attempts = transaction($db,static function () use ($db,$client,$window): int {
            query($db,'INSERT INTO orf_rate_bucket VALUES(?,?,1) ON DUPLICATE KEY UPDATE attempts=LEAST(attempts+1,6)',
                [$client,$window]);
            return (int) query($db,'SELECT attempts FROM orf_rate_bucket WHERE client_key=? AND window_start=?',
                [$client,$window])->fetchColumn();
        });
        if ($attempts > 5) {
            throw new RuntimeException('ADMIN_RATE_LIMIT');
        }
        $password = $input['password'] ?? '';
        if (!is_string($password) || strlen($password) > 255 || !password_verify($password,$config->get('ADMIN_PASSWORD_HASH'))) {
            throw new RuntimeException('ADMIN_AUTH_REQUIRED');
        }
        session_regenerate_id(true);
        $_SESSION = ['role'=>'admin','csrf'=>bin2hex(random_bytes(32)),'expires'=>time()+1800];
        return ['status'=>'AUTHENTICATED','csrf'=>$_SESSION['csrf']];
    }
    if ($route === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        return ['status'=>'SIGNED_OUT'];
    }
    session_write_close();
    $store = new Store($db);
    $at = utc();
    if ($route === 'promote') {
        return promote($store,(string) ($input['model'] ?? ''),(string) ($input['evidence'] ?? ''),$at);
    }
    if ($route === 'season') {
        return lock($db,'pipeline',static function () use ($store,$db,$input,$at): array {
            $result = $store->season((string) ($input['name'] ?? ''),cents((string) ($input['capital'] ?? '')),$at);
            query($db,'INSERT INTO orf_admin_audit(as_of,action,payload) VALUES(?,?,?)',[$at,'SEASON_CREATE',json($result)]);
            return $result;
        });
    }
    $season = filter_var($input['season_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$season) {
        throw new RuntimeException('INVALID_SEASON');
    }
    return lock($db,'pipeline',static function () use ($db,$season,$route,$at): array {
        return transaction($db,static function () use ($db,$season,$route,$at): array {
            if (!query($db,'SELECT 1 FROM orf_season WHERE id=? FOR UPDATE',[$season])->fetchColumn()) {
                throw new RuntimeException('NOT_FOUND');
            }
            $pause = $route === 'pause' ? 1 : 0;
            query($db,'UPDATE orf_season SET paused=? WHERE id=?',[$pause,$season]);
            query($db,'INSERT INTO orf_admin_audit(as_of,action,payload) VALUES(?,?,?)',
                [$at,$pause ? 'PAUSE' : 'RESUME',json(['season_id'=>$season])]);
            return ['season_id'=>$season,'paused'=>(bool) $pause];
        });
    });
}
