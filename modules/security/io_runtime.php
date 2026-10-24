<?php

if (!function_exists('app_parse_size_to_bytes')) {
    function app_parse_size_to_bytes($value): int
    {
        if (is_int($value) || is_float($value)) {
            return max(0, (int)$value);
        }
        $raw = trim((string)$value);
        if ($raw === '') {
            return 0;
        }
        if (ctype_digit($raw)) {
            return (int)$raw;
        }
        if (!preg_match('/^\s*([\d.]+)\s*([kmgt]?b?)?\s*$/i', $raw, $matches)) {
            return 0;
        }
        $number = (float)$matches[1];
        $unit = strtolower((string)($matches[2] ?? ''));
        $map = [
            '' => 1,
            'b' => 1,
            'k' => 1024,
            'kb' => 1024,
            'm' => 1024 * 1024,
            'mb' => 1024 * 1024,
            'g' => 1024 * 1024 * 1024,
            'gb' => 1024 * 1024 * 1024,
            't' => 1024 * 1024 * 1024 * 1024,
            'tb' => 1024 * 1024 * 1024 * 1024,
        ];
        return (int)round($number * ($map[$unit] ?? 1));
    }
}

if (!function_exists('app_default_upload_max_bytes')) {
    function app_default_upload_max_bytes(): int
    {
        $configured = app_parse_size_to_bytes(app_env('APP_DEFAULT_UPLOAD_MAX_SIZE', '2048M'));
        return $configured > 0 ? $configured : (2048 * 1024 * 1024);
    }
}

if (!function_exists('app_store_uploaded_file')) {
    function app_store_uploaded_file(array $file, array $options = []): array
    {
        $dir = $options['dir'] ?? 'uploads';
        $prefix = $options['prefix'] ?? 'file_';
        $maxSize = isset($options['max_size']) ? (int)$options['max_size'] : app_default_upload_max_bytes();
        $allowedExt = array_map('strtolower', $options['allowed_extensions'] ?? []);
        $allowedMime = array_map('strtolower', $options['allowed_mimes'] ?? []);

        if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'path' => '', 'error' => app_upload_error_message((int)($file['error'] ?? 0))];
        }
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'path' => '', 'error' => 'Invalid upload source.'];
        }
        if (!empty($file['size']) && (int)$file['size'] > $maxSize) {
            return ['ok' => false, 'path' => '', 'error' => 'Uploaded file is too large. Max allowed: ' . (int)round($maxSize / 1024 / 1024) . ' MB.'];
        }

        $originalName = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (app_upload_name_is_suspicious($originalName)) {
            return ['ok' => false, 'path' => '', 'error' => 'Suspicious file name.'];
        }
        if (app_is_blocked_upload_extension($originalName)) {
            return ['ok' => false, 'path' => '', 'error' => 'Blocked file type.'];
        }
        if (!empty($allowedExt) && !in_array($ext, $allowedExt, true)) {
            return ['ok' => false, 'path' => '', 'error' => 'Invalid file extension.'];
        }

        if (empty($allowedMime) && $ext !== '') {
            $defaultMimeMap = [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'webp' => ['image/webp'],
                'gif' => ['image/gif'],
                'bmp' => ['image/bmp', 'image/x-ms-bmp'],
                'pdf' => ['application/pdf'],
                'txt' => ['text/plain'],
                'csv' => ['text/plain', 'text/csv', 'application/csv'],
                'doc' => ['application/msword', 'application/octet-stream'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
                'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
                'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
                'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
                'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
                'zip' => ['application/zip', 'application/x-zip-compressed'],
                'rar' => ['application/vnd.rar', 'application/x-rar', 'application/x-rar-compressed', 'application/octet-stream'],
                '7z' => ['application/x-7z-compressed', 'application/octet-stream'],
                'psd' => ['image/vnd.adobe.photoshop', 'application/octet-stream'],
                'ai' => ['application/pdf', 'application/postscript', 'application/octet-stream'],
                'eps' => ['application/postscript', 'application/eps', 'application/octet-stream'],
                'mp4' => ['video/mp4'],
                'mov' => ['video/quicktime'],
                'avi' => ['video/x-msvideo', 'application/octet-stream'],
                'mkv' => ['video/x-matroska', 'application/octet-stream'],
                'webm' => ['video/webm', 'audio/webm'],
                'mp3' => ['audio/mpeg'],
                'wav' => ['audio/wav', 'audio/x-wav'],
                'ogg' => ['audio/ogg', 'video/ogg'],
            ];
            $allowedMime = $defaultMimeMap[$ext] ?? [];
        }

        if (!empty($allowedMime)) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? strtolower((string)finfo_file($finfo, $file['tmp_name'])) : '';
            if ($finfo) {
                @finfo_close($finfo);
            }
            if ($mime === '' || !in_array($mime, $allowedMime, true)) {
                return ['ok' => false, 'path' => '', 'error' => 'Invalid file type.'];
            }
        }

        if (!app_ensure_dir($dir, 0755)) {
            return ['ok' => false, 'path' => '', 'error' => 'Upload directory is not writable.'];
        }
        app_harden_upload_directory($dir);

        $random = bin2hex(random_bytes(12));
        $filename = $prefix . $random . ($ext !== '' ? '.' . $ext : '');
        $dest = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (!@move_uploaded_file($file['tmp_name'], $dest)) {
            return ['ok' => false, 'path' => '', 'error' => 'Could not move uploaded file.'];
        }

        @chmod($dest, 0644);
        $path = str_replace('\\', '/', $dest);
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            app_audit_log_add($GLOBALS['conn'], 'upload.stored', [
                'entity_type' => 'file',
                'entity_key' => $path,
                'details' => [
                    'dir' => $dir,
                    'original_name' => $originalName,
                    'size' => (int)($file['size'] ?? 0),
                ],
            ]);
        }
        return ['ok' => true, 'path' => $path, 'error' => ''];
    }
}

