<?php
ob_start();
// dashboard.php - (Royal Phantom V36.0 - Smart Ticker & Stable Updates)

error_reporting(E_ALL);

require 'auth.php'; 
require 'config.php'; 
app_handle_lang_switch($conn);

// 1. الهوية والصلاحيات
$my_role = $_SESSION['role'] ?? 'guest';
$my_name = $_SESSION['name'] ?? 'User';
$can_view_dashboard = app_user_can_any(['dashboard.view', 'jobs.view_all', 'jobs.view_assigned', 'jobs.edit_assigned', 'jobs.manage_all', 'jobs.create']);
if (!$can_view_dashboard) {
    http_response_code(403);
    require 'header.php';
    echo "<div class='container' style='margin-top:30px;'><div style='background:#1d0f0f;border:1px solid #5b2020;border-radius:12px;padding:20px;color:#ffb3b3;'>" . app_h(app_tr('غير مصرح لك بالدخول إلى لوحة العمليات.', 'You are not authorized to access the dashboard.')) . "</div></div>";
    require 'footer.php';
    exit;
}
$is_admin = app_user_can('jobs.manage_all');
$can_edit = app_user_can_any(['jobs.manage_all', 'jobs.edit_assigned', 'jobs.create']);
$can_create_job = app_user_can_any(['jobs.create', 'jobs.manage_all']);
$can_view_invoices = app_user_can('invoices.view');
$is_en = app_lang_is('en');
$tr = static function (string $ar, string $en) use ($is_en): string {
    return $is_en ? $en : $ar;
};
$dashboardUiTheme = app_ui_theme($conn, (int)($_SESSION['user_id'] ?? 0));
$dashboardUiVars = app_ui_theme_css_vars($dashboardUiTheme);
$search_query = trim((string)($_GET['q'] ?? ''));
$archiveParams = [
    'status' => 'completed',
    'type' => (string)($_GET['type'] ?? 'all'),
];
if ($search_query !== '') {
    $archiveParams['q'] = $search_query;
}
$archiveUrl = 'dashboard.php?' . http_build_query($archiveParams);
$csrfToken = app_csrf_token();
$jobsVisibilityClause = app_job_visibility_clause($conn, 'j');
$jobsVisibilityClauseRoot = app_job_visibility_clause($conn, 'job_orders');
$dashboardOperationTypes = app_operation_types($conn, true);
if (empty($dashboardOperationTypes)) {
    $dashboardOperationTypes = [
        ['type_key' => 'print', 'type_name' => $tr('الطباعة', 'Printing')],
        ['type_key' => 'carton', 'type_name' => $tr('الكرتون', 'Carton')],
        ['type_key' => 'plastic', 'type_name' => $tr('البلاستيك', 'Plastic')],
        ['type_key' => 'social', 'type_name' => $tr('السوشيال', 'Social Media')],
        ['type_key' => 'web', 'type_name' => $tr('المواقع', 'Web Projects')],
        ['type_key' => 'design_only', 'type_name' => $tr('التصميم فقط', 'Design Only')],
    ];
}
$dashboardTypeMap = [];
foreach ($dashboardOperationTypes as $typeRow) {
    $typeKey = (string)($typeRow['type_key'] ?? '');
    if ($typeKey === '') {
        continue;
    }
    $dashboardTypeMap[$typeKey] = (string)($typeRow['type_name'] ?? $typeKey);
}
$dashboardStatusFilter = (string)($_GET['status'] ?? 'active');
$dashboardTypeFilter = (string)($_GET['type'] ?? 'all');
$dashboardCurrentStatusLabel = $tr('العمليات الجارية', 'Active Jobs');
if ($dashboardStatusFilter === 'late') {
    $dashboardCurrentStatusLabel = $tr('المتأخرة', 'Late Jobs');
} elseif ($dashboardStatusFilter === 'all') {
    $dashboardCurrentStatusLabel = $tr('كل العمليات', 'All Jobs');
} elseif ($dashboardStatusFilter === 'completed') {
    $dashboardCurrentStatusLabel = $tr('الأرشيف', 'Archive');
}
$dashboardCurrentTypeLabel = $dashboardTypeFilter === 'all'
    ? $tr('كل الأقسام', 'All Departments')
    : (string)($dashboardTypeMap[$dashboardTypeFilter] ?? $dashboardTypeFilter);

$show_super_user_kpis = app_is_super_user() && app_license_edition() === 'owner';
$super_open_tickets_count = 0;
$super_active_systems_count = 0;
if ($show_super_user_kpis) {
    try {
        app_initialize_support_center($conn);
        $rowOpenTickets = $conn->query("SELECT COUNT(*) AS c FROM app_support_tickets WHERE status IN ('open','pending','answered')")->fetch_assoc() ?: [];
        $super_open_tickets_count = (int)($rowOpenTickets['c'] ?? 0);
    } catch (Throwable $e) {
        $super_open_tickets_count = 0;
    }

    try {
        app_initialize_license_management($conn);
        $rowActiveSystems = $conn->query("SELECT COUNT(*) AS c FROM app_license_registry WHERE status = 'active'")->fetch_assoc() ?: [];
        $super_active_systems_count = (int)($rowActiveSystems['c'] ?? 0);
    } catch (Throwable $e) {
        $super_active_systems_count = 0;
    }
}

// 2. معالجة الحذف
if(isset($_GET['delete_job']) && $is_admin){
    if (!app_verify_csrf($_GET['_token'] ?? '')) {
        http_response_code(419);
        die('Invalid CSRF token');
    }
    $jid = intval($_GET['delete_job']);
    app_require_job_access($conn, $jid, true);
    $tables = ['social_posts', 'job_files', 'job_proofs', 'invoices', 'job_orders'];
    foreach($tables as $tbl) $conn->query("DELETE FROM $tbl WHERE " . ($tbl=='job_orders'?'id':'job_id') . "=$jid");
    header("Location: dashboard.php?msg=deleted"); exit;
}

// 3. معالجة الرفض
if(isset($_GET['action']) && $_GET['action'] == 'accept' && $can_edit) {
    if (!app_verify_csrf($_GET['_token'] ?? '')) {
        http_response_code(419);
        die('Invalid CSRF token');
    }
    $type = (string)($_GET['type'] ?? '');
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0 && $type === 'order') {
        app_require_job_access($conn, $id, true);
        $stmt = $conn->prepare("SELECT id, job_type, status, current_stage FROM job_orders WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!empty($row)) {
            $jobType = strtolower(trim((string)($row['job_type'] ?? '')));
            $workflow = app_operation_workflow($conn, $jobType, [
                'briefing' => '1. التجهيز',
            ]);
            $firstStage = (string)(array_key_first($workflow) ?? 'briefing');
            if ($firstStage === '' || $firstStage === 'pending') {
                $firstStage = 'briefing';
            }

            $statusNow = strtolower(trim((string)($row['status'] ?? 'pending')));
            $stageNow = strtolower(trim((string)($row['current_stage'] ?? 'pending')));
            if ($statusNow === 'pending' || $stageNow === 'pending') {
                app_update_job_stage($conn, $id, $firstStage, 'processing');
            }
        }

        header("Location: job_details.php?id=" . $id);
        exit;
    }
}

// 4. معالجة الرفض
if(isset($_GET['action']) && $_GET['action'] == 'reject' && $can_edit) {
    if (!app_verify_csrf($_GET['_token'] ?? '')) {
        http_response_code(419);
        die('Invalid CSRF token');
    }
    $type = $_GET['type']; 
    $id = intval($_GET['id']);
    $reason = $conn->real_escape_string($_GET['reason'] ?? 'تم الرفض من الإدارة');

    if($type == 'order') {
        app_require_job_access($conn, $id, true);
        app_update_job_stage_with_note($conn, $id, 'cancelled', '[سبب الرفض: ' . $reason . ']', 'cancelled');
    } elseif ($type == 'quote') {
        $sql = "UPDATE quotes SET status = 'rejected', notes = CONCAT(notes, '\n[سبب الرفض: $reason]') WHERE id = $id";
        $conn->query($sql);
    }
    header("Location: dashboard.php?msg=rejected"); exit;
}

