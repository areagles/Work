<?php
ob_start();
// users.php - إدارة المستخدمين (Admin)

require 'auth.php';
require 'config.php';
app_start_session();

$isAdminRole = ((string)($_SESSION['role'] ?? '') === 'admin');
$isSuperUser = function_exists('app_is_super_user') ? app_is_super_user() : false;
if (!$isAdminRole && !$isSuperUser) {
    require 'header.php';
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>الوصول إلى هذه الصفحة متاح للمدير أو الحساب الأعلى صلاحية فقط.</div></div>";
    require 'footer.php';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    app_require_csrf();
}

function users_get_columns(mysqli $conn): array
{
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }

    $cols = [];
    try {
        $res = $conn->query("SHOW COLUMNS FROM users");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $cols[(string)$row['Field']] = true;
            }
        }
    } catch (Throwable $e) {
        $cols = [];
    }
    return $cols;
}

function users_has_column(mysqli $conn, string $col): bool
{
    $cols = users_get_columns($conn);
    return isset($cols[$col]);
}

function users_table_exists(mysqli $conn, string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }
    try {
        $safeTable = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
        return (bool)($res && $res->num_rows > 0);
    } catch (Throwable $e) {
        return false;
    }
}

function users_employee_columns(mysqli $conn): array
{
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }
    $cols = [];
    if (!users_table_exists($conn, 'employees')) {
        return $cols;
    }
    try {
        $res = $conn->query("SHOW COLUMNS FROM employees");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $cols[(string)$row['Field']] = true;
            }
        }
    } catch (Throwable $e) {
        $cols = [];
    }
    return $cols;
}

function users_employees_has_column(mysqli $conn, string $column): bool
{
    $cols = users_employee_columns($conn);
    return isset($cols[$column]);
}

