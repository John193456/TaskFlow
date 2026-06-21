<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/*
 * Local-only live reload endpoint.
 *
 * The browser calls this file every few seconds through live_reload.js.
 * It returns a single hash based on the modified time and size of TaskFlow's
 * PHP/CSS/JS files. When that hash changes, the browser reloads automatically.
 *
 * Security note:
 * The endpoint is enabled only on localhost/XAMPP. It does not return file
 * names, source code, paths, database data, or user data.
 */
taskflow_no_cache_headers();
header('Content-Type: application/json; charset=utf-8');

if (!taskflow_is_local_request()) {
    echo json_encode(['enabled' => false]);
    exit;
}

$fingerprints = [];
$allowedExtensions = ['php', 'css', 'js'];

foreach (new DirectoryIterator(__DIR__) as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $extension = strtolower($file->getExtension());
    if (!in_array($extension, $allowedExtensions, true)) {
        continue;
    }

    /*
     * Only a hash is returned to the browser, but the filename is included in
     * the internal fingerprint so adding/removing/editing files changes the
     * version reliably.
     */
    $fingerprints[] = $file->getFilename() . ':' . $file->getMTime() . ':' . $file->getSize();
}

sort($fingerprints, SORT_STRING);

echo json_encode([
    'enabled' => true,
    'version' => hash('sha256', implode('|', $fingerprints)),
]);