// =========================================================
// [AJAX HANDLER] معالج البيانات الذكي
// =========================================================
if (isset($_GET['live_updates'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    // إعادة تعريف الصلاحيات داخل AJAX
    $my_role = $_SESSION['role'] ?? 'guest';
    $is_admin = app_user_can('jobs.manage_all');
    $can_edit = app_user_can_any(['jobs.manage_all', 'jobs.edit_assigned', 'jobs.create']);
    $jobsVisibilityClause = app_job_visibility_clause($conn, 'j');
    $jobsVisibilityClauseRoot = app_job_visibility_clause($conn, 'job_orders');

    // 1. الفلاتر الآمنة
    $allowed_statuses = ['active', 'late', 'completed', 'all'];
    $allowed_types = array_merge(['all'], array_keys($dashboardTypeMap));

    $status_filter = in_array($_GET['status'] ?? '', $allowed_statuses) ? $_GET['status'] : 'active';
    $type_filter = in_array($_GET['type'] ?? '', $allowed_types) ? $_GET['type'] : 'all';
    $lite_mode = (string)($_GET['lite'] ?? '') === '1';
    $search_query = isset($_GET['q']) ? $conn->real_escape_string($_GET['q']) : '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = (int)app_setting_get($conn, 'dashboard_page_size', '18');
    if ($pageSize < 6) {
        $pageSize = 6;
    }
    if ($pageSize > 60) {
        $pageSize = 60;
    }

    // 2. الاستعلام
    $sql = "SELECT j.*, c.name as client_name FROM job_orders j LEFT JOIN clients c ON j.client_id = c.id WHERE ($jobsVisibilityClause)";
    $countSql = "SELECT COUNT(*) AS c FROM job_orders j LEFT JOIN clients c ON j.client_id = c.id WHERE ($jobsVisibilityClause)";
    
    if ($status_filter == 'active') {
        $sql .= " AND COALESCE(NULLIF(status, ''), 'pending') IN ('processing','pending','active')";
        $countSql .= " AND COALESCE(NULLIF(status, ''), 'pending') IN ('processing','pending','active')";
    }
    elseif ($status_filter == 'late') {
        $sql .= " AND delivery_date < CURDATE() AND COALESCE(NULLIF(status, ''), 'pending') IN ('processing','pending','active')";
        $countSql .= " AND delivery_date < CURDATE() AND COALESCE(NULLIF(status, ''), 'pending') IN ('processing','pending','active')";
    }
    elseif ($status_filter == 'completed') {
        $sql .= " AND COALESCE(NULLIF(status, ''), 'pending') IN ('completed','cancelled')";
        $countSql .= " AND COALESCE(NULLIF(status, ''), 'pending') IN ('completed','cancelled')";
    }
    
    if ($type_filter != 'all') {
        $sql .= " AND job_type = '$type_filter'";
        $countSql .= " AND job_type = '$type_filter'";
    }
    if (!empty($search_query)) {
        $sql .= " AND (job_name LIKE '%$search_query%' OR c.name LIKE '%$search_query%' OR j.id = '$search_query')";
        $countSql .= " AND (job_name LIKE '%$search_query%' OR c.name LIKE '%$search_query%' OR j.id = '$search_query')";
    }

    $countRes = $conn->query($countSql);
    $totalRows = (int)(($countRes && ($countRow = $countRes->fetch_assoc())) ? ($countRow['c'] ?? 0) : 0);
    $totalPages = max(1, (int)ceil($totalRows / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;
    
    $sql .= " ORDER BY j.delivery_date ASC, j.id DESC LIMIT $offset, $pageSize";
    $result = $conn->query($sql);

    // 3. الإحصائيات والشارت
    $count_active = $conn->query("SELECT COUNT(*) FROM job_orders WHERE ($jobsVisibilityClauseRoot) AND COALESCE(NULLIF(status, ''), 'pending') IN ('processing','pending','active')")->fetch_row()[0] ?? 0;
    $count_late = $conn->query("SELECT COUNT(*) FROM job_orders WHERE ($jobsVisibilityClauseRoot) AND delivery_date < CURDATE() AND COALESCE(NULLIF(status, ''), 'pending') IN ('processing','pending','active')")->fetch_row()[0] ?? 0;
    
    $chart_types_data = [];
    $chart_status_data = ['completed' => 0, 'active' => 0];
    if (!$lite_mode) {
        $chart_types_q = $conn->query("SELECT job_type, COUNT(*) as c FROM job_orders WHERE ($jobsVisibilityClauseRoot) AND status != 'cancelled' GROUP BY job_type");
        while($chart_types_q && ($row = $chart_types_q->fetch_assoc())) {
            $chart_types_data[$row['job_type']] = $row['c'];
        }

        $chart_status_q = $conn->query("SELECT SUM(CASE WHEN COALESCE(NULLIF(status, ''), 'pending') IN ('completed','cancelled') THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN COALESCE(NULLIF(status, ''), 'pending') IN ('processing','pending','active') THEN 1 ELSE 0 END) as active FROM job_orders WHERE ($jobsVisibilityClauseRoot)");
        if ($chart_status_q && ($statusRow = $chart_status_q->fetch_assoc())) {
            $chart_status_data = $statusRow;
        }
    }

    $stats = [
        'active' => $count_active, 'late' => $count_late,
        'chart_types' => $chart_types_data, 'chart_status' => $chart_status_data
    ];
    if (app_is_super_user() && app_license_edition() === 'owner') {
        $openTicketsLive = 0;
        $activeSystemsLive = 0;
        try {
            app_initialize_support_center($conn);
            $openRow = $conn->query("SELECT COUNT(*) AS c FROM app_support_tickets WHERE status IN ('open','pending','answered')")->fetch_assoc() ?: [];
            $openTicketsLive = (int)($openRow['c'] ?? 0);
        } catch (Throwable $e) {
            $openTicketsLive = 0;
        }
        try {
            app_initialize_license_management($conn);
            $activeRow = $conn->query("SELECT COUNT(*) AS c FROM app_license_registry WHERE status = 'active'")->fetch_assoc() ?: [];
            $activeSystemsLive = (int)($activeRow['c'] ?? 0);
        } catch (Throwable $e) {
            $activeSystemsLive = 0;
        }
        $stats['super_open_tickets'] = $openTicketsLive;
        $stats['super_active_systems'] = $activeSystemsLive;
    }

    // 4. بناء العرض (Grouping Logic)
    ob_start();
    
    // خريطة المراحل وتسميتها
    $groups = [
        'prep' => ['title' => $tr('التحضير والمحتوى', 'Planning & Content'), 'stages' => ['pending', 'briefing', 'idea_review', 'content_writing', 'content_review'], 'jobs' => []],
        'design' => ['title' => $tr('التصميم والمراجعة', 'Design & Review'), 'stages' => ['design', 'designing', 'design_review', 'client_rev'], 'jobs' => []],
        'production' => ['title' => $tr('الإنتاج والطباعة', 'Production & Printing'), 'stages' => ['pre_press', 'materials', 'cylinders', 'extrusion', 'printing'], 'jobs' => []],
        'finishing' => ['title' => $tr('التشطيب والتسليم', 'Finishing & Delivery'), 'stages' => ['cutting', 'finishing', 'delivery', 'accounting'], 'jobs' => []],
        'archive' => ['title' => $tr('الأرشيف', 'Archive'), 'stages' => ['completed', 'cancelled'], 'jobs' => []]
    ];

    $progress_map = [
        'pending' => 0, 'cancelled' => 0, 'briefing' => 5, 'idea_review' => 10, 'content_writing' => 15, 'content_review' => 20,
        'design' => 30, 'designing' => 30, 'design_review' => 35, 'client_rev' => 40, 'materials' => 50,
        'pre_press' => 60, 'cylinders' => 65, 'extrusion' => 70, 'printing' => 80, 'cutting' => 85, 'finishing' => 90,
        'delivery' => 95, 'accounting' => 98, 'completed' => 100
    ];
    $stage_ar = [
        'pending'=>$tr('جديد', 'New'),
        'cancelled'=>$tr('ملغي', 'Cancelled'),
        'briefing'=>$tr('تجهيز', 'Briefing'),
        'idea_review'=>$tr('فكرة', 'Idea Review'),
        'content_writing'=>$tr('محتوى', 'Content'),
        'content_review'=>$tr('مراجعة', 'Content Review'),
        'design'=>$tr('تصميم', 'Design'),
        'designing'=>$tr('تصميم', 'Design'),
        'design_review'=>$tr('تدقيق', 'Design Review'),
        'client_rev'=>$tr('عميل', 'Client Review'),
        'pre_press'=>'CTP',
        'printing'=>$tr('طباعة', 'Printing'),
        'finishing'=>$tr('تشطيب', 'Finishing'),
        'delivery'=>$tr('تسليم', 'Delivery'),
        'completed'=>$tr('أرشيف', 'Archived'),
        'accounting'=>$tr('مالية', 'Accounting'),
        'materials'=>$tr('خامات', 'Materials'),
        'cylinders'=>$tr('سلندرات', 'Cylinders'),
        'extrusion'=>$tr('سحب', 'Extrusion')
    ];
    $icons = ['print'=>'fa-print', 'carton'=>'fa-box-open', 'plastic'=>'fa-bag-shopping', 'social'=>'fa-hashtag', 'web'=>'fa-laptop-code', 'design_only'=>'fa-pen-nib'];

    // توزيع العمليات على المجموعات
    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $st = (string)$row['current_stage'];
            $statusNow = strtolower(trim((string)($row['status'] ?? 'pending')));
            if ($statusNow === 'active') {
                $statusNow = 'processing';
            }
            if ($statusNow === 'completed' || $statusNow === 'cancelled') {
                $st = $statusNow;
            }
            $added = false;
            foreach ($groups as $key => $group) {
                if (in_array($st, $group['stages'])) {
                    $groups[$key]['jobs'][] = $row;
                    $added = true;
                    break;
                }
            }
            if (!$added) $groups['prep']['jobs'][] = $row;
        }
    }

    // عرض المجموعات
    $has_jobs = false;
    foreach ($groups as $g_key => $group):
        if (empty($group['jobs'])) continue;
        $has_jobs = true;
    ?>
    
    <div class="stage-section">
        <h3 class="stage-header"><i class="fa-solid fa-layer-group"></i> <?php echo $group['title']; ?> <span class="badge"><?php echo count($group['jobs']); ?></span></h3>
        <div class="ph-grid">
            <?php foreach ($group['jobs'] as $row): 
                $st = $row['current_stage'];
                $jobRef = trim((string)($row['job_number'] ?? ''));
                if ($jobRef === '') {
                    $jobRef = app_assign_document_number($conn, 'job_orders', (int)$row['id'], 'job_number', 'job', date('Y-m-d'));
                }
                if ($jobRef === '') {
                    $jobRef = '#' . (int)$row['id'];
                }
                $prog = $progress_map[$st] ?? 5;
                $st_label = $stage_ar[$st] ?? $st;
                $icon = $icons[$row['job_type']] ?? 'fa-circle';
                $type_label = (string)($dashboardTypeMap[(string)($row['job_type'] ?? '')] ?? ($row['job_type'] ?: $tr('عام', 'General')));
                
                $days = 0; $late = false; $urgent = false; $day_msg = '';
                $d_date = $row['delivery_date'];
                
                if ($st == 'completed') { $day_msg = $tr('مكتملة', 'Completed'); } 
                elseif ($st == 'cancelled') { $day_msg = $tr('ملغي', 'Cancelled'); } 
                elseif (!empty($d_date) && $d_date != '0000-00-00') {
                    try {
                        $diff = (new DateTime())->diff(new DateTime($d_date));
                        $days = (int)$diff->format('%r%a');
                        if ($days < 0) { $late = true; $day_msg = $tr('متأخر ', 'Late by ') . abs($days) . $tr(' يوم', ' day(s)'); }
                        elseif ($days <= 2) { $urgent = true; $day_msg = $tr('باقي ', 'Due in ') . $days . $tr(' يوم', ' day(s)'); }
                        else { $day_msg = $tr('باقي ', 'Due in ') . $days . $tr(' يوم', ' day(s)'); }
                    } catch (Exception $e) { $day_msg = "-"; }
                } else { $day_msg = $tr('غير محدد', 'Not set'); }

                $card_class = 'ph-card-normal';
                $bar_color = 'var(--ae-gold)';
                if ($st == 'completed') { $card_class = 'ph-card-done'; $bar_color = '#2ecc71'; }
                elseif ($st == 'cancelled') { $card_class = 'ph-card-done'; $bar_color = '#e74c3c'; }
                elseif ($late) { $card_class = 'ph-card-late'; $bar_color = '#e74c3c'; }
                elseif ($urgent) { $card_class = 'ph-card-urgent'; $bar_color = '#f1c40f'; }
            ?>
            <div class="ph-card <?php echo $card_class; ?>">
                <div class="ph-card-top">
                    <span class="ph-id"><?php echo app_h($jobRef); ?></span>
                    <div class="ph-status-pill <?php echo $late?'late':($urgent?'urgent':'normal'); ?>">
                        <?php echo $day_msg; ?>
                    </div>
                </div>

                <div class="ph-card-body" onclick="window.location.href='job_details.php?id=<?php echo $row['id']; ?>'">
                    <div class="ph-icon-float"><i class="fa-solid <?php echo $icon; ?>"></i></div>
                    <div class="ph-card-meta">
                        <span class="ph-meta-tag"><i class="fa-solid <?php echo $icon; ?>"></i> <?php echo app_h($type_label); ?></span>
                        <?php if (!empty($d_date) && $d_date !== '0000-00-00'): ?>
                            <span class="ph-meta-tag"><i class="fa-regular fa-calendar"></i> <?php echo app_h($d_date); ?></span>
                        <?php endif; ?>
                    </div>
                    <h3 class="ph-job-title"><?php echo $row['job_name']; ?></h3>
                    <div class="ph-client"><i class="fa-regular fa-user"></i> <?php echo $row['client_name']; ?></div>
                    <div class="ph-stage-line">
                        <span class="ph-stage-badge"><?php echo app_h($st_label); ?></span>
                        <span class="ph-stage-note"><?php echo app_h($day_msg); ?></span>
                    </div>
                    <div class="ph-prog-wrapper">
                        <div class="ph-prog-info">
                            <span><?php echo app_h($tr('التقدم الحالي', 'Current Progress')); ?></span>
                            <span><?php echo $prog; ?>%</span>
                        </div>
                        <div class="ph-prog-track">
                            <div class="ph-prog-fill" style="width:<?php echo $prog; ?>%; background:<?php echo $bar_color; ?>;"></div>
                        </div>
                    </div>
                </div>

                <div class="ph-card-actions">
                    <a href="job_details.php?id=<?php echo $row['id']; ?>" class="ph-btn ph-btn-enter"><?php echo app_h($tr('دخول', 'Open')); ?> <i class="fa-solid fa-arrow-left"></i></a>
                    
                    <div class="secondary-actions">
                        <?php if($can_edit && $st!='completed' && $st!='cancelled'): ?>
                            <a href="edit_job.php?id=<?php echo $row['id']; ?>" class="ph-btn ph-btn-icon" title="تعديل"><i class="fa-solid fa-pen"></i></a>
                        <?php endif; ?>
                        
                        <?php if($is_admin): ?>
                            <a href="?delete_job=<?php echo $row['id']; ?>&amp;_token=<?php echo urlencode($csrfToken); ?>" class="ph-btn ph-btn-icon ph-btn-del" onclick="return confirm('هل أنت متأكد من الحذف النهائي؟')" title="حذف"><i class="fa-solid fa-trash"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; 
    
    if (!$has_jobs): ?>
        <div style="text-align:center; padding:80px 0; color:#666;">
            <i class="fa-solid fa-wind fa-3x"></i><br><br><?php echo app_h($tr('لا توجد عمليات تطابق البحث', 'No jobs match this search')); ?>
        </div>
    <?php endif;
    $grid_html = ob_get_clean();

    ob_start();
    if ($totalPages > 1):
        $baseParams = [
            'status' => $status_filter,
            'type' => $type_filter,
            'q' => $search_query,
        ];
    ?>
    <div class="dash-pagination-wrap">
        <div class="dash-pagination-meta"><?php echo app_h($tr('إجمالي النتائج', 'Total results')); ?>: <?php echo (int)$totalRows; ?> • <?php echo app_h($tr('صفحة', 'Page')); ?> <?php echo (int)$page; ?> <?php echo app_h($tr('من', 'of')); ?> <?php echo (int)$totalPages; ?></div>
        <div class="dash-pagination-links">
            <?php if ($page > 1): ?>
                <a class="dash-page-link" href="dashboard.php?<?php echo http_build_query(array_merge($baseParams, ['page' => $page - 1])); ?>"><?php echo app_h($tr('السابق', 'Previous')); ?></a>
            <?php endif; ?>
            <?php
            $windowStart = max(1, $page - 2);
            $windowEnd = min($totalPages, $page + 2);
            for ($p = $windowStart; $p <= $windowEnd; $p++):
                $activeClass = ($p === $page) ? 'active' : '';
            ?>
                <a class="dash-page-link <?php echo $activeClass; ?>" href="dashboard.php?<?php echo http_build_query(array_merge($baseParams, ['page' => $p])); ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a class="dash-page-link" href="dashboard.php?<?php echo http_build_query(array_merge($baseParams, ['page' => $page + 1])); ?>"><?php echo app_h($tr('التالي', 'Next')); ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php
    endif;
    $pagination_html = ob_get_clean();

    // Ticker Logic (معالج التكرار)
    ob_start();
    $alerts_q = $conn->query("SELECT j.id, j.job_name, (SELECT status FROM job_proofs WHERE job_id=j.id ORDER BY id DESC LIMIT 1) as st FROM job_orders j WHERE ($jobsVisibilityClause) AND j.current_stage IN ('client_rev','design_review')");
    $alerts = [];
    if($alerts_q) while($r = $alerts_q->fetch_assoc()) $alerts[] = $r;
    
    if(!empty($alerts)): ?>
    <div class="ticker-content">
        <?php foreach($alerts as $al): 
            $s = $al['st']; $col = '#f1c40f'; $txt = $tr('بانتظار العميل', 'Waiting for client');
            if(strpos($s,'reject')!==false){ $col='#e74c3c'; $txt=$tr('مطلوب تعديل', 'Revision needed'); }
            elseif(strpos($s,'approv')!==false){ $col='#2ecc71'; $txt=$tr('تم الاعتماد', 'Approved'); }
        ?>
        <div class="ticker-item">
            <span class="dot" style="background:<?php echo $col; ?>"></span>
            <span><?php echo $txt; ?>: <?php echo $al['job_name']; ?></span>
            <a href="job_details.php?id=<?php echo $al['id']; ?>"><?php echo app_h($tr('عرض', 'View')); ?></a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="ribbon-empty">
        <i class="fa-regular fa-circle-check" style="color:#63d18c;"></i>
        <?php echo app_h($tr('لا مراجعات معلقة', 'No pending reviews')); ?>
    </div>
    <?php endif;
    $ticker_html = ob_get_clean();

    $last_job = $conn->query("SELECT id, job_name FROM job_orders WHERE ($jobsVisibilityClauseRoot) ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $last_review = $conn->query("SELECT p.status, j.job_name, p.job_id FROM job_proofs p JOIN job_orders j ON p.job_id=j.id WHERE ($jobsVisibilityClause) ORDER BY p.id DESC LIMIT 1")->fetch_assoc();

    echo json_encode(['stats' => $stats, 'grid' => $grid_html, 'pagination' => $pagination_html, 'ticker' => $ticker_html, 'last_job' => $last_job, 'last_review' => $last_review]);
    exit;
}

require 'header.php'; 
?>

<style>
    :root { 
        --bg: <?php echo app_h($dashboardUiVars['--bg']); ?>;
        --bg-alt: <?php echo app_h($dashboardUiVars['--bg-alt']); ?>;
        --card-bg: <?php echo app_h($dashboardUiVars['--card-bg']); ?>;
        --card-strong: <?php echo app_h($dashboardUiVars['--card-strong']); ?>;
        --ae-gold: <?php echo app_h($dashboardUiVars['--ae-gold']); ?>;
        --ae-accent-soft: <?php echo app_h($dashboardUiVars['--ae-accent-soft']); ?>;
        --border: <?php echo app_h($dashboardUiVars['--border']); ?>;
        --text: <?php echo app_h($dashboardUiVars['--text']); ?>;
        --red-glow: 0 0 10px rgba(231, 76, 60, 0.3);
        --muted: <?php echo app_h($dashboardUiVars['--muted']); ?>;
        --shadow-lg: 0 26px 54px rgba(0, 0, 0, 0.34);
    }
    body {
        background:
            radial-gradient(circle at top right, color-mix(in srgb, var(--ae-gold) 12%, transparent), transparent 26%),
            radial-gradient(circle at top left, rgba(255,255,255,0.04), transparent 18%),
            linear-gradient(180deg, var(--bg) 0%, var(--bg-alt) 100%);
        font-family: 'Cairo', sans-serif;
        color: var(--text);
        padding-bottom: 80px;
    }
    body::before {
        content: '';
        position: fixed;
        inset: 0;
        pointer-events: none;
        background-image:
            linear-gradient(rgba(255,255,255,.026) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,.026) 1px, transparent 1px);
        background-size: 54px 54px;
        mask-image: linear-gradient(180deg, rgba(0,0,0,.8), transparent 78%);
        opacity: .42;
        z-index: 0;
    }
    .container { position: relative; z-index: 2; overflow: visible; }
    .dashboard-shell {
        padding-top: 18px;
        padding-bottom: 28px;
    }
    .dashboard-shell::before,
    .dashboard-shell::after {
        content: '';
        position: fixed;
        width: 320px;
        height: 320px;
        border-radius: 50%;
        pointer-events: none;
        filter: blur(80px);
        opacity: 0.18;
        z-index: 0;
    }
    .dashboard-shell::before {
        top: 90px;
        inset-inline-start: -80px;
        background: color-mix(in srgb, var(--ae-gold) 38%, transparent);
    }
    .dashboard-shell::after {
        top: 220px;
        inset-inline-end: -120px;
        background: rgba(255,255,255,0.16);
    }
    .dashboard-card {
        background: linear-gradient(180deg, color-mix(in srgb, var(--card-bg) 88%, transparent), color-mix(in srgb, var(--card-strong) 92%, transparent));
        border: 1px solid var(--border);
        box-shadow: var(--shadow-lg);
        backdrop-filter: blur(18px);
        transition: transform .22s ease, border-color .22s ease, box-shadow .22s ease;
    }
    .dashboard-card:hover {
        transform: translateY(-2px);
        border-color: color-mix(in srgb, var(--ae-gold) 34%, var(--border));
        box-shadow: 0 28px 58px rgba(0,0,0,.38), 0 0 0 1px color-mix(in srgb, var(--ae-gold) 8%, transparent) inset;
    }
    .dashboard-intro {
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(320px, 0.86fr);
        gap: 22px;
        margin-bottom: 24px;
        position: relative;
        z-index: 1;
    }
    .dashboard-stack {
        display: grid;
        gap: 16px;
        min-width: 0;
    }
    .dashboard-aside {
        display: grid;
        gap: 16px;
        align-content: start;
    }
    .dashboard-ribbon {
        display: grid;
        grid-template-columns: 1fr;
        gap: 14px;
        margin-bottom: 18px;
        position: relative;
        z-index: 1;
    }
    .dashboard-insights {
        display: grid;
        gap: 16px;
        margin-bottom: 26px;
        position: relative;
        z-index: 1;
    }
    .dashboard-insights.has-owner-kpis {
        grid-template-columns: minmax(260px, 0.72fr) minmax(0, 1fr);
        align-items: stretch;
    }

    .charts-container {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); 
        gap: 16px;
        margin-bottom: 0;
        position: relative;
        z-index: 1;
    }
    .chart-card {
        border-radius: 24px;
        padding: 18px;
        height: 280px;
        position: relative;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        z-index: 1;
    }
    .chart-card h3 { 
        color: color-mix(in srgb, var(--text) 84%, #d7d7d7 16%);
        font-size: 0.92rem;
        margin: 0 0 15px 0;
        width: 100%;
        text-align: right;
        border-bottom: 1px dashed var(--border);
        padding-bottom: 12px;
    }
    canvas { max-height: 220px !important; width: 100% !important; }

    .ph-hero { 
        border: 1px solid color-mix(in srgb, var(--ae-gold) 20%, transparent);
        border-radius: 30px;
        padding: 28px;
        margin-bottom: 24px;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 18px;
        align-items: center;
        position: relative;
        overflow: hidden;
        background:
            radial-gradient(circle at top right, color-mix(in srgb, var(--ae-gold) 16%, transparent), transparent 34%),
            linear-gradient(145deg, color-mix(in srgb, var(--card-bg) 92%, #000 8%), color-mix(in srgb, var(--card-strong) 96%, #000 4%));
        box-shadow: var(--shadow-lg);
    }
    .ph-hero::before {
        content: '';
        position: absolute;
        inset: auto auto -40px -40px;
        width: 200px;
        height: 200px;
        border-radius: 50%;
        background: color-mix(in srgb, var(--ae-gold) 8%, transparent);
        filter: blur(30px);
    }
    .ph-hero::after {
        content: '';
        position: absolute;
        inset: -35%;
        background: linear-gradient(115deg, transparent 38%, color-mix(in srgb, var(--ae-gold) 10%, transparent) 50%, transparent 62%);
        transform: translateX(-28%) rotate(8deg);
        animation: ae-dashboard-sweep 8s ease-in-out infinite;
        pointer-events: none;
    }
    .ph-user { display: flex; align-items: center; gap: 15px; }
    .ph-avatar {
        width: 64px;
        height: 64px;
        border-radius: 22px;
        border: 1px solid color-mix(in srgb, var(--ae-gold) 65%, transparent);
        padding: 3px;
        background: color-mix(in srgb, var(--card-bg) 82%, #fff 4%);
        box-shadow: 0 14px 28px rgba(0,0,0,0.24);
    }
    .ph-date {
        color: var(--ae-gold);
        font-size: 0.78rem;
        font-weight: 800;
        letter-spacing: 1.6px;
        text-transform: uppercase;
        margin-bottom: 8px;
    }
    .ph-welcome h2 { margin: 0 0 8px 0; font-size: 1.9rem; color: var(--ae-gold); }
    .ph-welcome span { color: var(--text); }
    .ph-subtitle {
        color: var(--muted);
        max-width: 640px;
        line-height: 1.7;
        font-size: 0.92rem;
    }
    .ph-meta-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 16px;
    }
    .ph-meta-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 40px;
        padding: 0 14px;
        border-radius: 999px;
        border: 1px solid color-mix(in srgb, var(--border) 88%, transparent);
        background: color-mix(in srgb, var(--card-bg) 84%, #fff 4%);
        color: var(--text);
        font-size: 0.82rem;
        font-weight: 700;
    }
    .ph-meta-chip i { color: var(--ae-gold); }
    .ph-hero-side {
        display: grid;
        gap: 12px;
        min-width: 230px;
        position: relative;
        z-index: 1;
    }
    .ph-kpi {
        text-align: left;
        padding: 18px 18px 16px;
        border-radius: 24px;
        border: 1px solid color-mix(in srgb, var(--ae-gold) 20%, transparent);
        background: linear-gradient(180deg, color-mix(in srgb, var(--card-bg) 82%, #fff 5%), color-mix(in srgb, var(--card-strong) 92%, transparent));
        position: relative;
        overflow: hidden;
    }
    .ph-kpi::after {
        content: '';
        position: absolute;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        inset-block-start: 14px;
        inset-inline-end: 14px;
        background: #67e08f;
        box-shadow: 0 0 0 0 rgba(103,224,143,.48);
        animation: ae-live-pulse 1.9s ease-out infinite;
    }
    .ph-num { font-size: 2.5rem; font-weight: 900; color: var(--text); line-height: 1; margin-bottom: 6px; }
    .ph-lbl { font-size: 0.8rem; color: var(--muted); }
    .ph-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }
    .hero-btn {
        min-height: 42px;
        border-radius: 14px;
        padding: 0 16px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        font-size: 0.88rem;
        font-weight: 800;
        transition: 0.22s ease;
    }
    .hero-btn:hover { transform: translateY(-1px); }
    .hero-btn.primary {
        background: linear-gradient(135deg, var(--ae-accent-soft), var(--ae-gold));
        color: color-mix(in srgb, var(--bg) 88%, #000 12%);
        box-shadow: 0 14px 28px color-mix(in srgb, var(--ae-gold) 20%, transparent);
    }
    .hero-btn.secondary {
        border: 1px solid color-mix(in srgb, var(--border) 88%, transparent);
        background: color-mix(in srgb, var(--card-bg) 84%, #fff 4%);
        color: var(--text);
    }
    .su-kpis {
        display: grid;
        gap: 10px;
        margin: 0;
        padding: 12px;
        border-radius: 20px;
        align-content: start;
    }
    .su-kpis-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        color: #f0d47a;
        font-size: 0.85rem;
        font-weight: 800;
        padding: 2px 2px 8px;
    }
    .su-kpis-grid {
        display: grid;
        gap: 10px;
    }
    .su-kpi {
        border: 1px solid rgba(255,255,255,0.07);
        border-radius: 16px;
        padding: 12px 14px;
        background: rgba(255,255,255,0.03);
        display: grid;
        grid-template-columns: 42px minmax(0, 1fr) auto;
        align-items: center;
        gap: 12px;
    }
    .su-kpi .icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        border: 1px solid rgba(212,175,55,.45);
        background: rgba(212,175,55,.12);
        color: var(--ae-gold);
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .su-kpi-main {
        min-width: 0;
    }
    .su-kpi .k {
        color: #a8a8a8;
        font-size: .8rem;
        line-height: 1.3;
    }
    .su-kpi .v {
        color: #fff;
        font-size: 1.7rem;
        font-weight: 900;
        line-height: 1;
        min-width: 28px;
        text-align: end;
    }

    .dashboard-alerts {
        border-radius: 24px;
        padding: 18px;
        margin-bottom: 24px;
        position: relative;
        overflow: hidden;
    }
    .dashboard-alerts::before {
        content: '';
        position: absolute;
        inset: -40% auto auto -8%;
        width: 180px;
        height: 180px;
        border-radius: 50%;
        background: rgba(212,175,55,0.08);
        filter: blur(24px);
    }
    .dashboard-alert-head {
        color: var(--ae-gold);
        margin: 0 0 14px 0;
        font-size: 1rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        position: relative;
        z-index: 1;
    }
    .dashboard-alert-headline {
        display: inline-flex;
        align-items: center;
        gap: 10px;
    }
    .dashboard-alert-count {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        background: rgba(212,175,55,.14);
        border: 1px solid rgba(212,175,55,.5);
        padding: 4px 10px;
        border-radius: 999px;
        font-size: .82rem;
    }
    .dashboard-alert-badges {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .dashboard-alert-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: .8rem;
        border: 1px solid transparent;
    }
    .dashboard-alert-badge.overdue {
        background: rgba(231,76,60,.14);
        border-color: rgba(231,76,60,.35);
        color: #ffb0b0;
    }
    .dashboard-alert-badge.today {
        background: rgba(212,175,55,.14);
        border-color: rgba(212,175,55,.4);
        color: #ffe08a;
    }
    .dashboard-alert-badge.soon {
        background: rgba(52,152,219,.14);
        border-color: rgba(52,152,219,.35);
        color: #9fd6ff;
    }
    .critical-overdue-alert {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        padding: 14px 16px;
        margin-bottom: 16px;
        border-radius: 18px;
        background: linear-gradient(135deg, rgba(120,18,18,.35), rgba(231,76,60,.12));
        border: 1px solid rgba(231,76,60,.4);
        box-shadow: 0 0 0 1px rgba(231,76,60,.08), 0 10px 24px rgba(0,0,0,.18);
    }
    .critical-overdue-alert .title {
        color: #ffb0b0;
        font-weight: 800;
    }
    .critical-overdue-alert .meta {
        color: #d5c0c0;
        font-size: .88rem;
        margin-top: 4px;
    }
    .critical-overdue-alert.pulse {
        animation: criticalPulse 1.4s ease-in-out 3;
    }
    @keyframes criticalPulse {
        0% { box-shadow: 0 0 0 0 rgba(231,76,60,.26); }
        70% { box-shadow: 0 0 0 12px rgba(231,76,60,0); }
        100% { box-shadow: 0 0 0 0 rgba(231,76,60,0); }
    }
    .dashboard-alert-list {
        display: grid;
        gap: 10px;
        position: relative;
        z-index: 1;
    }
    .dashboard-alert-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        background: rgba(255,255,255,0.03);
        padding: 12px 14px;
        border-radius: 14px;
        border: 1px solid rgba(255,255,255,0.06);
    }
    .dashboard-alert-row.ticket {
        border-color: rgba(212,175,55,.24);
    }
    .ribbon-empty {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #9f9f9f;
        font-size: 0.8rem;
        font-weight: 700;
    }

    .ph-filters {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 30px;
        align-items: center;
        position: relative;
        z-index: 40;
        padding: 16px;
        border-radius: 22px;
        border: 1px solid rgba(255,255,255,0.08);
        background: linear-gradient(180deg, rgba(18,18,18,0.88), rgba(12,12,12,0.84));
        box-shadow: var(--shadow-lg);
    }
    .ph-select, .ph-search {
        background: rgba(8,8,8,0.75);
        color: #ccc;
        border: 1px solid var(--border);
        padding: 12px 15px;
        border-radius: 14px;
        font-family: 'Cairo';
        outline: none;
    }
    .ph-select, .ph-search { position: relative; z-index: 41; }
    .ph-select option { background: #111; color: #f1f1f1; }
    .ph-search { flex: 1; min-width: 200px; }
    .ph-select:focus, .ph-search:focus {
        border-color: var(--ae-gold);
        box-shadow: 0 0 0 3px rgba(212,175,55,0.12);
    }
    .btn-add {
        background: linear-gradient(135deg, #ebc857, #c18d18);
        color: #000;
        padding: 0 18px;
        min-height: 46px;
        border-radius: 14px;
        font-weight: bold;
        text-decoration: none;
        display: flex;
        gap: 8px;
        align-items: center;
        box-shadow: 0 12px 24px rgba(212,175,55,0.18);
    }
    .btn-archive {
        color: #b2b2b2;
        text-decoration: none;
        padding: 0 14px;
        min-height: 46px;
        border-radius: 14px;
        border: 1px solid rgba(255,255,255,0.08);
        background: rgba(255,255,255,0.03);
        font-size: 0.9rem;
        transition: 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .btn-archive:hover { color: #fff; }

    .stage-section { margin-bottom: 40px; animation: slideUp 0.5s ease-out; position: relative; z-index: 1; }
    @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .stage-header { 
        color: var(--ae-gold);
        font-size: 1.1rem;
        margin-bottom: 18px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 0 2px 12px;
        border-bottom: 1px solid rgba(212, 175, 55, 0.2);
    }
    .stage-header .badge { 
        background: rgba(212, 175, 55, 0.1);
        color: #fff;
        font-size: 0.8rem;
        padding: 4px 12px;
        border-radius: 999px;
        border: 1px solid var(--ae-gold);
    }

    .ph-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 30px; position: relative; z-index: 1; }
    .ph-card { 
        background: linear-gradient(180deg, rgba(21,21,21,0.94), rgba(12,12,12,0.92));
        border: 1px solid rgba(255,255,255,0.08); 
        border-radius: 24px;
        position: relative;
        overflow: hidden;
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
        padding: 22px;
        z-index: 1;
        box-shadow: 0 16px 34px rgba(0,0,0,0.22);
    }
    .ph-card::before {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(145deg, rgba(212,175,55,0.05), transparent 40%);
        pointer-events: none;
    }
    .ph-card:hover { transform: translateY(-5px); border-color: rgba(212, 175, 55, 0.4); box-shadow: 0 18px 42px rgba(0,0,0,0.32); }
    
    .ph-card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; }
    .ph-id { color: #818181; font-family: monospace; font-size: 0.85rem; letter-spacing: 1px; }
    .ph-status-pill { font-size: 0.72rem; padding: 4px 11px; border-radius: 999px; background: rgba(255,255,255,0.05); color: #aaa; }
    .ph-status-pill.late { color: #e74c3c; background: rgba(231,76,60,0.1); }
    .ph-status-pill.urgent { color: #f1c40f; }
    .ph-status-pill.normal { color: #2ecc71; }

    .ph-card-body { flex: 1; cursor: pointer; position: relative; }
    .ph-icon-float { position: absolute; left: 0; top: 0; font-size: 2.4rem; color: rgba(255,255,255,0.03); z-index: 0; transition: 0.3s; }
    .ph-card:hover .ph-icon-float { color: rgba(212, 175, 55, 0.1); transform: rotate(-8deg) scale(1.06); }
    .ph-card-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 12px;
        position: relative;
        z-index: 1;
    }
    .ph-meta-tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-height: 28px;
        padding: 0 10px;
        border-radius: 999px;
        background: rgba(255,255,255,0.04);
        border: 1px solid rgba(255,255,255,0.06);
        color: #dedede;
        font-size: 0.74rem;
        font-weight: 700;
    }
    .ph-meta-tag i { color: var(--ae-gold); }

    .ph-job-title { margin: 0 0 5px 0; font-size: 1.15rem; color: #fff; font-weight: 700; z-index: 1; position: relative; }
    .ph-client { color: #999; font-size: 0.85rem; margin-bottom: 20px; display: flex; align-items: center; gap: 6px; z-index: 1; position: relative; }
    .ph-stage-line {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 16px;
        position: relative;
        z-index: 1;
    }
    .ph-stage-badge {
        display: inline-flex;
        align-items: center;
        min-height: 30px;
        padding: 0 12px;
        border-radius: 999px;
        background: rgba(212,175,55,0.1);
        border: 1px solid rgba(212,175,55,0.24);
        color: #f4de93;
        font-size: 0.76rem;
        font-weight: 800;
    }
    .ph-stage-note {
        color: #8f8f8f;
        font-size: 0.78rem;
        font-weight: 700;
    }
    
    .ph-prog-wrapper { margin-top: auto; }
    .ph-prog-info { display: flex; justify-content: space-between; font-size: 0.75rem; color: #999; margin-bottom: 5px; }
    .ph-prog-track { height: 6px; background: #222; border-radius: 999px; overflow: hidden; }
    .ph-prog-fill { height: 100%; border-radius: 2px; transition: width 0.6s ease; }
    
    .ph-card-actions { 
        margin-top: 20px; padding-top: 15px; border-top: 1px dashed var(--border);
        display: flex; justify-content: space-between; align-items: center; 
    }
    .ph-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 5px;
        padding: 8px 15px; border-radius: 12px; font-size: 0.85rem; 
        text-decoration: none; transition: 0.2s; cursor: pointer;
    }
    .ph-btn-enter { background: var(--ae-gold); color: #000; font-weight: bold; border: none; }
    .ph-btn-enter:hover { box-shadow: 0 0 10px rgba(212,175,55,0.4); transform: translateY(-1px); }
    .ph-btn-icon { background: rgba(255,255,255,0.05); color: #888; border: 1px solid #333; padding: 8px; }
    .ph-btn-icon:hover { background: #fff; color: #000; }
    .ph-btn-del:hover { background: #e74c3c; color: #fff; border-color: #e74c3c; }
    .secondary-actions { display: flex; gap: 8px; }

    .ticker-bar { 
        min-height: 46px;
        overflow: hidden;
        margin-bottom: 0;
        display: flex; align-items: center; padding: 0 16px; 
        position: relative;
        border-radius: 18px;
    }
    .ticker-content { display: flex; gap: 28px; animation: scrollTicker 30s linear infinite; white-space: nowrap; }
    .ticker-bar:hover .ticker-content { animation-play-state: paused; }
    
    @keyframes scrollTicker { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }
    .ticker-item { display: flex; align-items: center; gap: 10px; color: #ccc; font-size: 0.78rem; font-weight: 700; }
    .ticker-item .dot { width: 6px; height: 6px; border-radius: 50%; }
    .ticker-item a { color: var(--ae-gold); text-decoration: none; }
    #live-grid {
        position: relative;
        z-index: 1;
    }
    .dash-pagination-wrap {
        margin-top: 18px;
        padding: 14px;
        border: 1px solid var(--border);
        border-radius: 18px;
        background: rgba(20, 20, 20, 0.7);
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        align-items: center;
        justify-content: space-between;
    }
    .dash-pagination-meta { color: #9f9f9f; font-size: 0.85rem; }
    .dash-pagination-links { display: flex; gap: 8px; flex-wrap: wrap; }
    .dash-page-link {
        min-width: 36px;
        text-align: center;
        padding: 8px 10px;
        border-radius: 8px;
        border: 1px solid #333;
        color: #d2d2d2;
        background: #111;
        text-decoration: none;
        font-size: 0.85rem;
    }
    .dash-page-link:hover { border-color: var(--ae-gold); color: var(--ae-gold); }
    .dash-page-link.active {
        background: var(--ae-gold);
        color: #000;
        border-color: var(--ae-gold);
        font-weight: 800;
    }

    .ae-mobile-lite .dashboard-intro {
        grid-template-columns: 1fr;
    }
    .ae-mobile-lite .charts-container { display: none; }
    .ae-mobile-lite .chart-card,
    .ae-mobile-lite .ph-card,
    .ae-mobile-lite .ticker-bar {
        backdrop-filter: none !important;
        box-shadow: none !important;
    }
    .ae-mobile-lite .stage-section,
    .ae-mobile-lite .ticker-content {
        animation: none !important;
    }
    @keyframes ae-dashboard-sweep {
        0%, 42% { transform: translateX(-32%) rotate(8deg); opacity: 0; }
        54% { opacity: .72; }
        100% { transform: translateX(32%) rotate(8deg); opacity: 0; }
    }
    @keyframes ae-live-pulse {
        0% { box-shadow: 0 0 0 0 rgba(103,224,143,.48); }
        70% { box-shadow: 0 0 0 12px rgba(103,224,143,0); }
        100% { box-shadow: 0 0 0 0 rgba(103,224,143,0); }
    }
    @keyframes ae-soft-rise {
        from { opacity: 0; transform: translateY(12px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .dashboard-shell > * {
        animation: ae-soft-rise .55s ease both;
    }
    .dashboard-shell > *:nth-child(2) { animation-delay: .05s; }
    .dashboard-shell > *:nth-child(3) { animation-delay: .1s; }
    .dashboard-shell > *:nth-child(4) { animation-delay: .15s; }
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            animation-duration: .001ms !important;
            animation-iteration-count: 1 !important;
            scroll-behavior: auto !important;
        }
    }
    .ae-mobile-lite .ph-card,
    .ae-mobile-lite .ph-btn,
    .ae-mobile-lite .ph-btn-enter,
    .ae-mobile-lite .ph-icon-float {
        transition: none !important;
    }
    .ae-mobile-lite .ph-card:hover,
    .ae-mobile-lite .ph-btn-enter:hover,
    .ae-mobile-lite .ph-icon-float,
    .ae-mobile-lite .ph-card:hover .ph-icon-float {
        transform: none !important;
        box-shadow: none !important;
    }
    .ae-mobile-lite .ticker-bar {
        height: auto;
        border-radius: 14px;
        padding: 8px 10px;
    }
    .ae-mobile-lite .ticker-content {
        white-space: normal;
        gap: 8px;
        width: 100%;
        flex-direction: column;
        transform: none !important;
    }

    @media (max-width: 1120px) {
        .dashboard-intro {
            grid-template-columns: 1fr;
        }
        .dashboard-ribbon,
        .dashboard-insights.has-owner-kpis {
            grid-template-columns: 1fr;
        }
        .ph-hero {
            grid-template-columns: 1fr;
        }
        .ph-hero-side {
            min-width: 0;
        }
    }
    @media (max-width: 768px) {
        .ph-filters { flex-direction: column; align-items: stretch; }
        .charts-container { grid-template-columns: 1fr; }
        .chart-card { height: 250px; }
        .ph-hero {
            padding: 20px;
            border-radius: 22px;
        }
        .ph-welcome h2 {
            font-size: 1.5rem;
        }
        .dashboard-alert-row {
            flex-direction: column;
            align-items: stretch;
        }
        .dashboard-ribbon {
            gap: 10px;
        }
    }
    @media (max-width: 560px) {
        .dashboard-shell {
            padding-inline: 10px;
        }
        .dashboard-card,
        .ph-card,
        .chart-card {
            border-radius: 18px;
        }
        .dashboard-intro,
        .charts-container,
        .ph-grid {
            gap: 12px;
        }
        .ph-grid {
            grid-template-columns: 1fr;
        }
        .ph-hero {
            padding: 16px;
            border-radius: 18px;
            gap: 14px;
        }
        .ph-user {
            gap: 10px;
        }
        .ph-avatar {
            width: 52px;
            height: 52px;
            border-radius: 18px;
        }
        .ph-welcome h2 {
            font-size: 1.22rem;
            line-height: 1.4;
        }
        .ph-subtitle {
            font-size: 0.84rem;
            line-height: 1.65;
        }
        .ph-meta-row,
        .ph-actions {
            gap: 8px;
        }
        .ph-meta-chip,
        .hero-btn {
            width: 100%;
            justify-content: center;
        }
        .ph-kpi {
            padding: 14px;
            border-radius: 18px;
        }
        .ph-num {
            font-size: 1.9rem;
        }
        .chart-card {
            height: 220px;
            padding: 14px;
        }
    }
</style>

<div class="container dashboard-shell">
    <div class="dashboard-ribbon">
        <div id="live-ticker" class="ticker-bar dashboard-card"></div>
    </div>

    <?php 
    $n_quotes = $conn->query("SELECT id FROM quotes WHERE total_amount=0 AND status='pending'");
    $n_orders = $conn->query("SELECT id, job_name FROM job_orders WHERE ($jobsVisibilityClauseRoot) AND client_id != 0 AND COALESCE(NULLIF(status, ''), 'pending') = 'pending'");
    $dueInvoices = false;
    $dueInvoicesCount = 0;
    $dueInvoicesOverdueCount = 0;
    $dueInvoicesCriticalCount = 0;
    $dueInvoicesTodayCount = 0;
    $dueInvoicesSoonCount = 0;
    if ($can_view_invoices) {
        $dueInvoices = $conn->query("
            SELECT i.id, i.invoice_number, i.due_date, i.remaining_amount, c.name AS client_name,
                   CASE
                       WHEN DATE(i.due_date) <= DATE_SUB(CURDATE(), INTERVAL 3 DAY) THEN 'critical'
                       WHEN DATE(i.due_date) < CURDATE() THEN 'overdue'
                       WHEN DATE(i.due_date) = CURDATE() THEN 'today'
                       ELSE 'soon'
                   END AS due_state
            FROM invoices i
            LEFT JOIN clients c ON c.id = i.client_id
            WHERE DATE(i.due_date) <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
              AND IFNULL(i.remaining_amount, 0) > 0.009
              AND COALESCE(NULLIF(i.status, ''), 'unpaid') NOT IN ('paid', 'cancelled')
            ORDER BY i.due_date ASC, i.id DESC
            LIMIT 8
        ");
        if ($dueInvoices) {
            $dueInvoiceRows = [];
            while ($row = $dueInvoices->fetch_assoc()) {
                $dueState = (string)($row['due_state'] ?? 'soon');
                if ($dueState === 'critical') {
                    $dueInvoicesCriticalCount++;
                    $dueInvoicesOverdueCount++;
                } elseif ($dueState === 'overdue') {
                    $dueInvoicesOverdueCount++;
                } elseif ($dueState === 'today') {
                    $dueInvoicesTodayCount++;
                } else {
                    $dueInvoicesSoonCount++;
                }
                $dueInvoiceRows[] = $row;
            }
            $dueInvoicesCount = count($dueInvoiceRows);
            $dueInvoices = $dueInvoiceRows;
        }
    }
    $nQuotesCount = $n_quotes ? (int)$n_quotes->num_rows : 0;
    $nOrdersCount = $n_orders ? (int)$n_orders->num_rows : 0;
    $support_dashboard_enabled = $is_admin;
    $supportUnreadKey = app_license_edition() === 'owner' ? 'unread_for_admin' : 'unread_for_client';
    $support_attention = [];
    $support_unread_admin = 0;
    if ($support_dashboard_enabled) {
        try {
            $support_attention = app_support_admin_attention_tickets($conn, 6);
            $support_unread_admin = app_support_admin_unread_messages_count($conn);
        } catch (Throwable $e) {
            $support_attention = [];
            $support_unread_admin = 0;
            error_log('dashboard support widgets failed: ' . $e->getMessage());
        }
    }
    if ($nQuotesCount > 0 || $nOrdersCount > 0 || $dueInvoicesCount > 0 || !empty($support_attention)): 
    ?>
    <div class="dashboard-alerts dashboard-card">
        <?php if ($dueInvoicesCriticalCount > 0): ?>
            <div class="critical-overdue-alert pulse" id="critical-overdue-alert">
                <div>
                    <div class="title"><i class="fa-solid fa-siren-on"></i> <?php echo app_h($tr('تنبيه عاجل: فواتير متأخرة جدًا', 'Urgent alert: critically overdue invoices')); ?></div>
                    <div class="meta"><?php echo (int)$dueInvoicesCriticalCount; ?> <?php echo app_h($tr('فاتورة تجاوزت الاستحقاق بأكثر من 3 أيام', 'invoice(s) overdue by more than 3 days')); ?></div>
                </div>
                <a href="invoices.php?tab=sales&amp;due_filter=overdue" class="ph-btn ph-btn-enter" style="font-size:0.82rem; padding:6px 12px;">
                    <?php echo app_h($tr('عرض المتأخرات', 'View overdue')); ?>
                </a>
            </div>
        <?php endif; ?>
        <h3 class="dashboard-alert-head">
            <span class="dashboard-alert-headline">
                <i class="fa-solid fa-bell"></i> <?php echo app_h($tr('تنبيهات الإدارة', 'Management Alerts')); ?>
            </span>
            <?php if ($dueInvoicesCount > 0): ?>
                <span class="dashboard-alert-badges">
                    <span class="dashboard-alert-count">
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                        <?php echo (int)$dueInvoicesCount; ?> <?php echo app_h($tr('تنبيهات استحقاق', 'due alerts')); ?>
                    </span>
                    <?php if ($dueInvoicesOverdueCount > 0): ?>
                        <span class="dashboard-alert-badge overdue"><i class="fa-solid fa-triangle-exclamation"></i><?php echo (int)$dueInvoicesOverdueCount; ?> <?php echo app_h($tr('متأخر', 'Overdue')); ?></span>
                    <?php endif; ?>
                    <?php if ($dueInvoicesTodayCount > 0): ?>
                        <span class="dashboard-alert-badge today"><i class="fa-solid fa-calendar-day"></i><?php echo (int)$dueInvoicesTodayCount; ?> <?php echo app_h($tr('اليوم', 'Today')); ?></span>
                    <?php endif; ?>
                    <?php if ($dueInvoicesSoonCount > 0): ?>
                        <span class="dashboard-alert-badge soon"><i class="fa-solid fa-clock"></i><?php echo (int)$dueInvoicesSoonCount; ?> <?php echo app_h($tr('خلال 3 أيام', 'Within 3 days')); ?></span>
                    <?php endif; ?>
                </span>
            <?php elseif ($support_dashboard_enabled && $support_unread_admin > 0): ?>
                <span class="dashboard-alert-count">
                    <i class="fa-solid fa-headset"></i>
                    <?php echo (int)$support_unread_admin; ?> <?php echo app_h($tr('رسائل دعم جديدة', 'new support messages')); ?>
                </span>
            <?php endif; ?>
        </h3>
        <div class="dashboard-alert-list">
            <?php while($q = $n_quotes->fetch_assoc()): ?>
            <?php $quotePreviewLink = app_quote_view_link($conn, $q); ?>
            <div class="dashboard-alert-row">
                <span style="font-size:0.9rem;"><?php echo app_h($tr('طلب تسعير', 'Quotation Request')); ?> #<?php echo $q['id']; ?></span>
                <a href="<?php echo app_h($quotePreviewLink); ?>" class="ph-btn ph-btn-enter" style="font-size:0.8rem; padding:5px 10px;"><?php echo app_h($tr('معاينة', 'Preview')); ?></a>
            </div>
            <?php endwhile; ?>
            <?php while($o = $n_orders->fetch_assoc()): ?>
            <div class="dashboard-alert-row">
                <span style="font-size:0.9rem;"><?php echo app_h($tr('أمر شغل', 'Work Order')); ?> #<?php echo $o['id']; ?></span>
                <a href="dashboard.php?action=accept&type=order&id=<?php echo (int)$o['id']; ?>&amp;_token=<?php echo urlencode($csrfToken); ?>" class="ph-btn ph-btn-enter" style="font-size:0.8rem; padding:5px 10px;"><?php echo app_h($tr('اعتماد', 'Approve')); ?></a>
            </div>
            <?php endwhile; ?>
            <?php if ($dueInvoicesCount > 0): ?>
                <div class="dashboard-alert-row ticket">
                    <span style="font-size:0.92rem;">
                        <i class="fa-solid fa-triangle-exclamation" style="color:#ff8f8f;"></i>
                        <?php echo app_h($tr('فواتير متأخرة', 'Overdue invoices')); ?>:
                        <strong><?php echo (int)$dueInvoicesOverdueCount; ?></strong>
                    </span>
                    <a href="invoices.php?tab=sales&amp;due_filter=overdue" class="ph-btn ph-btn-enter" style="font-size:0.8rem; padding:5px 10px;"><?php echo app_h($tr('فتح القائمة', 'Open list')); ?></a>
                </div>
                <div class="dashboard-alert-row ticket">
                    <span style="font-size:0.92rem;">
                        <i class="fa-solid fa-calendar-day" style="color:#ffe08a;"></i>
                        <?php echo app_h($tr('فواتير مستحقة اليوم', 'Invoices due today')); ?>:
                        <strong><?php echo (int)$dueInvoicesTodayCount; ?></strong>
                    </span>
                    <a href="invoices.php?tab=sales" class="ph-btn ph-btn-enter" style="font-size:0.8rem; padding:5px 10px;"><?php echo app_h($tr('فتح القائمة', 'Open list')); ?></a>
                </div>
                <div class="dashboard-alert-row ticket">
                    <span style="font-size:0.92rem;">
                        <i class="fa-solid fa-clock" style="color:#9fd6ff;"></i>
                        <?php echo app_h($tr('فواتير خلال 3 أيام', 'Invoices within 3 days')); ?>:
                        <strong><?php echo (int)$dueInvoicesSoonCount; ?></strong>
                    </span>
                    <a href="invoices.php?tab=sales" class="ph-btn ph-btn-enter" style="font-size:0.8rem; padding:5px 10px;"><?php echo app_h($tr('فتح القائمة', 'Open list')); ?></a>
                </div>
            <?php endif; ?>
            <?php if ($support_dashboard_enabled): ?>
                <?php foreach ($support_attention as $st): ?>
                    <?php
                        $ticketId = (int)($st['id'] ?? 0);
                        $ticketUnread = (int)($st[$supportUnreadKey] ?? 0);
                        $ticketStatus = (string)($st['status'] ?? 'open');
                        $ticketSubject = trim((string)($st['subject'] ?? ''));
                    ?>
                    <div class="dashboard-alert-row ticket">
                        <span style="font-size:0.9rem;">
                            <i class="fa-solid fa-headset" style="color:var(--ae-gold);"></i>
                            <?php echo app_h($tr('تذكرة دعم', 'Support Ticket')); ?> #<?php echo $ticketId; ?> -
                            <?php echo app_h($ticketSubject !== '' ? $ticketSubject : $tr('بدون عنوان', 'No subject')); ?>
                            <?php if ($ticketUnread > 0): ?>
                                <strong style="color:#6ef0a0;">(<?php echo $ticketUnread; ?>)</strong>
                            <?php endif; ?>
                            <span style="color:#9a9a9a;">[<?php echo app_h($ticketStatus); ?>]</span>
                        </span>
                        <a href="license_center.php?ticket=<?php echo $ticketId; ?>" class="ph-btn ph-btn-enter" style="font-size:0.8rem; padding:5px 10px;">
                            <?php echo app_h($tr('فتح التذكرة', 'Open Ticket')); ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="ph-hero">
        <div class="ph-user">
            <?php $u_img = $_SESSION['avatar'] ?? "https://ui-avatars.com/api/?name=$my_name&background=random&color=fff"; ?>
            <img src="<?php echo $u_img; ?>" class="ph-avatar">
            <div class="ph-welcome">
                <div class="ph-date">
                    <?php echo date('d M, Y'); ?>
                </div>
                <h2><?php echo app_h($tr('لوحة العمليات', 'Operations Dashboard')); ?></h2>
                <div class="ph-subtitle"><?php echo app_h($my_name); ?></div>
                <div class="ph-meta-row">
                    <span class="ph-meta-chip"><i class="fa-solid fa-wave-square"></i> <?php echo app_h($dashboardCurrentStatusLabel); ?></span>
                    <span class="ph-meta-chip"><i class="fa-solid fa-layer-group"></i> <?php echo app_h($dashboardCurrentTypeLabel); ?></span>
                    <span class="ph-meta-chip"><i class="fa-solid fa-grid-2"></i> <?php echo count($dashboardTypeMap); ?> <?php echo app_h($tr('أقسام', 'Departments')); ?></span>
                    <span class="ph-meta-chip"><i class="fa-solid fa-file-signature"></i> <?php echo $nQuotesCount; ?> <?php echo app_h($tr('طلبات تسعير', 'Quote Requests')); ?></span>
                    <span class="ph-meta-chip"><i class="fa-solid fa-diagram-project"></i> <?php echo $nOrdersCount; ?> <?php echo app_h($tr('اعتمادات معلقة', 'Pending Approvals')); ?></span>
                </div>
            </div>
        </div>
        <div class="ph-hero-side">
            <div class="ph-kpi" id="live-stats">
                <div class="ph-num">--</div>
                <div class="ph-lbl"><?php echo app_h($tr('عملية نشطة', 'Active Jobs')); ?></div>
            </div>
            <div class="ph-actions">
                <?php if($can_create_job): ?>
                    <a href="add_job.php" class="hero-btn primary"><i class="fa-solid fa-plus"></i> <?php echo app_h($tr('إنشاء عملية', 'Create Job')); ?></a>
                <?php endif; ?>
                <a href="<?php echo app_h($archiveUrl); ?>" class="hero-btn secondary"><i class="fa-solid fa-box-archive"></i> <?php echo app_h($tr('فتح الأرشيف', 'Open Archive')); ?></a>
            </div>
        </div>
    </div>

    <form method="GET" class="ph-filters">
        <select name="status" class="ph-select" onchange="this.form.submit()">
            <option value="active" <?php echo ($_GET['status']??'active')=='active'?'selected':''; ?>><?php echo app_h($tr('العمليات الجارية', 'Active Jobs')); ?></option>
            <option value="late" <?php echo ($_GET['status']??'')=='late'?'selected':''; ?>><?php echo app_h($tr('المتأخرة', 'Late Jobs')); ?></option>
            <option value="all" <?php echo ($_GET['status']??'')=='all'?'selected':''; ?>><?php echo app_h($tr('الكل', 'All')); ?></option>
        </select>
        <select name="type" class="ph-select" onchange="this.form.submit()">
            <option value="all"><?php echo app_h($tr('كل الأقسام', 'All Departments')); ?></option>
            <?php foreach ($dashboardTypeMap as $typeKey => $typeName): ?>
                <option value="<?php echo app_h($typeKey); ?>" <?php echo ($_GET['type'] ?? '') === $typeKey ? 'selected' : ''; ?>>
                    <?php echo app_h($typeName); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="q" class="ph-search" placeholder="<?php echo app_h($tr('بحث...', 'Search...')); ?>" value="<?php echo htmlspecialchars($search_query ?? ''); ?>">
        <?php if($can_create_job): ?>
            <a href="add_job.php" class="btn-add"><i class="fa-solid fa-plus"></i> <?php echo app_h($tr('عملية جديدة', 'New Job')); ?></a>
        <?php endif; ?>
        <a href="<?php echo app_h($archiveUrl); ?>" class="btn-archive" style="<?php echo (($_GET['status'] ?? '') === 'completed') ? 'color:var(--ae-gold);font-weight:700;' : ''; ?>">
            <i class="fa-solid fa-box-archive"></i> <?php echo app_h($tr('الأرشيف', 'Archive')); ?>
        </a>
    </form>

    <div class="dashboard-insights <?php echo $show_super_user_kpis ? 'has-owner-kpis' : ''; ?>">
        <?php if ($show_super_user_kpis): ?>
        <div class="su-kpis dashboard-card">
            <div class="su-kpis-head">
                <span><?php echo app_h($tr('مؤشرات النظام', 'System Metrics')); ?></span>
                <i class="fa-solid fa-chart-line"></i>
            </div>
            <div class="su-kpis-grid">
                <div class="su-kpi">
                    <span class="icon"><i class="fa-solid fa-headset"></i></span>
                    <div class="su-kpi-main">
                        <div class="k"><?php echo app_h($tr('تذاكر الدعم المفتوحة', 'Open Support Tickets')); ?></div>
                    </div>
                    <div class="v" id="su-open-tickets"><?php echo (int)$super_open_tickets_count; ?></div>
                </div>
                <div class="su-kpi">
                    <span class="icon"><i class="fa-solid fa-signal"></i></span>
                    <div class="su-kpi-main">
                        <div class="k"><?php echo app_h($tr('الأنظمة الفعّالة', 'Active Systems')); ?></div>
                    </div>
                    <div class="v" id="su-active-systems"><?php echo (int)$super_active_systems_count; ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="charts-container">
            <div class="chart-card dashboard-card">
                <h3><?php echo app_h($tr('توزيع العمليات حسب القسم', 'Jobs by Department')); ?></h3>
                <div style="width: 100%; height: 100%; display: flex; justify-content: center; align-items: center;">
                    <canvas id="jobsTypeChart"></canvas>
                </div>
            </div>
            <div class="chart-card dashboard-card">
                <h3><?php echo app_h($tr('حالة التشغيل العام', 'Overall Run Status')); ?></h3>
                <div style="width: 100%; height: 100%; display: flex; justify-content: center; align-items: center;">
                    <canvas id="jobsStatusChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div id="live-grid">
        <div style="grid-column:1/-1; text-align:center; padding:80px 0; color:#444;">
            <i class="fa-solid fa-circle-notch fa-spin fa-2x"></i><br><?php echo app_h($tr('تحميل البيانات', 'Loading data')); ?>
        </div>
    </div>
    <div id="live-pagination"></div>

</div>

<script>
    const currentParams = new URLSearchParams(window.location.search);
    const isMobileLite = window.matchMedia("(max-width: 900px)").matches;
    const dashboardCsrfToken = <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>;
    const txtRejectPrompt = <?php echo json_encode($tr('ما هو سبب الرفض؟', 'What is the rejection reason?'), JSON_UNESCAPED_UNICODE); ?>;
    const txtRejectDefault = <?php echo json_encode($tr('لم يتم ذكر سبب محدد', 'No specific reason provided'), JSON_UNESCAPED_UNICODE); ?>;
    const txtChartActive = <?php echo json_encode($tr('جارية', 'Active'), JSON_UNESCAPED_UNICODE); ?>;
    const txtChartCompleted = <?php echo json_encode($tr('مكتملة', 'Completed'), JSON_UNESCAPED_UNICODE); ?>;
    const txtChartCount = <?php echo json_encode($tr('العدد', 'Count'), JSON_UNESCAPED_UNICODE); ?>;
    const txtNotificationNew = <?php echo json_encode($tr('🚀 عملية جديدة', '🚀 New Job'), JSON_UNESCAPED_UNICODE); ?>;
    const criticalOverdueCount = <?php echo (int)$dueInvoicesCriticalCount; ?>;
    const dashboardPollMs = isMobileLite ? 90000 : 45000;
    const liveUpdatesUrl = `dashboard.php?live_updates=1${isMobileLite ? "&lite=1" : ""}&`;
    let lastJobId = -1;
    let lastReviewId = -1;
    let currentTickerHTML = ""; // متغير لتخزين محتوى التيكر الحالي
    let currentGridHTML = "";
    let currentPaginationHTML = "";
    let currentActiveJobs = null;
    let fetchInFlight = false;
    let dashboardTimer = null;
    let currentTypeChartKey = "";
    let currentStatusChartKey = "";
    let typeChart, statusChart;

    if (document.body) {
        document.body.classList.toggle("ae-mobile-lite", isMobileLite);
    }

    if ("Notification" in window && Notification.permission !== "granted") {
        Notification.requestPermission();
    }

    function playCriticalBeep() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const audioCtx = new AudioCtx();
            const now = audioCtx.currentTime;
            for (let i = 0; i < 2; i++) {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = "sine";
                osc.frequency.value = 880;
                gain.gain.setValueAtTime(0.0001, now + (i * 0.32));
                gain.gain.exponentialRampToValueAtTime(0.08, now + (i * 0.32) + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + (i * 0.32) + 0.18);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start(now + (i * 0.32));
                osc.stop(now + (i * 0.32) + 0.2);
            }
        } catch (e) {}
    }

    function rejectItem(type, id) {
        let reason = prompt(txtRejectPrompt);
        if (reason !== null) {
            if(reason.trim() === "") reason = txtRejectDefault;
            window.location.href = `dashboard.php?action=reject&type=${type}&id=${id}&reason=${encodeURIComponent(reason)}&_token=${encodeURIComponent(dashboardCsrfToken)}`;
        }
    }

    function initCharts() {
        if (isMobileLite || typeof Chart === "undefined") return;
        const typeCanvas = document.getElementById('jobsTypeChart');
        const statusCanvas = document.getElementById('jobsStatusChart');
        if (!typeCanvas || !statusCanvas) return;
        const ctxType = typeCanvas.getContext('2d');
        const ctxStatus = statusCanvas.getContext('2d');
        if (!ctxType || !ctxStatus) return;
        Chart.defaults.color = '#777';
        Chart.defaults.font.family = 'Cairo';

        typeChart = new Chart(ctxType, {
            type: 'doughnut',
            data: {
                labels: [],
                datasets: [{
                    data: [],
                    backgroundColor: ['#d4af37', '#e74c3c', '#3498db', '#9b59b6', '#2ecc71', '#e67e22'],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: { 
                responsive: true, maintainAspectRatio: false, cutout: '75%', 
                plugins: { legend: { position: 'right', labels: { boxWidth: 10, usePointStyle: true } } } 
            }
        });

        statusChart = new Chart(ctxStatus, {
            type: 'bar',
            data: {
                labels: [txtChartActive, txtChartCompleted],
                datasets: [{
                    label: txtChartCount,
                    data: [0, 0],
                    backgroundColor: ['#d4af37', '#2ecc71'],
                    borderRadius: 10, barThickness: 40
                }]
            },
            options: { 
                responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, border: { display: false } }, x: { grid: { display: false }, border: { display: false } } } 
            }
        });
    }

    function updateCharts(data) {
        if (!typeChart || !statusChart) return;
        if(!data.stats.chart_types || !data.stats.chart_status) return;
        const nextTypeLabels = Object.keys(data.stats.chart_types);
        const nextTypeValues = Object.values(data.stats.chart_types);
        const nextTypeKey = JSON.stringify([nextTypeLabels, nextTypeValues]);
        if (nextTypeKey !== currentTypeChartKey) {
            typeChart.data.labels = nextTypeLabels;
            typeChart.data.datasets[0].data = nextTypeValues;
            typeChart.update('none');
            currentTypeChartKey = nextTypeKey;
        }

        const nextStatusValues = [data.stats.chart_status.active, data.stats.chart_status.completed];
        const nextStatusKey = JSON.stringify(nextStatusValues);
        if (nextStatusKey !== currentStatusChartKey) {
            statusChart.data.datasets[0].data = nextStatusValues;
            statusChart.update('none');
            currentStatusChartKey = nextStatusKey;
        }
    }

    function loadChartLibrary() {
        if (isMobileLite) return Promise.resolve(false);
        if (typeof Chart !== "undefined") return Promise.resolve(true);
        return new Promise((resolve) => {
            const script = document.createElement("script");
            script.src = "https://cdn.jsdelivr.net/npm/chart.js";
            script.async = true;
            script.onload = () => resolve(true);
            script.onerror = () => resolve(false);
            document.head.appendChild(script);
        });
    }

    function fetchUpdates() {
        if (fetchInFlight || document.visibilityState === "hidden") return;
        fetchInFlight = true;
        fetch(liveUpdatesUrl + currentParams.toString())
            .then(r => r.json())
            .then(data => {
                const activeEl = document.querySelector('#live-stats .ph-num');
                const nextActive = Number(data && data.stats ? data.stats.active : 0);
                if (activeEl && nextActive !== currentActiveJobs) {
                    activeEl.textContent = String(nextActive);
                    currentActiveJobs = nextActive;
                }

                const suOpenEl = document.getElementById('su-open-tickets');
                if (suOpenEl && data && data.stats && Number.isFinite(Number(data.stats.super_open_tickets))) {
                    suOpenEl.textContent = String(Number(data.stats.super_open_tickets));
                }
                const suActiveEl = document.getElementById('su-active-systems');
                if (suActiveEl && data && data.stats && Number.isFinite(Number(data.stats.super_active_systems))) {
                    suActiveEl.textContent = String(Number(data.stats.super_active_systems));
                }

                if (typeof data.grid === "string" && data.grid !== currentGridHTML) {
                    document.getElementById('live-grid').innerHTML = data.grid;
                    currentGridHTML = data.grid;
                }

                const nextPagination = typeof data.pagination === "string" ? data.pagination : "";
                if (nextPagination !== currentPaginationHTML) {
                    document.getElementById('live-pagination').innerHTML = nextPagination;
                    currentPaginationHTML = nextPagination;
                }

                // --- إصلاح التيكر: التحديث فقط عند وجود تغيير حقيقي ---
                const nextTickerRaw = typeof data.ticker === "string" ? data.ticker : "";
                const nextTicker = nextTickerRaw.trim();
                if (nextTicker !== currentTickerHTML) {
                    document.getElementById('live-ticker').innerHTML = nextTickerRaw;
                    currentTickerHTML = nextTicker;
                }

                updateCharts(data);

                if(data.last_job && data.last_job.id) {
                    let newId = parseInt(data.last_job.id);
                    if(lastJobId !== -1 && newId > lastJobId) {
                        try{ new Notification(txtNotificationNew, { body: data.last_job.job_name, icon: 'assets/img/icon-192x192.png' }); }catch(e){}
                    }
                    lastJobId = newId;
                }
            })
            .catch(() => { console.log("Connection paused..."); })
            .finally(() => { fetchInFlight = false; });
    }

    function stopDashboardPolling() {
        if (dashboardTimer) {
            window.clearInterval(dashboardTimer);
            dashboardTimer = null;
        }
    }

    function startDashboardPolling() {
        stopDashboardPolling();
        if (document.visibilityState === "hidden") {
            return;
        }
        dashboardTimer = window.setInterval(() => {
            fetchUpdates();
        }, dashboardPollMs);
    }

    async function startDashboardLive() {
        await loadChartLibrary();
        initCharts();
        if (criticalOverdueCount > 0) {
            window.setTimeout(() => {
                playCriticalBeep();
            }, 500);
        }
        fetchUpdates();
        startDashboardPolling();
    }

    startDashboardLive();
    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") {
            fetchUpdates();
            startDashboardPolling();
        } else {
            stopDashboardPolling();
        }
    });
</script>

<?php include 'footer.php'; ob_end_flush(); ?>