function users_employee_salary_map(mysqli $conn): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    if (!users_employees_has_column($conn, 'name') || !users_employees_has_column($conn, 'salary')) {
        return $map;
    }
    try {
        $res = $conn->query("SELECT name, salary FROM employees");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $name = trim((string)($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $map[$name] = (float)($row['salary'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        $map = [];
    }
    return $map;
}

function users_sync_employee_record(mysqli $conn, string $fullName, string $role, float $salary, string $oldName = ''): void
{
    if (!users_employees_has_column($conn, 'name')) {
        return;
    }

    $searchNames = [];
    $oldName = trim($oldName);
    $fullName = trim($fullName);
    if ($oldName !== '') {
        $searchNames[] = $oldName;
    }
    if ($fullName !== '' && !in_array($fullName, $searchNames, true)) {
        $searchNames[] = $fullName;
    }

    $targetRow = null;
    foreach ($searchNames as $candidate) {
        $stmtFind = $conn->prepare('SELECT * FROM employees WHERE name = ? LIMIT 1');
        $stmtFind->bind_param('s', $candidate);
        $stmtFind->execute();
        $res = $stmtFind->get_result();
        $targetRow = $res ? $res->fetch_assoc() : null;
        $stmtFind->close();
        if ($targetRow) {
            break;
        }
    }

    $salary = max(0, round($salary, 2));
    $hasSalary = users_employees_has_column($conn, 'salary');
    $hasJobTitle = users_employees_has_column($conn, 'job_title');
    $hasInitialBalance = users_employees_has_column($conn, 'initial_balance');

    if ($targetRow) {
        $set = ['name = ?'];
        $types = 's';
        $values = [$fullName];
        if ($hasSalary) {
            $set[] = 'salary = ?';
            $types .= 'd';
            $values[] = $salary;
        }
        if ($hasJobTitle) {
            $set[] = 'job_title = ?';
            $types .= 's';
            $values[] = $role;
        }
        $values[] = (int)($targetRow['id'] ?? 0);
        $types .= 'i';
        $stmtUpdate = $conn->prepare('UPDATE employees SET ' . implode(', ', $set) . ' WHERE id = ? LIMIT 1');
        users_bind_params($stmtUpdate, $types, $values);
        $stmtUpdate->execute();
        $stmtUpdate->close();
        return;
    }

    $insertCols = ['name'];
    $types = 's';
    $values = [$fullName];
    if ($hasSalary) {
        $insertCols[] = 'salary';
        $types .= 'd';
        $values[] = $salary;
    }
    if ($hasJobTitle) {
        $insertCols[] = 'job_title';
        $types .= 's';
        $values[] = $role;
    }
    if ($hasInitialBalance) {
        $insertCols[] = 'initial_balance';
        $types .= 'd';
        $values[] = 0.0;
    }
    $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
    $stmtInsert = $conn->prepare('INSERT INTO employees (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')');
    users_bind_params($stmtInsert, $types, $values);
    $stmtInsert->execute();
    $stmtInsert->close();
}

function users_bind_params(mysqli_stmt $stmt, string $types, array $values): void
{
    if ($types === '') {
        return;
    }
    $bind = [];
    $bind[] = &$types;
    foreach ($values as $k => $v) {
        $values[$k] = $v;
        $bind[] = &$values[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function users_avatar_url(array $row): string
{
    $profilePic = trim((string)($row['profile_pic'] ?? ''));
    if ($profilePic !== '') {
        return $profilePic;
    }

    $avatar = trim((string)($row['avatar'] ?? ''));
    if ($avatar !== '') {
        if (strpos($avatar, '/') !== false) {
            return $avatar;
        }
        return 'uploads/users/' . $avatar;
    }

    $name = trim((string)($row['full_name'] ?? $row['username'] ?? 'User'));
    return 'https://ui-avatars.com/api/?name=' . rawurlencode($name) . '&background=2f2f2f&color=fff';
}

function users_cleanup_avatar_files(array $row): void
{
    $paths = [];

    $profilePic = trim((string)($row['profile_pic'] ?? ''));
    if ($profilePic !== '' && basename($profilePic) !== 'default.png') {
        $paths[] = [$profilePic, 'uploads/avatars'];
    }

    $avatar = trim((string)($row['avatar'] ?? ''));
    if ($avatar !== '' && basename($avatar) !== 'default.png') {
        if (strpos($avatar, '/') !== false) {
            $root = (strpos($avatar, 'uploads/avatars/') === 0) ? 'uploads/avatars' : 'uploads/users';
            $paths[] = [$avatar, $root];
        } else {
            $paths[] = ['uploads/users/' . $avatar, 'uploads/users'];
        }
    }

    foreach ($paths as $pair) {
        app_safe_unlink($pair[0], $pair[1]);
    }
}

// ضمان وجود أعمدة الصلاحيات الدقيقة.
try { $conn->query("ALTER TABLE users ADD COLUMN allow_caps TEXT NULL"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE users ADD COLUMN deny_caps TEXT NULL"); } catch (Throwable $e) {}

$hasEmail = users_has_column($conn, 'email');
$hasPhone = users_has_column($conn, 'phone');
$hasProfilePic = users_has_column($conn, 'profile_pic');
$hasAvatar = users_has_column($conn, 'avatar');
$hasCreatedAt = users_has_column($conn, 'created_at');
$hasAllowCaps = users_has_column($conn, 'allow_caps');
$hasDenyCaps = users_has_column($conn, 'deny_caps');
$hasUserActive = users_has_column($conn, 'is_active');
$hasArchivedAt = users_has_column($conn, 'archived_at');
$hasArchivedBy = users_has_column($conn, 'archived_by');
$hasArchivedReason = users_has_column($conn, 'archived_reason');
$employeeSalaryMap = users_employee_salary_map($conn);
$capabilityCatalog = app_capability_catalog();
$capabilityKeys = array_keys($capabilityCatalog);
$isEnglish = app_current_lang($conn) === 'en';
$capabilityGroups = [];
foreach ($capabilityCatalog as $capKey => $capMeta) {
    $groupKey = (string)($capMeta['group'] ?? 'general');
    if (!isset($capabilityGroups[$groupKey])) {
        $capabilityGroups[$groupKey] = [];
    }
    $capabilityGroups[$groupKey][$capKey] = $capMeta;
}
$groupLabels = [
    'jobs' => $isEnglish ? 'Operations' : 'العمليات',
    'pricing' => $isEnglish ? 'Print Pricing' : 'تسعير الطباعة',
    'finance' => $isEnglish ? 'Finance' : 'المالية',
    'inventory' => $isEnglish ? 'Inventory' : 'المخزون',
    'general' => $isEnglish ? 'General' : 'عام',
];

$roleOptions = [
    'admin' => 'Admin',
    'manager' => 'Manager',
    'accountant' => 'Accountant',
    'sales' => 'Sales',
    'designer' => 'Designer',
    'production' => 'Production',
    'purchasing' => 'Purchasing',
    'monitor' => 'Monitor',
    'driver' => 'Driver',
    'employee' => 'Employee',
];

$msg = '';
$msgType = 'success';

$ok = (string)($_GET['ok'] ?? '');
if ($ok === 'created') {
    $msg = 'تم إضافة المستخدم بنجاح.';
} elseif ($ok === 'updated') {
    $msg = 'تم تحديث بيانات المستخدم بنجاح.';
} elseif ($ok === 'deleted') {
    $msg = 'تم حذف المستخدم بنجاح.';
} elseif ($ok === 'delete_archived') {
    $msg = 'المستخدم مرتبط بسجلات تشغيل، لذلك تم أرشفته وإيقاف دخوله بدلاً من حذفه نهائياً حفاظاً على البيانات.';
} elseif ($ok === 'archived') {
    $msg = 'تم أرشفة المستخدم بنجاح.';
} elseif ($ok === 'restored') {
    $msg = 'تمت استعادة المستخدم من الأرشيف.';
} elseif ($ok === 'deactivated') {
    $msg = 'تم إيقاف تفعيل المستخدم.';
} elseif ($ok === 'activated') {
    $msg = 'تم تفعيل المستخدم.';
} elseif ($ok === 'self_delete_blocked') {
    $msg = 'لا يمكن حذف حسابك الحالي.';
    $msgType = 'error';
} elseif ($ok === 'self_archive_blocked') {
    $msg = 'لا يمكن أرشفة أو تعطيل حسابك الحالي.';
    $msgType = 'error';
} elseif ($ok === 'invalid_role') {
    $msg = 'الدور الوظيفي غير صالح.';
    $msgType = 'error';
} elseif ($ok === 'duplicate_username') {
    $msg = 'اسم المستخدم مستخدم بالفعل.';
    $msgType = 'error';
} elseif ($ok === 'missing_required') {
    $msg = 'يرجى استكمال الحقول الأساسية.';
    $msgType = 'error';
} elseif ($ok === 'password_required') {
    $msg = 'كلمة المرور مطلوبة عند إضافة مستخدم جديد.';
    $msgType = 'error';
} elseif ($ok === 'save_failed') {
    $msg = 'لم يتم حفظ البيانات. راجع المدخلات ثم حاول مرة أخرى.';
    $msgType = 'error';
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'delete_user') {
            $deleteId = (int)($_POST['user_id'] ?? 0);
            $currentId = (int)($_SESSION['user_id'] ?? 0);

            if ($deleteId > 0) {
                if ($deleteId === $currentId) {
                    header('Location: users.php?ok=self_delete_blocked');
                    exit;
                }

                $stmtOld = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
                $stmtOld->bind_param('i', $deleteId);
                $stmtOld->execute();
                $oldRes = $stmtOld->get_result();
                $oldRow = $oldRes ? $oldRes->fetch_assoc() : null;
                $stmtOld->close();

                if ($oldRow) {
                    $deleteFailed = false;
                    try {
                        $stmtDel = $conn->prepare('DELETE FROM users WHERE id = ?');
                        $stmtDel->bind_param('i', $deleteId);
                        $stmtDel->execute();
                        $affected = (int)$stmtDel->affected_rows;
                        $stmtDel->close();

                        if ($affected > 0) {
                            users_cleanup_avatar_files($oldRow);
                            app_audit_log_add($conn, 'users.user_deleted', [
                                'entity_type' => 'user',
                                'entity_key' => (string)$deleteId,
                                'details' => [
                                    'username' => (string)($oldRow['username'] ?? ''),
                                    'full_name' => (string)($oldRow['full_name'] ?? ''),
                                ],
                            ]);
                            header('Location: users.php?ok=deleted');
                            exit;
                        }

                        $deleteFailed = true;
                    } catch (Throwable $deleteError) {
                        $deleteFailed = true;
                    }

                    if ($deleteFailed && $hasUserActive && $hasArchivedAt && $hasArchivedBy && $hasArchivedReason) {
                        $reason = 'تعذر الحذف النهائي لارتباط المستخدم بسجلات تشغيل؛ تم التحويل للأرشيف تلقائياً.';
                        $stmtArchive = $conn->prepare('UPDATE users SET is_active = 0, archived_at = NOW(), archived_by = ?, archived_reason = ? WHERE id = ? LIMIT 1');
                        $stmtArchive->bind_param('isi', $currentId, $reason, $deleteId);
                        $stmtArchive->execute();
                        $archivedAffected = (int)$stmtArchive->affected_rows;
                        $stmtArchive->close();

                        if ($archivedAffected >= 0) {
                            app_audit_log_add($conn, 'users.user_delete_archived', [
                                'entity_type' => 'user',
                                'entity_key' => (string)$deleteId,
                                'details' => [
                                    'username' => (string)($oldRow['username'] ?? ''),
                                    'full_name' => (string)($oldRow['full_name'] ?? ''),
                                    'reason' => $reason,
                                ],
                            ]);
                            header('Location: users.php?tab=archived&ok=delete_archived');
                            exit;
                        }
                    }
                }
            }

            header('Location: users.php?ok=save_failed');
            exit;
        }

        if (in_array($action, ['archive_user', 'restore_user', 'toggle_user_active'], true)) {
            $targetId = (int)($_POST['user_id'] ?? 0);
            $currentId = (int)($_SESSION['user_id'] ?? 0);
            if ($targetId > 0) {
                if ($targetId === $currentId && $action !== 'restore_user') {
                    header('Location: users.php?ok=self_archive_blocked');
                    exit;
                }

                $stmtTarget = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
                $stmtTarget->bind_param('i', $targetId);
                $stmtTarget->execute();
                $targetRes = $stmtTarget->get_result();
                $targetRow = $targetRes ? $targetRes->fetch_assoc() : null;
                $stmtTarget->close();

                if ($targetRow) {
                    if ($action === 'archive_user') {
                        $reason = trim((string)($_POST['archive_reason'] ?? ''));
                        $reason = mb_substr($reason, 0, 255);
                        $stmtArchive = $conn->prepare('UPDATE users SET is_active = 0, archived_at = NOW(), archived_by = ?, archived_reason = ? WHERE id = ? LIMIT 1');
                        $stmtArchive->bind_param('isi', $currentId, $reason, $targetId);
                        $stmtArchive->execute();
                        $stmtArchive->close();
                        app_audit_log_add($conn, 'users.user_archived', [
                            'entity_type' => 'user',
                            'entity_key' => (string)$targetId,
                            'details' => ['reason' => $reason],
                        ]);
                        header('Location: users.php?tab=archived&ok=archived');
                        exit;
                    }

                    if ($action === 'restore_user') {
                        $stmtRestore = $conn->prepare('UPDATE users SET is_active = 1, archived_at = NULL, archived_by = NULL, archived_reason = NULL WHERE id = ? LIMIT 1');
                        $stmtRestore->bind_param('i', $targetId);
                        $stmtRestore->execute();
                        $stmtRestore->close();
                        app_audit_log_add($conn, 'users.user_restored', [
                            'entity_type' => 'user',
                            'entity_key' => (string)$targetId,
                        ]);
                        header('Location: users.php?tab=active&ok=restored');
                        exit;
                    }

                    if ($action === 'toggle_user_active') {
                        $nextActive = ((int)($targetRow['is_active'] ?? 1) === 1) ? 0 : 1;
                        $stmtToggle = $conn->prepare('UPDATE users SET is_active = ?, archived_at = NULL, archived_by = NULL, archived_reason = NULL WHERE id = ? LIMIT 1');
                        $stmtToggle->bind_param('ii', $nextActive, $targetId);
                        $stmtToggle->execute();
                        $stmtToggle->close();
                        app_audit_log_add($conn, 'users.user_toggled', [
                            'entity_type' => 'user',
                            'entity_key' => (string)$targetId,
                            'details' => ['is_active' => $nextActive],
                        ]);
                        header('Location: users.php?tab=active&ok=' . ($nextActive === 1 ? 'activated' : 'deactivated'));
                        exit;
                    }
                }
            }

            header('Location: users.php?ok=save_failed');
            exit;
        }

        if ($action === 'save_user') {
            $mode = (($_POST['mode'] ?? 'create') === 'update') ? 'update' : 'create';
            $userId = (int)($_POST['user_id'] ?? 0);

            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $role = trim((string)($_POST['role'] ?? 'employee'));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $basicSalary = max(0, round((float)($_POST['basic_salary'] ?? 0), 2));
            $password = (string)($_POST['password'] ?? '');
            $allowCaps = app_normalize_capability_list($_POST['allow_caps'] ?? [], $capabilityKeys);
            $denyCaps = app_normalize_capability_list($_POST['deny_caps'] ?? [], $capabilityKeys);
            // إذا تم اختيار نفس الصلاحية في الفتح والغلق، نعطي أولوية للغلق.
            $allowCaps = array_values(array_diff($allowCaps, $denyCaps));
            $allowCapsJson = json_encode($allowCaps, JSON_UNESCAPED_UNICODE);
            $denyCapsJson = json_encode($denyCaps, JSON_UNESCAPED_UNICODE);

            if ($fullName === '' || $username === '') {
                header('Location: users.php?ok=missing_required');
                exit;
            }
            if (!isset($roleOptions[$role])) {
                header('Location: users.php?ok=invalid_role');
                exit;
            }
            if ($mode === 'create' && $password === '') {
                header('Location: users.php?ok=password_required');
                exit;
            }
            if ($hasEmail && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                header('Location: users.php?ok=save_failed');
                exit;
            }

            if ($mode === 'update') {
                $stmtDup = $conn->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
                $stmtDup->bind_param('si', $username, $userId);
            } else {
                $stmtDup = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                $stmtDup->bind_param('s', $username);
            }
            $stmtDup->execute();
            $dupRes = $stmtDup->get_result();
            $hasDup = (bool)($dupRes && $dupRes->num_rows > 0);
            $stmtDup->close();
            if ($hasDup) {
                header('Location: users.php?ok=duplicate_username');
                exit;
            }

            $newAvatarPath = null;
            if (isset($_FILES['avatar']) && (int)($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $upload = app_store_uploaded_file($_FILES['avatar'], [
                    'dir' => 'uploads/avatars',
                    'prefix' => 'user_' . ($mode === 'update' ? $userId : 'new') . '_',
                    'max_size' => 5 * 1024 * 1024,
                    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
                    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
                ]);
                if (!$upload['ok']) {
                    header('Location: users.php?ok=save_failed');
                    exit;
                }
                $newAvatarPath = (string)$upload['path'];
            }

            if ($mode === 'update') {
                $stmtCurrent = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
                $stmtCurrent->bind_param('i', $userId);
                $stmtCurrent->execute();
                $currentRes = $stmtCurrent->get_result();
                $currentRow = $currentRes ? $currentRes->fetch_assoc() : null;
                $stmtCurrent->close();
                if (!$currentRow) {
                    header('Location: users.php?ok=save_failed');
                    exit;
                }

                $set = ['full_name = ?', 'username = ?', 'role = ?'];
                $types = 'sss';
                $values = [$fullName, $username, $role];

                if ($hasPhone) {
                    $set[] = 'phone = ?';
                    $types .= 's';
                    $values[] = $phone;
                }
                if ($hasEmail) {
                    $set[] = 'email = ?';
                    $types .= 's';
                    $values[] = $email;
                }
                if ($hasAllowCaps) {
                    $set[] = 'allow_caps = ?';
                    $types .= 's';
                    $values[] = (string)$allowCapsJson;
                }
                if ($hasDenyCaps) {
                    $set[] = 'deny_caps = ?';
                    $types .= 's';
                    $values[] = (string)$denyCapsJson;
                }

                if ($newAvatarPath !== null) {
                    if ($hasProfilePic) {
                        $set[] = 'profile_pic = ?';
                        $types .= 's';
                        $values[] = $newAvatarPath;
                    }
                    if ($hasAvatar) {
                        $avatarColValue = $hasProfilePic ? basename($newAvatarPath) : $newAvatarPath;
                        $set[] = 'avatar = ?';
                        $types .= 's';
                        $values[] = $avatarColValue;
                    }
                }

                $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?';
                $types .= 'i';
                $values[] = $userId;

                $stmtUpdate = $conn->prepare($sql);
                users_bind_params($stmtUpdate, $types, $values);
                $stmtUpdate->execute();
                $stmtUpdate->close();

                if ($password !== '') {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $stmtPw = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                    $stmtPw->bind_param('si', $hashed, $userId);
                    $stmtPw->execute();
                    $stmtPw->close();
                }

                if ($newAvatarPath !== null) {
                    users_cleanup_avatar_files($currentRow);
                }

                users_sync_employee_record($conn, $fullName, $role, $basicSalary, (string)($currentRow['full_name'] ?? ''));

                $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
                if ($sessionUserId === $userId) {
                    $_SESSION['name'] = $fullName;
                    $_SESSION['username'] = $username;
                    $_SESSION['role'] = $role;
                    app_set_session_permission_caps($allowCaps, $denyCaps);
                }
                app_audit_log_add($conn, 'users.user_updated', [
                    'entity_type' => 'user',
                    'entity_key' => (string)$userId,
                    'details' => ['username' => $username, 'role' => $role],
                ]);

                header('Location: users.php?ok=updated');
                exit;
            }

            $insertCols = ['username', 'password', 'full_name', 'role'];
            $insertTypes = 'ssss';
            $insertVals = [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role];

            if ($hasPhone) {
                $insertCols[] = 'phone';
                $insertTypes .= 's';
                $insertVals[] = $phone;
            }
            if ($hasEmail) {
                $insertCols[] = 'email';
                $insertTypes .= 's';
                $insertVals[] = $email;
            }
            if ($hasAllowCaps) {
                $insertCols[] = 'allow_caps';
                $insertTypes .= 's';
                $insertVals[] = (string)$allowCapsJson;
            }
            if ($hasDenyCaps) {
                $insertCols[] = 'deny_caps';
                $insertTypes .= 's';
                $insertVals[] = (string)$denyCapsJson;
            }
            if ($hasProfilePic) {
                $insertCols[] = 'profile_pic';
                $insertTypes .= 's';
                $insertVals[] = ($newAvatarPath ?? '');
            }
            if ($hasAvatar) {
                $avatarInsertValue = 'default.png';
                if ($newAvatarPath !== null) {
                    $avatarInsertValue = $hasProfilePic ? basename($newAvatarPath) : $newAvatarPath;
                }
                $insertCols[] = 'avatar';
                $insertTypes .= 's';
                $insertVals[] = $avatarInsertValue;
            }

            $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
            $sqlInsert = 'INSERT INTO users (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')';
            $stmtInsert = $conn->prepare($sqlInsert);
            users_bind_params($stmtInsert, $insertTypes, $insertVals);
            $stmtInsert->execute();
            $newUserId = (int)$stmtInsert->insert_id;
            $stmtInsert->close();

            users_sync_employee_record($conn, $fullName, $role, $basicSalary);
            app_audit_log_add($conn, 'users.user_created', [
                'entity_type' => 'user',
                'entity_key' => (string)$newUserId,
                'details' => ['username' => $username, 'role' => $role],
            ]);

            header('Location: users.php?ok=created');
            exit;
        }
    }
} catch (Throwable $e) {
    $msg = 'حدث خطأ غير متوقع أثناء تنفيذ العملية.';
    $msgType = 'error';
}

$editMode = false;
$formData = [
    'id' => 0,
    'full_name' => '',
    'username' => '',
    'role' => 'employee',
    'phone' => '',
    'email' => '',
    'basic_salary' => 0.0,
    'allow_caps' => [],
    'deny_caps' => [],
];

$editId = (int)($_GET['edit'] ?? 0);
$currentTab = ((string)($_GET['tab'] ?? 'active') === 'archived') ? 'archived' : 'active';
if ($editId > 0) {
    try {
        $stmtEdit = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmtEdit->bind_param('i', $editId);
        $stmtEdit->execute();
        $editRes = $stmtEdit->get_result();
        $row = $editRes ? $editRes->fetch_assoc() : null;
        $stmtEdit->close();

        if ($row) {
            $editMode = true;
            $formData['id'] = (int)$row['id'];
            $formData['full_name'] = (string)($row['full_name'] ?? '');
            $formData['username'] = (string)($row['username'] ?? '');
            $formData['role'] = (string)($row['role'] ?? 'employee');
            $formData['phone'] = (string)($row['phone'] ?? '');
            $formData['email'] = (string)($row['email'] ?? '');
            $formData['basic_salary'] = (float)($employeeSalaryMap[(string)($row['full_name'] ?? '')] ?? 0);
            $formData['allow_caps'] = app_normalize_capability_list((string)($row['allow_caps'] ?? ''), $capabilityKeys);
            $formData['deny_caps'] = app_normalize_capability_list((string)($row['deny_caps'] ?? ''), $capabilityKeys);
        }
    } catch (Throwable $e) {
        // ignore invalid edit target
    }
}

$txtUsersTitle = app_t('users.title', 'إدارة المستخدمين');
$txtUsersSubtitle = app_t('users.subtitle', 'إدارة الحسابات والصلاحيات وحالة التفعيل والأرشفة من شاشة واحدة.');
$txtAddUser = app_t('users.form.add', 'إضافة مستخدم');
$txtEditUser = app_t('users.form.edit', 'تعديل مستخدم');
$txtFullName = app_t('users.form.full_name', 'الاسم الكامل');
$txtUsername = app_t('users.form.username', 'اسم المستخدم');
$txtPhone = app_t('users.form.phone', 'الهاتف');
$txtEmail = app_t('users.form.email', 'البريد الإلكتروني');
$txtRole = app_t('users.form.role', 'الدور الوظيفي');
$txtBasicSalary = app_t('users.form.basic_salary', 'الراتب الأساسي');
$txtPassword = app_t('users.form.password', 'كلمة المرور');
$txtPasswordOptional = app_t('users.form.password_optional', 'كلمة المرور (اختياري)');
$txtAvatar = app_t('users.form.avatar', 'الصورة الشخصية');
$txtSaveNew = app_t('users.form.save_new', 'إضافة المستخدم');
$txtSaveEdit = app_t('users.form.save_edit', 'حفظ التعديلات');
$txtCancel = app_t('users.form.cancel', 'إلغاء');
$txtOpenPerm = app_t('users.permissions.open', 'صلاحيات فتح إضافية');
$txtClosePerm = app_t('users.permissions.close', 'صلاحيات غلق');
$txtPermHelp = app_t('users.permissions.help', 'الفتح يعطي المستخدم هذه الصلاحية حتى لو دوره لا يملكها، والغلق يمنعها حتى لو الدور يسمح بها.');
$txtPermSearch = app_t('users.permissions.search', 'بحث في الصلاحيات');
$txtPermSearchPlaceholder = app_t('users.permissions.search_placeholder', 'اكتب اسم الصلاحية أو الكود...');
$txtPermDefault = app_t('users.permissions.default', 'افتراضي');
$txtPermOpenAll = app_t('users.permissions.open_all', 'فتح الكل');
$txtPermCloseAll = app_t('users.permissions.close_all', 'غلق الكل');
$txtPermResetAll = app_t('users.permissions.reset_all', 'إعادة الكل');
$txtPermAppliedCount = app_t('users.permissions.applied_count', 'عدد الصلاحيات المخصصة');
$txtPermStateOpen = app_t('users.permissions.state_open', 'فتح');
$txtPermStateClose = app_t('users.permissions.state_close', 'غلق');

$usersStats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'archived' => 0,
];
try {
    $statsRes = $conn->query("
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN archived_at IS NULL AND is_active = 1 THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN archived_at IS NULL AND is_active = 0 THEN 1 ELSE 0 END) AS inactive_count,
            SUM(CASE WHEN archived_at IS NOT NULL THEN 1 ELSE 0 END) AS archived_count
        FROM users
    ");
    if ($statsRes) {
        $statsRow = $statsRes->fetch_assoc() ?: [];
        $usersStats['total'] = (int)($statsRow['total_count'] ?? 0);
        $usersStats['active'] = (int)($statsRow['active_count'] ?? 0);
        $usersStats['inactive'] = (int)($statsRow['inactive_count'] ?? 0);
        $usersStats['archived'] = (int)($statsRow['archived_count'] ?? 0);
    }
} catch (Throwable $e) {
}

require 'header.php';
?>

<style>
    .users-wrap { max-width: 1460px; margin: 0 auto; padding: 24px; }
    .users-shell { display: grid; gap: 18px; }
    .users-hero {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(280px, 0.85fr);
        gap: 18px;
        align-items: stretch;
    }
    .users-card {
        background: linear-gradient(180deg, rgba(25,25,25,0.98), rgba(16,16,16,0.98));
        border: 1px solid rgba(212, 175, 55, 0.16);
        border-radius: 18px;
        padding: 20px;
        box-shadow: 0 18px 36px rgba(0,0,0,0.28);
    }
    .users-overview {
        position: relative;
        overflow: hidden;
        min-height: 100%;
    }
    .users-overview::after {
        content: "";
        position: absolute;
        inset-inline-end: -80px;
        inset-block-start: -80px;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(212,175,55,0.16), transparent 70%);
        pointer-events: none;
    }
    .users-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 6px 12px;
        border-radius: 999px;
        border: 1px solid rgba(212,175,55,0.24);
        background: rgba(212,175,55,0.08);
        color: #f0d684;
        font-size: .76rem;
        font-weight: 700;
        margin-bottom: 14px;
    }
    .users-page-title { margin: 0; color: #f7f1dc; font-size: 1.9rem; line-height: 1.25; }
    .users-page-subtitle { margin: 10px 0 0; color: #aaabae; font-size: .96rem; max-width: 720px; line-height: 1.75; }
    .users-summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    .users-stat {
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 16px;
        padding: 16px;
        background: rgba(255,255,255,0.03);
        min-height: 108px;
    }
    .users-stat-label { color: #a4a7ad; font-size: .82rem; margin-bottom: 10px; }
    .users-stat-value { color: #f8f6ef; font-size: 1.9rem; font-weight: 800; line-height: 1; }
    .users-stat-note { color: #8b8f95; font-size: .75rem; margin-top: 10px; }
    .users-stat.active .users-stat-value { color: #98e1b2; }
    .users-stat.inactive .users-stat-value { color: #f0cd84; }
    .users-stat.archived .users-stat-value { color: #f1a4a0; }
    .users-alert { border-radius: 12px; padding: 13px 15px; margin-bottom: 14px; border: 1px solid transparent; }
    .users-alert.success { background: rgba(46, 204, 113, 0.1); border-color: rgba(46, 204, 113, 0.5); color: #8be8b0; }
    .users-alert.error { background: rgba(231, 76, 60, 0.1); border-color: rgba(231, 76, 60, 0.45); color: #ff9f96; }

    .users-layout { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(360px, 420px); gap: 18px; align-items: start; }
    .users-panel-title { margin: 0; color: #f0d684; font-size: 1.06rem; }
    .users-panel-subtitle { color: #9ca0a8; font-size: .82rem; margin: 8px 0 0; line-height: 1.65; }
    .users-card-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        margin-bottom: 18px;
    }
    .users-tabs { display:flex; gap:10px; margin:0; flex-wrap:wrap; }
    .users-tab {
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:10px 15px;
        border-radius:999px;
        text-decoration:none;
        border:1px solid #383838;
        background:#171717;
        color:#ddd;
        font-weight:700;
        font-size:.84rem;
    }
    .users-tab.active { background:rgba(212,175,55,.12); border-color:rgba(212,175,55,.42); color:#f4d67a; }
    .users-tab-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 26px;
        height: 26px;
        border-radius: 999px;
        background: rgba(255,255,255,0.08);
        color: inherit;
        font-size: .74rem;
        padding: 0 8px;
    }

    .users-form-card { position: sticky; top: 16px; }
    .users-form-mode {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 6px 12px;
        border-radius: 999px;
        background: rgba(212,175,55,0.08);
        border: 1px solid rgba(212,175,55,0.24);
        color: #f0d684;
        font-size: .76rem;
        font-weight: 700;
        margin-bottom: 12px;
    }
    .users-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .users-form-section {
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 14px;
        background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.015));
        padding: 16px;
        backdrop-filter: blur(12px);
    }
    .users-form-section.full-span { grid-column: 1 / -1; }
    .users-section-title { margin: 0 0 12px; color: #e5e1d4; font-size: .9rem; }
    .users-group { margin-bottom: 11px; }
    .users-group:last-child { margin-bottom: 0; }
    .users-group label { display:block; margin-bottom:6px; color:#bcbcbc; font-size:.84rem; }
    .users-input, .users-select {
        width: 100%;
        background: rgba(6, 6, 6, 0.78);
        border: 1px solid rgba(255,255,255,0.1);
        color: #fff;
        border-radius: 14px;
        padding: 13px 14px;
        font-family: 'Cairo', sans-serif;
        transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
    }
    .users-input:focus, .users-select:focus {
        outline:none;
        border-color: rgba(212,175,55,0.75);
        box-shadow: 0 0 0 4px rgba(212,175,55,0.08);
        background: rgba(12, 12, 12, 0.9);
    }
    .users-help { display:block; color:#8f8f8f; font-size:.78rem; margin-top:5px; line-height:1.45; }
    .users-perm-box {
        background:#111;
        border:1px solid #2f2f2f;
        border-radius:14px;
        padding:14px;
        margin-bottom:0;
    }
    .users-perm-head { display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
    .users-perm-title { color:#e8e1c8; font-size:.9rem; margin:0; font-weight:700; }
    .users-perm-count {
        color:#d8c37a;
        font-size:.76rem;
        background:rgba(212, 175, 55, 0.12);
        border:1px solid rgba(212, 175, 55, 0.34);
        border-radius:999px;
        padding:3px 9px;
    }
    .users-perm-tools { display:grid; grid-template-columns:1fr; gap:8px; margin-bottom:10px; }
    .users-perm-bulk { display:flex; flex-wrap:wrap; gap:6px; }
    .users-perm-btn {
        border:1px solid #3a3a3a;
        background:#1e1e1e;
        color:#ddd;
        border-radius:9px;
        padding:7px 10px;
        font-size:.76rem;
        cursor:pointer;
        font-family:'Cairo',sans-serif;
    }
    .users-perm-btn.allow { border-color:rgba(46,204,113,.5); color:#8be8b0; background:rgba(46,204,113,.1); }
    .users-perm-btn.deny { border-color:rgba(231,76,60,.5); color:#ff9f96; background:rgba(231,76,60,.1); }
    .users-perm-btn.reset { border-color:#4a4a4a; color:#cacaca; }
    .users-perm-groups { display:grid; grid-template-columns:1fr; gap:10px; max-height:430px; overflow:auto; padding-right:2px; }
    .users-perm-group {
        border:1px solid #2e2e2e;
        border-radius:10px;
        background:rgba(255,255,255,0.015);
        padding:10px;
    }
    .users-perm-group-hd {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:6px;
        margin-bottom:8px;
        padding-bottom:7px;
        border-bottom:1px dashed rgba(255,255,255,0.08);
    }
    .users-perm-group-name { color:#9ea8b3; font-size:.83rem; font-weight:700; }
    .users-perm-group-bulk { display:flex; gap:5px; flex-wrap:wrap; }
    .users-perm-group-bulk .users-perm-btn { padding:5px 8px; font-size:.72rem; }
    .users-perm-grid { display:grid; grid-template-columns:1fr; gap:6px; }
    .users-perm-row {
        display:grid;
        grid-template-columns:minmax(0,1fr) auto;
        align-items:center;
        gap:8px;
        border:1px solid #2c2c2c;
        border-radius:10px;
        padding:8px;
        background:#151515;
    }
    .users-perm-row-main { min-width:0; }
    .users-perm-label {
        color:#dfdfdf;
        font-size:.82rem;
        font-weight:600;
        line-height:1.35;
        overflow-wrap:anywhere;
    }
    .users-perm-key {
        color:#8d8d8d;
        font-size:.72rem;
        margin-top:2px;
        direction:ltr;
        text-align:left;
    }
    .users-perm-state { display:flex; align-items:center; gap:6px; }
    .users-cap-toggle {
        display:inline-flex;
        align-items:center;
        gap:5px;
        border:1px solid #3a3a3a;
        border-radius:999px;
        padding:3px 8px;
        cursor:pointer;
        font-size:.72rem;
        color:#d9d9d9;
        user-select:none;
        white-space:nowrap;
        background:#1a1a1a;
    }
    .users-cap-toggle input { margin:0; accent-color: var(--gold-primary); }
    .users-cap-toggle.allow { border-color:rgba(46,204,113,.5); }
    .users-cap-toggle.deny { border-color:rgba(231,76,60,.45); }
    .users-cap-toggle.allow.active { background:rgba(46,204,113,.15); color:#8be8b0; }
    .users-cap-toggle.deny.active { background:rgba(231,76,60,.14); color:#ff9f96; }
    .users-cap-reset {
        border:1px solid #444;
        background:#212121;
        color:#cfcfcf;
        border-radius:999px;
        padding:4px 8px;
        font-size:.7rem;
        cursor:pointer;
        font-family:'Cairo',sans-serif;
    }
    .users-perm-row.hidden-by-search { display:none; }

    .users-actions { display:flex; gap:8px; margin-top:16px; }
    .users-btn {
        border: 1px solid #393939;
        background: linear-gradient(140deg, var(--gold-primary), #9c7726);
        color:#000; border-radius:12px; padding:10px 14px; font-weight:700;
        font-family:'Cairo',sans-serif; cursor:pointer; text-decoration:none; text-align:center;
        display:inline-flex; align-items:center; justify-content:center; gap:8px;
    }
    .users-btn.secondary { background: #232323; color:#e2e2e2; border-color:#3b3b3b; }
    .users-btn.danger { background: rgba(231, 76, 60, 0.15); color:#ff9a90; border-color: rgba(231,76,60,.5); }

    .users-list-tools {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }
    .users-list-meta { color: #9ca0a8; font-size: .82rem; }
    .users-list-mode {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border-radius: 999px;
        border: 1px solid rgba(212,175,55,0.22);
        background: rgba(212,175,55,0.08);
        color: #f0d684;
        padding: 7px 12px;
        font-size: .76rem;
        font-weight: 700;
    }
    .users-cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 14px;
    }
    .user-glass-card {
        position: relative;
        overflow: hidden;
        min-height: 100%;
        border-radius: 22px;
        border: 1px solid rgba(255,255,255,0.08);
        background:
            linear-gradient(180deg, rgba(255,255,255,0.08), rgba(255,255,255,0.02)),
            radial-gradient(circle at top left, rgba(212,175,55,0.12), transparent 34%),
            rgba(17,17,17,0.76);
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.05), 0 18px 34px rgba(0,0,0,0.2);
        backdrop-filter: blur(14px);
        padding: 18px;
    }
    .user-glass-card::after {
        content: "";
        position: absolute;
        inset-inline-end: -42px;
        inset-block-start: -42px;
        width: 120px;
        height: 120px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(212,175,55,0.11), transparent 70%);
        pointer-events: none;
    }
    .user-card-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 16px;
    }
    .u-main { display:flex; align-items:center; gap:12px; min-width: 0; }
    .u-avatar {
        width:54px;
        height:54px;
        border-radius:50%;
        object-fit:cover;
        border:1px solid rgba(255,255,255,0.18);
        box-shadow: 0 8px 18px rgba(0,0,0,0.2);
        flex-shrink: 0;
    }
    .u-meta { display:flex; flex-direction:column; min-width: 0; }
    .u-meta b { font-size:1rem; line-height: 1.45; color: #f5f3ea; }
    .u-meta span { font-size:.76rem; color:#8c8c8c; line-height: 1.5; }
    .u-login-name { color: #b6bbc1; font-size: .83rem; direction: ltr; text-align: left; }
    .u-contact { color:#cfcfcf; font-size:.82rem; }
    .u-contact.muted { color:#777; }
    .u-id-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 34px;
        height: 34px;
        padding: 0 12px;
        border-radius: 999px;
        background: rgba(255,255,255,0.08);
        border: 1px solid rgba(255,255,255,0.08);
        color: #f1e2a6;
        font-size: .8rem;
        font-weight: 700;
    }
    .user-card-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 14px;
    }
    .user-info-box {
        border-radius: 14px;
        background: rgba(255,255,255,0.035);
        border: 1px solid rgba(255,255,255,0.05);
        padding: 12px;
        min-height: 76px;
    }
    .user-info-label { color: #9ca0a8; font-size: .73rem; margin-bottom: 6px; }
    .user-info-value { color: #f0f0f0; font-size: .84rem; line-height: 1.65; overflow-wrap: anywhere; }
    .user-card-tags {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }
    .user-card-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-top: 14px;
        border-top: 1px solid rgba(255,255,255,0.08);
    }
    .user-card-created { color: #8f949b; font-size: .74rem; line-height: 1.6; }
    .user-empty-state {
        border-radius: 18px;
        border: 1px dashed rgba(255,255,255,0.1);
        background: rgba(255,255,255,0.025);
        color: #9ca0a8;
        text-align: center;
        padding: 32px 18px;
    }

    .u-role { display:inline-block; padding:4px 10px; border-radius:999px; font-size:.74rem; border:1px solid #3f3f3f; background:#1d1d1d; }
    .u-role.admin { color:#f4d67a; border-color: rgba(212,175,55,.6); background: rgba(212,175,55,.1); }
    .u-status { display:inline-block; padding:4px 10px; border-radius:999px; font-size:.74rem; border:1px solid #3f3f3f; background:#1d1d1d; }
    .u-status.active { color:#8be8b0; border-color:rgba(46,204,113,.45); background:rgba(46,204,113,.1); }
    .u-status.inactive { color:#ffcc88; border-color:rgba(241,196,15,.4); background:rgba(241,196,15,.12); }
    .u-status.archived { color:#ff9f96; border-color:rgba(231,76,60,.45); background:rgba(231,76,60,.12); }

    .u-row-actions {
        display:flex;
        gap:6px;
        flex-wrap:wrap;
        justify-content:flex-end;
    }
    .u-action-btn {
        min-height: 34px;
        border-radius: 10px;
        border:1px solid #343434;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        text-decoration:none;
        cursor:pointer;
        background:#1b1b1b;
        color:#d0d0d0;
        font-family:'Cairo',sans-serif;
        font-size:.77rem;
        padding:7px 10px;
        white-space: nowrap;
    }
    .u-action-btn.primary { color:#f4d67a; border-color: rgba(212,175,55,.35); background: rgba(212,175,55,.08); }
    .u-action-btn.warn { color:#f0cd84; border-color: rgba(241,196,15,.3); background: rgba(241,196,15,.09); }
    .u-action-btn.success { color:#8be8b0; border-color: rgba(46,204,113,.35); background: rgba(46,204,113,.08); }
    .u-action-btn.danger { color:#ff9a90; border-color: rgba(231,76,60,.4); background: rgba(231,76,60,.12); }
    .u-perm-badge {
        display:inline-block;
        border-radius:999px;
        font-size:.72rem;
        padding:2px 8px;
        margin-inline-end:4px;
        margin-bottom:3px;
    }
    .u-perm-badge.allow { color:#8be8b0; border:1px solid rgba(46,204,113,.45); background:rgba(46,204,113,.1); }
    .u-perm-badge.deny { color:#ff9f96; border:1px solid rgba(231,76,60,.45); background:rgba(231,76,60,.1); }

    @media (max-width: 1040px) {
        .users-hero,
        .users-layout { grid-template-columns: 1fr; }
        .users-wrap { padding: 16px 10px; }
        .users-card { padding: 14px; }
        .users-form-card { position: static; }
    }
    @media (max-width: 900px) {
        .users-summary { grid-template-columns: 1fr 1fr; }
        .users-card-head,
        .users-list-tools { flex-direction: column; align-items: stretch; }
        .users-actions { flex-direction:column; }
        .users-actions .users-btn { width:100%; }
        .users-form-grid,
        .user-card-grid { grid-template-columns: 1fr; }
        .users-perm-row { grid-template-columns:1fr; }
        .users-perm-state { flex-wrap:wrap; }
        .users-perm-groups { max-height:360px; }
        .user-card-footer { flex-direction: column; align-items: stretch; }
        .u-row-actions { justify-content:flex-start; }
    }
    @media (max-width: 640px) {
        .users-summary { grid-template-columns: 1fr; }
        .users-page-title { font-size: 1.5rem; }
    }
</style>

<div class="users-wrap">
    <div class="users-shell">
        <section class="users-hero">
            <div class="users-card users-overview">
                <div class="users-eyebrow">إدارة الحسابات</div>
                <h2 class="users-page-title"><?php echo app_h($txtUsersTitle); ?></h2>
                <p class="users-page-subtitle"><?php echo app_h($txtUsersSubtitle); ?></p>
            </div>
            <div class="users-summary">
                <div class="users-card users-stat">
                    <div class="users-stat-label">إجمالي المستخدمين</div>
                    <div class="users-stat-value"><?php echo (int)$usersStats['total']; ?></div>
                    <div class="users-stat-note">جميع الحسابات المسجلة في النظام</div>
                </div>
                <div class="users-card users-stat active">
                    <div class="users-stat-label">الحسابات النشطة</div>
                    <div class="users-stat-value"><?php echo (int)$usersStats['active']; ?></div>
                    <div class="users-stat-note">يمكنها تسجيل الدخول والعمل حاليًا</div>
                </div>
                <div class="users-card users-stat inactive">
                    <div class="users-stat-label">الحسابات الموقوفة</div>
                    <div class="users-stat-value"><?php echo (int)$usersStats['inactive']; ?></div>
                    <div class="users-stat-note">موجودة بالنظام لكن غير مفعلة</div>
                </div>
                <div class="users-card users-stat archived">
                    <div class="users-stat-label">الأرشيف</div>
                    <div class="users-stat-value"><?php echo (int)$usersStats['archived']; ?></div>
                    <div class="users-stat-note">حسابات محفوظة خارج التشغيل اليومي</div>
                </div>
            </div>
        </section>

        <?php if ($msg !== ''): ?>
            <div class="users-alert <?php echo $msgType === 'error' ? 'error' : 'success'; ?>"><?php echo app_h($msg); ?></div>
        <?php endif; ?>

        <div class="users-layout">
            <div class="users-card users-form-card" id="userForm">
                <div class="users-card-head">
                    <div>
                        <div class="users-form-mode"><?php echo $editMode ? 'وضع التعديل' : 'إضافة حساب جديد'; ?></div>
                        <h3 class="users-panel-title"><?php echo app_h($editMode ? $txtEditUser : $txtAddUser); ?></h3>
                        <p class="users-panel-subtitle">البيانات الأساسية وصلاحيات الاستخدام تحفظ من نفس النموذج.</p>
                    </div>
                </div>
            <form method="post" enctype="multipart/form-data">
                <?php echo app_csrf_input(); ?>
                <input type="hidden" name="action" value="save_user">
                <input type="hidden" name="mode" value="<?php echo $editMode ? 'update' : 'create'; ?>">
                <input type="hidden" name="user_id" value="<?php echo (int)$formData['id']; ?>">

                <div class="users-form-grid">
                    <section class="users-form-section">
                        <h4 class="users-section-title">البيانات الأساسية</h4>
                        <div class="users-group">
                            <label><?php echo app_h($txtFullName); ?></label>
                            <input class="users-input" type="text" name="full_name" required maxlength="120" value="<?php echo app_h($formData['full_name']); ?>">
                        </div>

                        <div class="users-group">
                            <label><?php echo app_h($txtUsername); ?></label>
                            <input class="users-input" type="text" name="username" required maxlength="80" value="<?php echo app_h($formData['username']); ?>">
                        </div>

                        <?php if ($hasPhone): ?>
                        <div class="users-group">
                            <label><?php echo app_h($txtPhone); ?></label>
                            <input class="users-input" type="text" name="phone" maxlength="30" value="<?php echo app_h($formData['phone']); ?>">
                        </div>
                        <?php endif; ?>

                        <?php if ($hasEmail): ?>
                        <div class="users-group">
                            <label><?php echo app_h($txtEmail); ?></label>
                            <input class="users-input" type="email" name="email" maxlength="120" value="<?php echo app_h($formData['email']); ?>">
                        </div>
                        <?php endif; ?>
                    </section>

                    <section class="users-form-section">
                        <h4 class="users-section-title">الهوية التشغيلية</h4>
                        <div class="users-group">
                            <label><?php echo app_h($txtRole); ?></label>
                            <select class="users-select" name="role" required>
                                <?php foreach ($roleOptions as $k => $lbl): ?>
                                    <option value="<?php echo app_h($k); ?>" <?php echo $formData['role'] === $k ? 'selected' : ''; ?>><?php echo app_h($lbl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="users-group">
                            <label><?php echo app_h($txtBasicSalary); ?></label>
                            <input class="users-input" type="number" name="basic_salary" min="0" step="0.01" value="<?php echo app_h(number_format((float)($formData['basic_salary'] ?? 0), 2, '.', '')); ?>">
                            <span class="users-help">يُستخدم كقيمة افتراضية عند إعداد مسير الرواتب ويمكن تعديله لكل مسير بشكل مستقل.</span>
                        </div>

                        <div class="users-group">
                            <label><?php echo app_h($editMode ? $txtPasswordOptional : $txtPassword); ?></label>
                            <input class="users-input" type="password" name="password" <?php echo $editMode ? '' : 'required'; ?> maxlength="200" placeholder="<?php echo $editMode ? 'اترك الحقل فارغًا إذا لم تكن هناك حاجة لتغيير كلمة المرور' : 'أدخل كلمة المرور'; ?>">
                        </div>

                        <div class="users-group">
                            <label><?php echo app_h($txtAvatar); ?></label>
                            <input class="users-input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp">
                        </div>
                    </section>
                </div>

                <?php if ($hasAllowCaps || $hasDenyCaps): ?>
                    <div class="users-perm-box users-form-section full-span" id="usersPermissionsBox" style="margin-top:14px;">
                        <div class="users-perm-head">
                            <h4 class="users-perm-title"><?php echo app_h($isEnglish ? 'Permission Overrides' : 'تخصيص الصلاحيات'); ?></h4>
                            <span class="users-perm-count" id="usersPermCount"><?php echo app_h($txtPermAppliedCount); ?>: 0</span>
                        </div>
                        <div class="users-perm-tools">
                            <input id="usersPermSearch" class="users-input" type="search" autocomplete="off" placeholder="<?php echo app_h($txtPermSearchPlaceholder); ?>" aria-label="<?php echo app_h($txtPermSearch); ?>">
                            <div class="users-perm-bulk">
                                <button type="button" class="users-perm-btn allow" data-perm-bulk="allow"><?php echo app_h($txtPermOpenAll); ?></button>
                                <button type="button" class="users-perm-btn deny" data-perm-bulk="deny"><?php echo app_h($txtPermCloseAll); ?></button>
                                <button type="button" class="users-perm-btn reset" data-perm-bulk="reset"><?php echo app_h($txtPermResetAll); ?></button>
                            </div>
                        </div>
                        <div class="users-perm-groups">
                            <?php foreach ($capabilityGroups as $groupKey => $groupCaps): ?>
                                <?php $groupLabel = (string)($groupLabels[$groupKey] ?? ($isEnglish ? ucfirst($groupKey) : $groupKey)); ?>
                                <section class="users-perm-group" data-group="<?php echo app_h($groupKey); ?>">
                                    <div class="users-perm-group-hd">
                                        <div class="users-perm-group-name"><?php echo app_h($groupLabel); ?></div>
                                        <div class="users-perm-group-bulk">
                                            <button type="button" class="users-perm-btn allow" data-perm-bulk="allow" data-perm-group="<?php echo app_h($groupKey); ?>"><?php echo app_h($txtPermOpenAll); ?></button>
                                            <button type="button" class="users-perm-btn deny" data-perm-bulk="deny" data-perm-group="<?php echo app_h($groupKey); ?>"><?php echo app_h($txtPermCloseAll); ?></button>
                                            <button type="button" class="users-perm-btn reset" data-perm-bulk="reset" data-perm-group="<?php echo app_h($groupKey); ?>"><?php echo app_h($txtPermResetAll); ?></button>
                                        </div>
                                    </div>
                                    <div class="users-perm-grid">
                                        <?php foreach ($groupCaps as $capKey => $capMeta): ?>
                                            <?php $capLabel = (string)($capMeta['label'] ?? $capKey); ?>
                                            <?php
                                                $allowChecked = in_array($capKey, $formData['allow_caps'], true);
                                                $denyChecked = in_array($capKey, $formData['deny_caps'], true);
                                            ?>
                                            <div class="users-perm-row"
                                                 data-cap-key="<?php echo app_h(strtolower($capKey)); ?>"
                                                 data-cap-label="<?php echo app_h(strtolower($capLabel)); ?>"
                                                 data-cap-group="<?php echo app_h($groupKey); ?>">
                                                <div class="users-perm-row-main">
                                                    <div class="users-perm-label"><?php echo app_h($capLabel); ?></div>
                                                    <div class="users-perm-key"><?php echo app_h($capKey); ?></div>
                                                </div>
                                                <div class="users-perm-state">
                                                    <label class="users-cap-toggle allow <?php echo $allowChecked ? 'active' : ''; ?>">
                                                        <input type="checkbox" class="users-cap-allow" name="allow_caps[]" value="<?php echo app_h($capKey); ?>" <?php echo $allowChecked ? 'checked' : ''; ?>>
                                                        <span><?php echo app_h($txtPermStateOpen); ?></span>
                                                    </label>
                                                    <label class="users-cap-toggle deny <?php echo $denyChecked ? 'active' : ''; ?>">
                                                        <input type="checkbox" class="users-cap-deny" name="deny_caps[]" value="<?php echo app_h($capKey); ?>" <?php echo $denyChecked ? 'checked' : ''; ?>>
                                                        <span><?php echo app_h($txtPermStateClose); ?></span>
                                                    </label>
                                                    <button class="users-cap-reset" type="button"><?php echo app_h($txtPermDefault); ?></button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        </div>
                        <span class="users-help"><?php echo app_h($txtPermHelp); ?></span>
                    </div>
                <?php endif; ?>

                <div class="users-actions">
                    <button class="users-btn" type="submit"><?php echo app_h($editMode ? $txtSaveEdit : $txtSaveNew); ?></button>
                    <?php if ($editMode): ?>
                        <a class="users-btn secondary" href="users.php"><?php echo app_h($txtCancel); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

            <div class="users-card">
                <div class="users-card-head">
                    <div>
                        <h3 class="users-panel-title"><?php echo app_h($isEnglish ? 'Users List' : 'قائمة المستخدمين'); ?></h3>
                        <p class="users-panel-subtitle">عرض موحد للحسابات مع حالة التفعيل والأرشفة وتخصيص الصلاحيات.</p>
                    </div>
                    <div class="users-tabs">
                        <a class="users-tab <?php echo $currentTab === 'active' ? 'active' : ''; ?>" href="users.php?tab=active">
                            المستخدمون
                            <span class="users-tab-count"><?php echo (int)($usersStats['active'] + $usersStats['inactive']); ?></span>
                        </a>
                        <a class="users-tab <?php echo $currentTab === 'archived' ? 'active' : ''; ?>" href="users.php?tab=archived">
                            الأرشيف
                            <span class="users-tab-count"><?php echo (int)$usersStats['archived']; ?></span>
                        </a>
                    </div>
                </div>
                <div class="users-list-tools">
                    <div class="users-list-meta">
                        <?php echo $currentTab === 'archived' ? 'يعرض هذا القسم الحسابات المؤرشفة مع إمكان الاستعادة.' : 'يعرض هذا القسم الحسابات النشطة والموقوفة مع إمكان التعديل والتفعيل.'; ?>
                    </div>
                    <div class="users-list-mode">
                        <?php echo $currentTab === 'archived' ? 'عرض الأرشيف' : 'عرض الحسابات التشغيلية'; ?>
                    </div>
                </div>
            <div class="users-cards-grid">
                <?php
                $userWhere = $currentTab === 'archived' ? "WHERE archived_at IS NOT NULL" : "WHERE archived_at IS NULL";
                $listRes = $conn->query("SELECT * FROM users {$userWhere} ORDER BY (role='admin') DESC, id ASC");
                if ($listRes && $listRes->num_rows > 0):
                    while ($row = $listRes->fetch_assoc()):
                        $avatarUrl = users_avatar_url($row);
                        $roleVal = (string)($row['role'] ?? 'employee');
                        $rowBasicSalary = (float)($employeeSalaryMap[(string)($row['full_name'] ?? '')] ?? 0);
                        $isSelf = ((int)$row['id'] === (int)($_SESSION['user_id'] ?? 0));
                        $isArchived = app_user_is_archived($row);
                        $isActiveUser = app_user_is_active_record($row);
                        $rowAllow = app_normalize_capability_list((string)($row['allow_caps'] ?? ''), $capabilityKeys);
                        $rowDeny = app_normalize_capability_list((string)($row['deny_caps'] ?? ''), $capabilityKeys);
                        $allowTitle = implode(' • ', array_map(static function (string $cap) use ($capabilityCatalog): string {
                            return (string)($capabilityCatalog[$cap]['label'] ?? $cap);
                        }, $rowAllow));
                        $denyTitle = implode(' • ', array_map(static function (string $cap) use ($capabilityCatalog): string {
                            return (string)($capabilityCatalog[$cap]['label'] ?? $cap);
                        }, $rowDeny));
                ?>
                <article class="user-glass-card">
                    <div class="user-card-head">
                        <div class="u-main">
                            <img class="u-avatar" src="<?php echo app_h($avatarUrl); ?>" alt="avatar">
                            <div class="u-meta">
                                <b><?php echo app_h((string)($row['full_name'] ?? '')); ?></b>
                                <span><?php echo $isSelf ? 'الحساب الحالي' : ($isArchived ? 'حساب مؤرشف' : 'حساب مستخدم'); ?></span>
                                <div class="u-login-name"><?php echo app_h((string)($row['username'] ?? '')); ?></div>
                            </div>
                        </div>
                        <div class="u-id-badge">#<?php echo (int)$row['id']; ?></div>
                    </div>

                    <div class="user-card-tags">
                        <span class="u-role <?php echo $roleVal === 'admin' ? 'admin' : ''; ?>"><?php echo app_h($roleVal); ?></span>
                        <?php if ($isArchived): ?>
                            <span class="u-status archived">مؤرشف</span>
                        <?php elseif ($isActiveUser): ?>
                            <span class="u-status active">نشط</span>
                        <?php else: ?>
                            <span class="u-status inactive">موقوف</span>
                        <?php endif; ?>
                        <?php if ($hasAllowCaps || $hasDenyCaps): ?>
                            <span class="u-perm-badge allow" title="<?php echo app_h($allowTitle); ?>">فتح: <?php echo count($rowAllow); ?></span>
                            <span class="u-perm-badge deny" title="<?php echo app_h($denyTitle); ?>">غلق: <?php echo count($rowDeny); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="user-card-grid">
                        <?php if ($hasPhone): ?>
                        <div class="user-info-box">
                            <div class="user-info-label">الهاتف</div>
                            <div class="user-info-value"><?php echo app_h(trim((string)($row['phone'] ?? '')) !== '' ? (string)$row['phone'] : 'غير مسجل'); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($hasEmail): ?>
                        <div class="user-info-box">
                            <div class="user-info-label">البريد الإلكتروني</div>
                            <div class="user-info-value"><?php echo app_h(trim((string)($row['email'] ?? '')) !== '' ? (string)$row['email'] : 'غير مسجل'); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="user-info-box">
                            <div class="user-info-label">الراتب الأساسي</div>
                            <div class="user-info-value"><?php echo number_format($rowBasicSalary, 2); ?></div>
                        </div>
                        <?php if ($hasCreatedAt): ?>
                        <div class="user-info-box">
                            <div class="user-info-label">تاريخ الإضافة</div>
                            <div class="user-info-value"><?php echo app_h((string)($row['created_at'] ?? '')); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="user-info-box">
                            <div class="user-info-label">حالة الحساب</div>
                            <div class="user-info-value"><?php echo $isArchived ? 'محفوظ في الأرشيف' : ($isActiveUser ? 'فعال داخل التشغيل اليومي' : 'موجود بالنظام لكن غير مفعل'); ?></div>
                        </div>
                    </div>

                    <div class="user-card-footer">
                        <div class="user-card-created"><?php echo $isSelf ? 'هذا هو الحساب المستخدم حاليًا في الجلسة.' : 'يمكن إدارة الحساب من الإجراءات المباشرة أدناه.'; ?></div>
                        <div class="u-row-actions">
                            <a class="u-action-btn primary" href="users.php?edit=<?php echo (int)$row['id']; ?>#userForm" title="تعديل">تعديل</a>
                            <?php if (!$isSelf): ?>
                            <?php if ($isArchived): ?>
                            <form method="post" style="display:inline;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="action" value="restore_user">
                                <input type="hidden" name="user_id" value="<?php echo (int)$row['id']; ?>">
                                <button class="u-action-btn success" type="submit" title="استعادة">استعادة</button>
                            </form>
                            <?php else: ?>
                            <form method="post" style="display:inline;">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="action" value="toggle_user_active">
                                <input type="hidden" name="user_id" value="<?php echo (int)$row['id']; ?>">
                                <button class="u-action-btn warn" type="submit" title="<?php echo $isActiveUser ? 'إيقاف التفعيل' : 'تفعيل'; ?>"><?php echo $isActiveUser ? 'إيقاف' : 'تفعيل'; ?></button>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('أرشفة المستخدم؟');">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="action" value="archive_user">
                                <input type="hidden" name="user_id" value="<?php echo (int)$row['id']; ?>">
                                <input type="hidden" name="archive_reason" value="تمت الأرشفة من شاشة إدارة المستخدمين">
                                <button class="u-action-btn danger" type="submit" title="أرشفة">أرشفة</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" style="display:inline;" onsubmit="return confirm('تأكيد حذف المستخدم؟');">
                                <?php echo app_csrf_input(); ?>
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?php echo (int)$row['id']; ?>">
                                <button class="u-action-btn danger" type="submit" title="حذف">حذف</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
                <?php
                    endwhile;
                else:
                ?>
                <div class="user-empty-state"><?php echo app_h($isEnglish ? 'No users found.' : 'لا يوجد مستخدمون حالياً في هذا القسم.'); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const box = document.getElementById('usersPermissionsBox');
    if (!box) return;

    const searchInput = document.getElementById('usersPermSearch');
    const countNode = document.getElementById('usersPermCount');
    const rows = Array.from(box.querySelectorAll('.users-perm-row'));
    const countLabel = <?php echo json_encode($txtPermAppliedCount, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    const syncRowState = (row) => {
        const allow = row.querySelector('.users-cap-allow');
        const deny = row.querySelector('.users-cap-deny');
        const allowWrap = row.querySelector('.users-cap-toggle.allow');
        const denyWrap = row.querySelector('.users-cap-toggle.deny');
        if (!allow || !deny || !allowWrap || !denyWrap) return;
        allowWrap.classList.toggle('active', !!allow.checked);
        denyWrap.classList.toggle('active', !!deny.checked);
    };

    const updateCount = () => {
        let total = 0;
        for (const row of rows) {
            const allow = row.querySelector('.users-cap-allow');
            const deny = row.querySelector('.users-cap-deny');
            if ((allow && allow.checked) || (deny && deny.checked)) {
                total++;
            }
        }
        if (countNode) countNode.textContent = `${countLabel}: ${total}`;
    };

    const applyBulk = (mode, group = '') => {
        const targetRows = group
            ? rows.filter((row) => row.dataset.capGroup === group)
            : rows;
        for (const row of targetRows) {
            if (row.classList.contains('hidden-by-search')) continue;
            const allow = row.querySelector('.users-cap-allow');
            const deny = row.querySelector('.users-cap-deny');
            if (!allow || !deny) continue;
            if (mode === 'allow') {
                allow.checked = true;
                deny.checked = false;
            } else if (mode === 'deny') {
                allow.checked = false;
                deny.checked = true;
            } else {
                allow.checked = false;
                deny.checked = false;
            }
            syncRowState(row);
        }
        updateCount();
    };

    for (const row of rows) {
        const allow = row.querySelector('.users-cap-allow');
        const deny = row.querySelector('.users-cap-deny');
        const resetBtn = row.querySelector('.users-cap-reset');

        syncRowState(row);

        if (allow) {
            allow.addEventListener('change', () => {
                if (allow.checked && deny) deny.checked = false;
                syncRowState(row);
                updateCount();
            });
        }
        if (deny) {
            deny.addEventListener('change', () => {
                if (deny.checked && allow) allow.checked = false;
                syncRowState(row);
                updateCount();
            });
        }
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                if (allow) allow.checked = false;
                if (deny) deny.checked = false;
                syncRowState(row);
                updateCount();
            });
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const q = searchInput.value.trim().toLowerCase();
            for (const row of rows) {
                const key = row.dataset.capKey || '';
                const label = row.dataset.capLabel || '';
                const visible = q === '' || key.includes(q) || label.includes(q);
                row.classList.toggle('hidden-by-search', !visible);
            }
        });
    }

    const bulkButtons = box.querySelectorAll('[data-perm-bulk]');
    for (const btn of bulkButtons) {
        btn.addEventListener('click', () => {
            const mode = String(btn.getAttribute('data-perm-bulk') || '');
            const group = String(btn.getAttribute('data-perm-group') || '');
            applyBulk(mode, group);
        });
    }

    updateCount();
})();
</script>

<?php require 'footer.php'; ?>
<?php ob_end_flush(); ?>
