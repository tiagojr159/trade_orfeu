<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$target = $root . '/storage/orfeu-hostgator.zip';
$files = ['.htaccess','.env.hostgator.example','public/.htaccess','public/index.php','public/dashboard.php',
    'public/assets/orfeu.css','public/assets/orfeu.js','bin/orfeu.php','bin/install-hostgator.php',
    'bin/tick-hostgator.sh','config/seed-catalog.csv','config/seeds.php','config/fixed-universe.php'];
foreach (['app/*.php','database/migrations/*.sql'] as $pattern) {
    foreach (glob($root . '/' . $pattern) as $path) {
        $files[] = substr($path, strlen($root) + 1);
    }
}
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    fwrite(STDERR, "ZIP_TARGET_UNAVAILABLE_OR_EXISTS\n");
    exit(1);
}
foreach ($files as $file) {
    $path = $root . '/' . $file;
    $resolved = realpath($path);
    $expected = str_replace('\\', '/', $path);
    if (!$resolved || str_replace('\\', '/', $resolved) !== $expected || !$zip->addFile($path,$file)) {
        $zip->unchangeAll();
        $zip->close();
        fwrite(STDERR, "ZIP_INPUT_UNAVAILABLE\n");
        exit(1);
    }
}
if (!$zip->close()) {
    fwrite(STDERR, "ZIP_WRITE_FAILED\n");
    exit(1);
}
echo "storage/orfeu-hostgator.zip\n";