if (!function_exists('app_harden_upload_directory')) {
    function app_harden_upload_directory(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $htaccessPath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccessPath)) {
            $rules = "Options -Indexes\n"
                . "Header set X-Content-Type-Options \"nosniff\"\n"
                . "RemoveHandler .php .phtml .phar .pl .py .cgi .asp .aspx .jsp .sh .bash\n"
                . "RemoveType .php .phtml .phar .pl .py .cgi .asp .aspx .jsp .sh .bash\n"
                . "php_flag engine off\n"
                . "<FilesMatch \"(?i)\\.(php|phtml|phar|pl|py|cgi|asp|aspx|jsp|sh|bash)$\">\n"
                . "    Order Allow,Deny\n"
                . "    Deny from all\n"
                . "</FilesMatch>\n";
            @file_put_contents($htaccessPath, $rules, LOCK_EX);
            @chmod($htaccessPath, 0644);
        }
        $indexPath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($indexPath)) {
            @file_put_contents($indexPath, "<!doctype html><meta charset=\"utf-8\"><title>403</title>", LOCK_EX);
            @chmod($indexPath, 0644);
        }
        $webConfigPath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'web.config';
        if (!is_file($webConfigPath)) {
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<configuration>\n"
                . "  <system.webServer>\n"
                . "    <security>\n"
                . "      <requestFiltering>\n"
                . "        <fileExtensions>\n"
                . "          <add fileExtension=\".php\" allowed=\"false\" />\n"
                . "          <add fileExtension=\".phtml\" allowed=\"false\" />\n"
                . "          <add fileExtension=\".phar\" allowed=\"false\" />\n"
                . "          <add fileExtension=\".asp\" allowed=\"false\" />\n"
                . "          <add fileExtension=\".aspx\" allowed=\"false\" />\n"
                . "          <add fileExtension=\".jsp\" allowed=\"false\" />\n"
                . "        </fileExtensions>\n"
                . "      </requestFiltering>\n"
                . "    </security>\n"
                . "  </system.webServer>\n"
                . "</configuration>\n";
            @file_put_contents($webConfigPath, $xml, LOCK_EX);
            @chmod($webConfigPath, 0644);
        }
    }
}

if (!function_exists('app_path_within')) {
    function app_path_within(string $path, string $baseDir): bool
    {
        $realPath = realpath($path);
        $realBase = realpath($baseDir);
        if ($realPath === false || $realBase === false) {
            return false;
        }
        $realBase = rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strpos($realPath, $realBase) === 0;
    }
}

if (!function_exists('app_safe_unlink')) {
    function app_safe_unlink(string $path, ?string $allowedBaseDir = null): bool
    {
        if ($path === '' || !is_file($path)) {
            return false;
        }
        if ($allowedBaseDir !== null && !app_path_within($path, $allowedBaseDir)) {
            return false;
        }
        return @unlink($path);
    }
}
