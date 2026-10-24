<?php
// backup.php - (Full SQL Backup Only - Most Reliable)
// يقوم هذا الملف بعمل Dump كامل لقاعدة البيانات لضمان الاستعادة الكاملة

error_reporting(E_ALL);
ini_set('memory_limit', '1024M'); 
set_time_limit(600); 

require 'auth.php';
require 'config.php';

// التحقق من الصلاحية
if (($_SESSION['role'] ?? '') !== 'admin') {
    die("هذه العملية للمدير فقط.");
}

$action = $_GET['type'] ?? 'sql';

if ($action == 'sql') {
    // اسم الملف
    $filename = 'backup_' . date("Y-m-d_H-i") . '.sql';
    
    // الهيدر لتحميل الملف
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // جلب كل الجداول
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_row()) { $tables[] = $row[0]; }

    // بداية الملف
    echo "-- Arab Eagles Full SQL Backup\n";
    echo "-- Generated: " . date("Y-m-d H:i:s") . "\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        // 1. حذف الجدول إذا كان موجوداً (لضمان نظافة الاستعادة)
        echo "DROP TABLE IF EXISTS `$table`;\n";

        // 2. هيكل الجدول (Create Table)
        $row2 = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row();
        echo $row2[1] . ";\n\n";

        // 3. البيانات (Insert Into)
        $result = $conn->query("SELECT * FROM `$table`");
        while ($row = $result->fetch_assoc()) {
            $sql = "INSERT INTO `$table` VALUES(";
            $vals = [];
            foreach ($row as $val) {
                // التعامل مع القيم الفارغة والرموز الخاصة
                if (is_null($val)) $vals[] = "NULL";
                else $vals[] = "'" . $conn->real_escape_string($val) . "'";
            }
            $sql .= implode(',', $vals) . ");\n";
            echo $sql;
        }
        echo "\n"; // فاصل بين الجداول
    }
    
    echo "\nSET FOREIGN_KEY_CHECKS=1;";
    exit;
}
?>
