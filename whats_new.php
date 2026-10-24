<?php
require 'auth.php';
require 'config.php';
app_start_session();
app_handle_lang_switch($conn);

if (!function_exists('wn_normalize_version')) {
    function wn_normalize_version(string $value): string
    {
        $value = strtolower(trim($value));
        return ltrim($value, 'v');
    }
}

if (!function_exists('wn_parse_changelog')) {
    function wn_parse_changelog(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $entries = [];
        $entryIndex = -1;
        $sectionIndex = -1;

        foreach ($lines as $rawLine) {
            $line = trim((string)$rawLine);
            if ($line === '' || strpos($line, '# Changelog') === 0 || strpos($line, 'All notable changes') === 0) {
                continue;
            }

            if (strpos($line, '## ') === 0) {
                $entryIndex++;
                $sectionIndex = -1;
                $version = trim(substr($line, 3));
                $entries[$entryIndex] = [
                    'version' => $version,
                    'version_key' => wn_normalize_version($version),
                    'sections' => [],
                ];
                continue;
            }

            if ($entryIndex < 0) {
                continue;
            }

            if (strpos($line, '### ') === 0) {
                $sectionIndex++;
                $entries[$entryIndex]['sections'][$sectionIndex] = [
                    'title' => trim(substr($line, 4)),
                    'items' => [],
                ];
                continue;
            }

            if (strpos($line, '- ') === 0) {
                if ($sectionIndex < 0) {
                    $sectionIndex = 0;
                    $entries[$entryIndex]['sections'][$sectionIndex] = [
                        'title' => 'Notes',
                        'items' => [],
                    ];
                }
                $entries[$entryIndex]['sections'][$sectionIndex]['items'][] = trim(substr($line, 2));
            }
        }

        return array_values(array_filter($entries));
    }
}

$isEnglish = app_current_lang($conn) === 'en';
$currentVersion = 'v' . app_version($conn);
$currentVersionKey = wn_normalize_version($currentVersion);
$entries = wn_parse_changelog(__DIR__ . '/CHANGELOG.md');
$latestEntry = $entries[0] ?? null;
$currentEntry = null;
foreach ($entries as $entry) {
    if (($entry['version_key'] ?? '') === $currentVersionKey) {
        $currentEntry = $entry;
        break;
    }
}
if ($currentEntry === null) {
    $currentEntry = $latestEntry;
}

