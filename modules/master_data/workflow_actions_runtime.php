<?php

if (!function_exists('md_handle_workflow_actions')) {
    function md_handle_workflow_actions(mysqli $conn, string $action, array &$state): bool
    {
        if (!in_array($action, [
            'save_type',
            'save_type_smart',
            'toggle_type',
            'delete_type',
            'save_stage',
            'toggle_stage',
            'delete_stage',
            'move_stage',
            'save_catalog_item',
            'toggle_catalog_item',
            'delete_catalog_item',
            'move_catalog_item',
            'move_type',
            'delete_number_rule',
        ], true)) {
            return false;
        }

        if ($action === 'save_type') {
            $typeKey = strtolower(trim((string)($_POST['type_key'] ?? '')));
            $typeName = trim((string)($_POST['type_name'] ?? ''));
            $typeNameEn = trim((string)($_POST['type_name_en'] ?? ''));
            $iconClass = trim((string)($_POST['icon_class'] ?? 'fa-circle'));
            $defaultStage = strtolower(trim((string)($_POST['default_stage_key'] ?? 'briefing')));
            $sortOrder = (int)($_POST['sort_order'] ?? 100);
            if (!preg_match('/^[a-z0-9_]{2,50}$/', $typeKey) || $typeName === '') {
                throw new RuntimeException('بيانات نوع العملية غير مكتملة.');
            }
            if ($typeNameEn === '') {
                $typeNameEn = $typeName;
            }
            $stmt = $conn->prepare("
                INSERT INTO app_operation_types (type_key, type_name, type_name_en, icon_class, default_stage_key, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    type_name = VALUES(type_name),
                    type_name_en = VALUES(type_name_en),
                    icon_class = VALUES(icon_class),
                    default_stage_key = VALUES(default_stage_key),
                    sort_order = VALUES(sort_order)
            ");
            $stmt->bind_param('sssssi', $typeKey, $typeName, $typeNameEn, $iconClass, $defaultStage, $sortOrder);
            $stmt->execute();
            $stmt->close();
            $state['msg'] = 'تم حفظ نوع العملية.';
            return true;
        }

        if ($action === 'save_type_smart') {
            $typeKey = strtolower(trim((string)($_POST['smart_type_key'] ?? '')));
            $typeName = trim((string)($_POST['smart_type_name'] ?? ''));
            $typeNameEn = trim((string)($_POST['smart_type_name_en'] ?? ''));
            $iconClass = trim((string)($_POST['smart_icon_class'] ?? 'fa-circle'));
            $sortOrder = (int)($_POST['smart_sort_order'] ?? 100);
            $selectedStages = md_string_list($_POST['smart_stage_keys'] ?? [], true, 30, 60);
            $stageTemplates = md_stage_template_definitions();

            if (!preg_match('/^[a-z0-9_]{2,50}$/', $typeKey) || $typeName === '' || $typeNameEn === '') {
                throw new RuntimeException('بيانات النشاط الجديد غير مكتملة (المفتاح + اسم عربي + اسم إنجليزي).');
            }
            if (empty($selectedStages)) {
                throw new RuntimeException('اختر مرحلة واحدة على الأقل قبل الحفظ الذكي.');
            }

            $orderedStageKeys = [];
            foreach ($stageTemplates as $stageKey => $_stageDef) {
                if (in_array($stageKey, $selectedStages, true)) {
                    $orderedStageKeys[] = $stageKey;
                }
            }
            if (empty($orderedStageKeys)) {
                throw new RuntimeException('المراحل المختارة غير صالحة.');
            }

            $defaultStage = (string)$orderedStageKeys[0];
            $stmtType = $conn->prepare("
                INSERT INTO app_operation_types (type_key, type_name, type_name_en, icon_class, default_stage_key, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    type_name = VALUES(type_name),
                    type_name_en = VALUES(type_name_en),
                    icon_class = VALUES(icon_class),
                    default_stage_key = VALUES(default_stage_key),
                    sort_order = VALUES(sort_order),
                    is_active = 1
            ");
            $stmtType->bind_param('sssssi', $typeKey, $typeName, $typeNameEn, $iconClass, $defaultStage, $sortOrder);
            $stmtType->execute();
            $stmtType->close();

            $smartStageNameAr = is_array($_POST['smart_stage_name_ar'] ?? null) ? $_POST['smart_stage_name_ar'] : [];
            $smartStageNameEn = is_array($_POST['smart_stage_name_en'] ?? null) ? $_POST['smart_stage_name_en'] : [];
            $smartStageRequired = is_array($_POST['smart_stage_required_ops'] ?? null) ? $_POST['smart_stage_required_ops'] : [];
            $smartStageActions = is_array($_POST['smart_stage_actions'] ?? null) ? $_POST['smart_stage_actions'] : [];
            $smartStageTerminal = is_array($_POST['smart_stage_terminal'] ?? null) ? $_POST['smart_stage_terminal'] : [];

            $stmtStage = $conn->prepare("
                INSERT INTO app_operation_stages (
                    type_key,
                    stage_key,
                    stage_name,
                    stage_name_en,
                    stage_order,
                    default_stage_cost,
                    stage_actions_json,
                    stage_required_ops_json,
                    is_active,
                    is_terminal
                )
                VALUES (?, ?, ?, ?, ?, 0.00, ?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE
                    stage_name = VALUES(stage_name),
                    stage_name_en = VALUES(stage_name_en),
                    stage_order = VALUES(stage_order),
                    stage_actions_json = VALUES(stage_actions_json),
                    stage_required_ops_json = VALUES(stage_required_ops_json),
                    is_terminal = VALUES(is_terminal),
                    is_active = 1
            ");

            $order = 1;
            foreach ($orderedStageKeys as $stageKey) {
                $template = $stageTemplates[$stageKey] ?? null;
                if ($template === null) {
                    continue;
                }
                $stageName = trim((string)($smartStageNameAr[$stageKey] ?? $template['ar']));
                $stageNameEn = trim((string)($smartStageNameEn[$stageKey] ?? $template['en']));
                if ($stageName === '') {
                    $stageName = (string)$template['ar'];
                }
                if ($stageNameEn === '') {
                    $stageNameEn = (string)($template['en'] ?? $stageName);
                }
                $terminalRaw = (string)($smartStageTerminal[$stageKey] ?? (string)($template['terminal'] ?? 0));
                $isTerminal = $terminalRaw === '1' ? 1 : 0;

                $stageActions = md_normalize_stage_actions($smartStageActions[$stageKey] ?? ($template['actions'] ?? []));
                $requiredOps = md_string_list($smartStageRequired[$stageKey] ?? '', false, 120, 140);
                $stageActionsJson = md_json_encode_list($stageActions);
                $requiredOpsJson = md_json_encode_list($requiredOps);

                $stmtStage->bind_param(
                    'ssssissi',
                    $typeKey,
                    $stageKey,
                    $stageName,
                    $stageNameEn,
                    $order,
                    $stageActionsJson,
                    $requiredOpsJson,
                    $isTerminal
                );
                $stmtStage->execute();
                $order++;
            }
            $stmtStage->close();

            $state['scopeType'] = $typeKey;
            $state['activeTab'] = 'stages';
            $state['msg'] = 'تم إنشاء النشاط الذكي وحفظ المراحل المطلوبة. يمكنك الآن استكمال التعديل التفصيلي.';
            return true;
        }

        if ($action === 'toggle_type') {
            $typeKey = strtolower(trim((string)($_POST['type_key'] ?? '')));
            $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
            $stmt = $conn->prepare("UPDATE app_operation_types SET is_active = ? WHERE type_key = ?");
            $stmt->bind_param('is', $isActive, $typeKey);
            $stmt->execute();
            $stmt->close();
            $state['msg'] = 'تم تحديث حالة نوع العملية.';
            return true;
        }

        if ($action === 'delete_type') {
            $typeKey = strtolower(trim((string)($_POST['type_key'] ?? '')));
            if (!preg_match('/^[a-z0-9_]{2,50}$/', $typeKey)) {
                throw new RuntimeException('نوع العملية غير صالح للحذف.');
            }
            $stmt1 = $conn->prepare("DELETE FROM app_operation_stages WHERE type_key = ?");
            $stmt1->bind_param('s', $typeKey);
            $stmt1->execute();
            $stmt1->close();
            $stmt2 = $conn->prepare("DELETE FROM app_operation_catalog WHERE type_key = ?");
            $stmt2->bind_param('s', $typeKey);
            $stmt2->execute();
            $stmt2->close();
            $stmt3 = $conn->prepare("DELETE FROM app_operation_types WHERE type_key = ?");
            $stmt3->bind_param('s', $typeKey);
            $stmt3->execute();
            $stmt3->close();
            if (($state['scopeType'] ?? '') === $typeKey) {
                $state['scopeType'] = '';
            }
            $state['msg'] = 'تم حذف نوع العملية وكل ما يرتبط به.';
            return true;
        }

        if ($action === 'save_stage') {
            $stageId = (int)($_POST['stage_id'] ?? 0);
            $typeKey = strtolower(trim((string)($_POST['stage_type_key'] ?? '')));
            $stageKey = strtolower(trim((string)($_POST['stage_key'] ?? '')));
            $stageName = trim((string)($_POST['stage_name'] ?? ''));
            $stageNameEn = trim((string)($_POST['stage_name_en'] ?? ''));
            $stageOrder = (int)($_POST['stage_order'] ?? 1);
            $stageCost = (float)($_POST['default_stage_cost'] ?? 0);
            $isTerminal = (int)($_POST['is_terminal'] ?? 0) === 1 ? 1 : 0;
            $stageActions = md_normalize_stage_actions($_POST['stage_actions'] ?? []);
            $requiredOps = md_string_list((string)($_POST['stage_required_ops_text'] ?? ''), false, 120, 140);
            $stageActionsJson = md_json_encode_list($stageActions);
            $requiredOpsJson = md_json_encode_list($requiredOps);
            if (!preg_match('/^[a-z0-9_]{2,50}$/', $typeKey) || !preg_match('/^[a-z0-9_]{2,60}$/', $stageKey) || $stageName === '') {
                throw new RuntimeException('بيانات المرحلة غير صحيحة.');
            }
            if ($stageNameEn === '') {
                $stageNameEn = $stageName;
            }
            if ($stageId > 0) {
                $stmt = $conn->prepare("
                    UPDATE app_operation_stages
                    SET type_key = ?,
                        stage_key = ?,
                        stage_name = ?,
                        stage_name_en = ?,
                        stage_order = ?,
                        default_stage_cost = ?,
                        is_terminal = ?,
                        stage_actions_json = ?,
                        stage_required_ops_json = ?,
                        is_active = 1
                    WHERE id = ?
                ");
                $stmt->bind_param('ssssidissi', $typeKey, $stageKey, $stageName, $stageNameEn, $stageOrder, $stageCost, $isTerminal, $stageActionsJson, $requiredOpsJson, $stageId);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO app_operation_stages (
                        type_key,
                        stage_key,
                        stage_name,
                        stage_name_en,
                        stage_order,
                        default_stage_cost,
                        stage_actions_json,
                        stage_required_ops_json,
                        is_active,
                        is_terminal
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                    ON DUPLICATE KEY UPDATE
                        stage_name = VALUES(stage_name),
                        stage_name_en = VALUES(stage_name_en),
                        stage_order = VALUES(stage_order),
                        default_stage_cost = VALUES(default_stage_cost),
                        stage_actions_json = VALUES(stage_actions_json),
                        stage_required_ops_json = VALUES(stage_required_ops_json),
                        is_terminal = VALUES(is_terminal),
                        is_active = 1
                ");
                $stmt->bind_param('ssssidssi', $typeKey, $stageKey, $stageName, $stageNameEn, $stageOrder, $stageCost, $stageActionsJson, $requiredOpsJson, $isTerminal);
            }
            $stmt->execute();
            $stmt->close();
            $state['scopeType'] = $typeKey;
            $state['msg'] = 'تم حفظ المرحلة.';
            return true;
        }

        if ($action === 'toggle_stage') {
            $stageId = (int)($_POST['stage_id'] ?? 0);
            $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
            if ($stageId > 0) {
                $stmt = $conn->prepare("UPDATE app_operation_stages SET is_active = ? WHERE id = ?");
                $stmt->bind_param('ii', $isActive, $stageId);
                $stmt->execute();
                $stmt->close();
            }
            $state['msg'] = $isActive === 1 ? 'تم تفعيل المرحلة.' : 'تم تعطيل المرحلة.';
            return true;
        }

        if ($action === 'delete_stage') {
            $stageId = (int)($_POST['stage_id'] ?? 0);
            if ($stageId > 0) {
                $stmt = $conn->prepare("DELETE FROM app_operation_stages WHERE id = ?");
                $stmt->bind_param('i', $stageId);
                $stmt->execute();
                $stmt->close();
            }
            $state['msg'] = 'تم حذف المرحلة نهائياً.';
            return true;
        }

        if ($action === 'move_stage') {
            $stageId = (int)($_POST['stage_id'] ?? 0);
            $direction = strtolower(trim((string)($_POST['direction'] ?? '')));
            $state['msg'] = md_swap_stage_order($conn, $stageId, $direction)
                ? 'تم تعديل أولوية المرحلة.'
                : 'لا يمكن نقل المرحلة أكثر في هذا الاتجاه.';
            return true;
        }

        if ($action === 'save_catalog_item') {
            $catalogItemId = (int)($_POST['catalog_item_id'] ?? 0);
            $typeKey = strtolower(trim((string)($_POST['catalog_type_key'] ?? '')));
            $group = strtolower(trim((string)($_POST['catalog_group'] ?? 'material')));
            $label = trim((string)($_POST['item_label'] ?? ''));
            $labelEn = trim((string)($_POST['item_label_en'] ?? ''));
            $sortOrder = (int)($_POST['item_sort_order'] ?? 1);
            $defaultPrice = (float)($_POST['default_unit_price'] ?? 0);
            if (!preg_match('/^[a-z0-9_]{2,50}$/', $typeKey) || !preg_match('/^[a-z0-9_]{2,40}$/', $group) || $label === '') {
                throw new RuntimeException('بيانات عنصر الكتالوج غير صحيحة.');
            }
            if ($labelEn === '') {
                $labelEn = $label;
            }
            if ($catalogItemId > 0) {
                $stmt = $conn->prepare("
                    UPDATE app_operation_catalog
                    SET type_key = ?,
                        catalog_group = ?,
                        item_label = ?,
                        item_label_en = ?,
                        sort_order = ?,
                        default_unit_price = ?,
                        is_active = 1
                    WHERE id = ?
                ");
                $stmt->bind_param('ssssidi', $typeKey, $group, $label, $labelEn, $sortOrder, $defaultPrice, $catalogItemId);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO app_operation_catalog (type_key, catalog_group, item_label, item_label_en, sort_order, default_unit_price, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE
                        item_label_en = VALUES(item_label_en),
                        sort_order = VALUES(sort_order),
                        default_unit_price = VALUES(default_unit_price),
                        is_active = 1
                ");
                $stmt->bind_param('ssssid', $typeKey, $group, $label, $labelEn, $sortOrder, $defaultPrice);
            }
            $stmt->execute();
            $stmt->close();
            $state['scopeType'] = $typeKey;
            $state['msg'] = 'تم حفظ عنصر الكتالوج.';
            return true;
        }

        if ($action === 'toggle_catalog_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
            if ($itemId > 0) {
                $stmt = $conn->prepare("UPDATE app_operation_catalog SET is_active = ? WHERE id = ?");
                $stmt->bind_param('ii', $isActive, $itemId);
                $stmt->execute();
                $stmt->close();
            }
            $state['msg'] = $isActive === 1 ? 'تم تفعيل عنصر الكتالوج.' : 'تم تعطيل عنصر الكتالوج.';
            return true;
        }

        if ($action === 'delete_catalog_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            if ($itemId > 0) {
                $stmt = $conn->prepare("DELETE FROM app_operation_catalog WHERE id = ?");
                $stmt->bind_param('i', $itemId);
                $stmt->execute();
                $stmt->close();
            }
            $state['msg'] = 'تم حذف عنصر الكتالوج نهائياً.';
            return true;
        }

        if ($action === 'move_catalog_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $direction = strtolower(trim((string)($_POST['direction'] ?? '')));
            $state['msg'] = md_swap_catalog_order($conn, $itemId, $direction)
                ? 'تم تعديل أولوية عنصر الكتالوج.'
                : 'لا يمكن نقل العنصر أكثر في هذا الاتجاه.';
            return true;
        }

        if ($action === 'move_type') {
            $typeKey = strtolower(trim((string)($_POST['type_key'] ?? '')));
            $direction = strtolower(trim((string)($_POST['direction'] ?? '')));
            $state['msg'] = md_swap_type_order($conn, $typeKey, $direction)
                ? 'تم تعديل أولوية نوع العملية.'
                : 'لا يمكن نقل نوع العملية أكثر في هذا الاتجاه.';
            return true;
        }

        if ($action === 'delete_number_rule') {
            $docType = strtolower(trim((string)($_POST['doc_type'] ?? '')));
            if (!preg_match('/^[a-z0-9_]{2,40}$/', $docType)) {
                throw new RuntimeException('نوع المستند غير صالح للحذف.');
            }
            $stmt = $conn->prepare("DELETE FROM app_document_sequences WHERE doc_type = ?");
            $stmt->bind_param('s', $docType);
            $stmt->execute();
            $stmt->close();
            $state['msg'] = 'تم حذف قاعدة الترقيم.';
            return true;
        }

        return false;
    }
}
