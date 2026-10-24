<?php
// utilities to update translation files from code usage
// run this from project root: php scripts/update_translations.php

$baseDir = __DIR__ . "/../";
$codeDir = $baseDir;
$jsonPath = $baseDir . "i18n/en.json";
$keys = [];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($codeDir));
foreach ($iterator as $file) {
    if (!$file->isFile()) continue;
    $path = $file->getPathname();
    if (strpos($path, '/i18n/') !== false) continue;
    if (pathinfo($path, PATHINFO_EXTENSION) !== 'php') continue;
    $contents = file_get_contents($path);
    if (preg_match_all("/app_t\('([^']+)'\s*,\s*'(.*?)'/", $contents, $m)) {
        foreach ($m[1] as $i => $key) {
            $fallback = $m[2][$i];
            $keys[$key] = $fallback;
        }
    }
}

$existing = [];
if (file_exists($jsonPath)) {
    $existing = json_decode(file_get_contents($jsonPath), true) ?: [];
}
$added = 0;
foreach ($keys as $k => $f) {
    if (!array_key_exists($k, $existing)) {
        $existing[$k] = $f;
        $added++;
    }
}
ksort($existing);
file_put_contents($jsonPath, json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "Added $added keys to en.json\n";