require 'header.php';
?>
<style>
    .wn-wrap{max-width:1220px;margin:18px auto 36px;padding:0 12px}
    .wn-hero,.wn-card{background:linear-gradient(165deg,#11131a,#0f1016);border:1px solid #2d3140;border-radius:18px;box-shadow:0 12px 28px rgba(0,0,0,.26)}
    .wn-hero{padding:22px 24px;margin-bottom:16px;display:grid;grid-template-columns:minmax(0,1.5fr) minmax(280px,.8fr);gap:16px;align-items:start}
    .wn-kicker{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border-radius:999px;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.24);color:#f3d67c;font-size:.84rem;font-weight:700}
    .wn-title{margin:14px 0 10px;color:#fff;font-size:2rem;font-weight:800}
    .wn-sub{margin:0;color:#bcc3cf;line-height:1.8}
    .wn-side{display:grid;gap:12px}
    .wn-stat{padding:14px 16px;border-radius:16px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06)}
    .wn-stat-label{display:block;color:#94a0b4;font-size:.82rem;margin-bottom:6px}
    .wn-stat-value{display:block;color:#fff;font-size:1.12rem;font-weight:800}
    .wn-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,.9fr);gap:16px}
    .wn-card{padding:18px 20px}
    .wn-card h2{margin:0 0 14px;color:#f5f7fb;font-size:1.15rem}
    .wn-release{display:grid;gap:14px}
    .wn-section{padding:14px 15px;border-radius:16px;background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.06)}
    .wn-section h3{margin:0 0 10px;color:#f1d271;font-size:.96rem}
    .wn-list{margin:0;padding:0;list-style:none;display:grid;gap:9px}
    .wn-list li{color:#d5d9e2;line-height:1.7;padding-inline-start:18px;position:relative}
    .wn-list li::before{content:'';position:absolute;inset-inline-start:0;top:.72em;width:8px;height:8px;border-radius:50%;background:#d4af37;box-shadow:0 0 0 4px rgba(212,175,55,.14)}
    .wn-timeline{display:grid;gap:12px}
    .wn-version{padding:14px 15px;border-radius:16px;background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.06)}
    .wn-version.current{border-color:rgba(212,175,55,.34);background:rgba(212,175,55,.08)}
    .wn-version-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}
    .wn-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:.75rem;font-weight:700;background:rgba(212,175,55,.14);border:1px solid rgba(212,175,55,.24);color:#f3d67c}
    .wn-mini{margin:0;padding:0;list-style:none;display:grid;gap:6px}
    .wn-mini li{color:#aab3c1;font-size:.92rem}
    .wn-empty{padding:18px;border-radius:16px;background:rgba(255,255,255,.03);border:1px dashed rgba(255,255,255,.14);color:#b9c2cf}
    @media (max-width: 980px){
        .wn-hero,.wn-grid{grid-template-columns:1fr}
    }
</style>

<div class="wn-wrap">
    <section class="wn-hero">
        <div>
            <span class="wn-kicker"><i class="fa-solid fa-sparkles"></i> <?php echo app_h(app_tr('ما الجديد', "What's New")); ?></span>
            <h1 class="wn-title"><?php echo app_h(app_tr('تحديثات النظام والإصدارات', 'System updates and releases')); ?></h1>
            <p class="wn-sub">
                <?php echo app_h(app_tr('هذا القسم يعرض أحدث التحسينات والإصلاحات والوظائف الجديدة، مع إبراز الإصدار الحالي المستخدم داخل النظام.', 'This section shows the latest improvements, fixes, and new features, while highlighting the version currently running in the system.')); ?>
            </p>
        </div>
        <div class="wn-side">
            <div class="wn-stat">
                <span class="wn-stat-label"><?php echo app_h(app_tr('الإصدار الحالي', 'Current version')); ?></span>
                <span class="wn-stat-value"><?php echo app_h($currentVersion); ?></span>
            </div>
            <div class="wn-stat">
                <span class="wn-stat-label"><?php echo app_h(app_tr('آخر إصدار موثق', 'Latest documented release')); ?></span>
                <span class="wn-stat-value"><?php echo app_h((string)($latestEntry['version'] ?? $currentVersion)); ?></span>
            </div>
            <div class="wn-stat">
                <span class="wn-stat-label"><?php echo app_h(app_tr('عدد الإصدارات المعروضة', 'Shown releases')); ?></span>
                <span class="wn-stat-value"><?php echo (int)count($entries); ?></span>
            </div>
        </div>
    </section>

    <div class="wn-grid">
        <section class="wn-card">
            <h2><?php echo app_h(app_tr('الإصدار الحالي بالتفصيل', 'Current release details')); ?></h2>
            <?php if (is_array($currentEntry) && !empty($currentEntry['sections'])): ?>
                <div class="wn-release">
                    <?php foreach ($currentEntry['sections'] as $section): ?>
                        <div class="wn-section">
                            <h3><?php echo app_h((string)($section['title'] ?? '')); ?></h3>
                            <?php if (!empty($section['items'])): ?>
                                <ul class="wn-list">
                                    <?php foreach ($section['items'] as $item): ?>
                                        <li><?php echo app_h((string)$item); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="wn-empty"><?php echo app_h(app_tr('لا توجد تفاصيل إضافية في هذا القسم حالياً.', 'No additional details in this section yet.')); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="wn-empty"><?php echo app_h(app_tr('لم يتم العثور على سجل تغييرات مفصل لهذا الإصدار بعد.', 'No detailed changelog was found for this release yet.')); ?></div>
            <?php endif; ?>
        </section>

        <aside class="wn-card">
            <h2><?php echo app_h(app_tr('سجل الإصدارات', 'Release timeline')); ?></h2>
            <?php if (!empty($entries)): ?>
                <div class="wn-timeline">
                    <?php foreach ($entries as $entry): ?>
                        <?php
                        $isCurrent = (($entry['version_key'] ?? '') === $currentVersionKey);
                        $sectionCount = is_array($entry['sections'] ?? null) ? count($entry['sections']) : 0;
                        $itemCount = 0;
                        foreach (($entry['sections'] ?? []) as $section) {
                            $itemCount += is_array($section['items'] ?? null) ? count($section['items']) : 0;
                        }
                        ?>
                        <div class="wn-version <?php echo $isCurrent ? 'current' : ''; ?>">
                            <div class="wn-version-top">
                                <strong><?php echo app_h((string)($entry['version'] ?? '')); ?></strong>
                                <?php if ($isCurrent): ?><span class="wn-badge"><i class="fa-solid fa-circle-check"></i> <?php echo app_h(app_tr('الحالي', 'Current')); ?></span><?php endif; ?>
                            </div>
                            <ul class="wn-mini">
                                <li><?php echo app_h(app_tr('أقسام التحديث', 'Update sections')); ?>: <?php echo (int)$sectionCount; ?></li>
                                <li><?php echo app_h(app_tr('العناصر الموثقة', 'Documented items')); ?>: <?php echo (int)$itemCount; ?></li>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="wn-empty"><?php echo app_h(app_tr('ملف CHANGELOG غير متاح حالياً.', 'The changelog file is not available right now.')); ?></div>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php require 'footer.php'; ?>
