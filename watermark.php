<?php
// watermark.php - (Royal Protector V1.0)
// يقوم بوضع ختم مائي على الصور ديناميكياً لحمايتها من السرقة
require_once __DIR__ . '/config.php';

function watermark_blank_image(): void
{
    header("Content-Type: image/png");
    $im = @imagecreate(1, 1);
    imagecolorallocate($im, 255, 255, 255);
    imagepng($im);
    imagedestroy($im);
    exit;
}

function watermark_media_allowed_for_client_review(mysqli $conn, string $token, string $src): bool
{
    if ($token === '' || $src === '') {
        return false;
    }

    $stmt = $conn->prepare("SELECT id FROM job_orders WHERE access_token = ? AND LOWER(TRIM(current_stage)) IN ('client_rev', 'design_review') LIMIT 1");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$job) {
        return false;
    }

    $jobId = (int)$job['id'];
    $stmt = $conn->prepare("SELECT id FROM job_proofs WHERE job_id = ? AND file_path = ? LIMIT 1");
    $stmt->bind_param('is', $jobId, $src);
    $stmt->execute();
    $proofOk = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($proofOk) {
        return true;
    }

    $stmt = $conn->prepare("SELECT design_path FROM social_posts WHERE job_id = ?");
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $posts = $stmt->get_result();
    while ($row = $posts->fetch_assoc()) {
        $raw = (string)($row['design_path'] ?? '');
        if ($raw === $src) {
            $stmt->close();
            return true;
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && in_array($src, array_map('strval', $decoded), true)) {
            $stmt->close();
            return true;
        }
    }
    $stmt->close();
    return false;
}

if (!isset($_GET['src']) || empty($_GET['src'])) watermark_blank_image();

$file_path = (string)$_GET['src'];
$token = trim((string)($_GET['token'] ?? ''));
$real_path = realpath($file_path);
$uploads_root = realpath(__DIR__ . '/uploads');

// 1. التحقق من أمان الملف (عشان محدش يطلب ملفات النظام)
if (!$real_path || !$uploads_root || strpos($real_path, $uploads_root . DIRECTORY_SEPARATOR) !== 0 || !file_exists($real_path)) {
    // لو الملف مش موجود او خارج مجلد uploads، نرجع صورة فاضية
    watermark_blank_image();
}

if (!watermark_media_allowed_for_client_review($conn, $token, $file_path)) {
    watermark_blank_image();
}

// 2. تحديد نوع الصورة
$ext = strtolower(pathinfo($real_path, PATHINFO_EXTENSION));
switch ($ext) {
    case 'jpg': case 'jpeg': $image = imagecreatefromjpeg($real_path); header("Content-Type: image/jpeg"); break;
    case 'png': $image = imagecreatefrompng($real_path); header("Content-Type: image/png"); break;
    case 'webp': $image = imagecreatefromwebp($real_path); header("Content-Type: image/webp"); break;
    default: exit;
}

// 3. إعدادات الختم
$text = "Arab Eagles - PREVIEW"; // النص الذي سيظهر
$font_size = 5; // حجم الخط الافتراضي (للموبايل)
$width = imagesx($image);
$height = imagesy($image);

// لون الختم (أبيض شفاف)
$white = imagecolorallocatealpha($image, 255, 255, 255, 80); // 80 = شفافية عالية
$grey = imagecolorallocatealpha($image, 128, 128, 128, 90);

// 4. تكرار الختم على كامل الصورة (Pattern)
// عشان لو قص حتة من الصورة، الختم يفضل موجود في الباقي
$step_x = 300; 
$step_y = 300;

for ($x = 0; $x < $width; $x += $step_x) {
    for ($y = 0; $y < $height; $y += $step_y) {
        // كتابة النص بشكل مائل ومكرر
        // imagestring لا يدعم الميلان والدوران، لو السيرفر يدعم imagettftext يكون أفضل، بس ده حل يعمل على كل السيرفرات
        imagestring($image, 5, $x, $y, $text, $grey);
        imagestring($image, 5, $x+2, $y+2, $text, $white); // ظل خفيف
    }
}

// إضافة خطين متقاطعين (Cross) لمزيد من الحماية
imageline($image, 0, 0, $width, $height, $white);
imageline($image, $width, 0, 0, $height, $white);

// 5. إخراج الصورة
if ($ext == 'png') {
    imagepng($image);
} elseif ($ext == 'webp') {
    imagewebp($image, null, 80); // جودة 80% للتصفح السريع
} else {
    imagejpeg($image, null, 80);
}

imagedestroy($image);
?>