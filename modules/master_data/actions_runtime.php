<?php

if (!function_exists('md_handle_cloud_pricing_actions')) {
    function md_handle_cloud_pricing_actions(mysqli $conn, string $action, array &$state): bool
    {
        if (!in_array($action, [
            'save_cloud_sync_settings',
            'save_pricing_settings',
            'append_pricing_paper_line',
            'append_pricing_machine_line',
            'append_pricing_finish_line',
            'run_cloud_sync_now',
        ], true)) {
            return false;
        }

        if ($action === 'save_cloud_sync_settings') {
            $save = app_cloud_sync_save_settings($conn, [
                'enabled' => $_POST['cloud_sync_enabled'] ?? '0',
                'remote_url' => (string)($_POST['cloud_sync_remote_url'] ?? ''),
                'remote_token' => (string)($_POST['cloud_sync_remote_token'] ?? ''),
                'api_token' => (string)($_POST['cloud_sync_api_token'] ?? ''),
                'installation_code' => (string)($_POST['cloud_sync_installation_code'] ?? ''),
                'sync_mode' => (string)($_POST['cloud_sync_mode'] ?? 'off'),
                'numbering_policy' => (string)($_POST['cloud_sync_numbering_policy'] ?? 'namespace'),
                'interval_seconds' => (string)($_POST['cloud_sync_interval_seconds'] ?? '120'),
                'auto_online' => $_POST['cloud_sync_auto_online'] ?? '0',
                'verify_financial' => $_POST['cloud_sync_verify_financial'] ?? '0',
                'local_db_label' => (string)($_POST['cloud_sync_local_db_label'] ?? ''),
                'remote_db_label' => (string)($_POST['cloud_sync_remote_db_label'] ?? ''),
            ]);
            if (empty($save['ok'])) {
                $saveError = (string)($save['error'] ?? 'save_failed');
                $errorText = 'تعذر حفظ إعدادات المزامنة.';
                if ($saveError === 'invalid_remote_url') {
                    $errorText = 'رابط المزامنة السحابية غير صالح.';
                } elseif ($saveError === 'remote_url_required') {
                    $errorText = 'أدخل رابط API السحابي قبل تفعيل المزامنة.';
                } elseif ($saveError === 'remote_token_required') {
                    $errorText = 'أدخل رمز التوثيق (Token) قبل تفعيل المزامنة.';
                }
                throw new RuntimeException($errorText . ' [' . $saveError . ']');
            }
            $state['msg'] = 'تم حفظ إعدادات الربط السحابي ومزامنة البيانات.';
            return true;
        }

        if ($action === 'save_pricing_settings') {
            $enabled = isset($_POST['pricing_enabled']) ? '1' : '0';
            $bindingCosts = [
                'cut' => (float)($_POST['pricing_binding_cut'] ?? 0),
                'thread' => (float)($_POST['pricing_binding_thread'] ?? 0),
                'cut_thread' => (float)($_POST['pricing_binding_cut_thread'] ?? 0),
                'staple' => (float)($_POST['pricing_binding_staple'] ?? 0),
                'staple_cut' => (float)($_POST['pricing_binding_staple_cut'] ?? 0),
            ];
            $defaults = [
                'waste_percent' => (float)($_POST['pricing_waste_percent'] ?? 0),
                'waste_sheets' => (int)($_POST['pricing_waste_sheets'] ?? 0),
                'profit_percent' => (float)($_POST['pricing_profit_percent'] ?? 0),
                'misc_cost' => (float)($_POST['pricing_misc_cost'] ?? 0),
                'setup_fee' => (float)($_POST['pricing_setup_fee'] ?? 0),
                'gather_cost_per_signature' => (float)($_POST['pricing_gather_cost_per_signature'] ?? 0),
                'risk_percent' => (float)($_POST['pricing_risk_percent'] ?? 0),
                'reject_percent' => (float)($_POST['pricing_reject_percent'] ?? 0),
                'color_test_cost' => (float)($_POST['pricing_color_test_cost'] ?? 0),
                'internal_transport_cost' => (float)($_POST['pricing_internal_transport_cost'] ?? 0),
                'book_mode_enabled' => isset($_POST['pricing_book_enabled']) ? 1 : 0,
                'binding_costs' => $bindingCosts,
            ];

            $paperFields = [
                ['key' => 'name', 'type' => 'string'],
                ['key' => 'price_ton', 'type' => 'float'],
            ];
            $machineFields = [
                ['key' => 'key', 'type' => 'string'],
                ['key' => 'label_ar', 'type' => 'string'],
                ['key' => 'label_en', 'type' => 'string'],
                ['key' => 'price_per_tray', 'type' => 'float'],
                ['key' => 'min_trays', 'type' => 'int'],
                ['key' => 'plate_cost', 'type' => 'float'],
                ['key' => 'sheet_class', 'type' => 'string'],
            ];
            $finishFields = [
                ['key' => 'key', 'type' => 'string'],
                ['key' => 'label_ar', 'type' => 'string'],
                ['key' => 'label_en', 'type' => 'string'],
                ['key' => 'price_piece', 'type' => 'float'],
                ['key' => 'price_tray', 'type' => 'float'],
                ['key' => 'allow_faces', 'type' => 'int'],
                ['key' => 'default_unit', 'type' => 'string'],
                ['key' => 'sheet_sensitive', 'type' => 'int'],
            ];

            $paperRows = md_pricing_collect_json_rows($_POST['pricing_papers_payload'] ?? '', $paperFields, 200);
            if (empty($paperRows)) {
                $paperRows = md_pricing_collect_rows($_POST['pricing_papers'] ?? null, $paperFields, 200);
            }
            if (empty($paperRows)) {
                $paperRows = md_pricing_parse_lines((string)($_POST['pricing_paper_lines'] ?? ''), $paperFields, 200);
            }
            $machineRows = md_pricing_collect_json_rows($_POST['pricing_machines_payload'] ?? '', $machineFields, 80, 'label_ar');
            if (empty($machineRows)) {
                $machineRows = md_pricing_collect_rows($_POST['pricing_machines_rows'] ?? null, $machineFields, 80, 'label_ar');
            }
            if (empty($machineRows)) {
                $machineRows = md_pricing_parse_lines((string)($_POST['pricing_machine_lines'] ?? ''), $machineFields, 80);
            }
            $machineRows = md_pricing_normalize_machine_rows($machineRows);
            $finishRows = md_pricing_collect_json_rows($_POST['pricing_finish_payload'] ?? '', $finishFields, 200);
            if (empty($finishRows)) {
                $finishRows = md_pricing_collect_rows($_POST['pricing_finish_rows'] ?? null, $finishFields, 200);
            }
            if (empty($finishRows)) {
                $finishRows = md_pricing_parse_lines((string)($_POST['pricing_finish_lines'] ?? ''), $finishFields, 200);
            }
            $finishRows = md_pricing_normalize_finish_rows($finishRows);

            app_setting_set($conn, 'pricing_enabled', $enabled);
            app_setting_set($conn, 'pricing_defaults', json_encode($defaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            app_setting_set($conn, 'pricing_paper_types', json_encode($paperRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            app_setting_set($conn, 'pricing_machines', json_encode($machineRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            app_setting_set($conn, 'pricing_finishing_ops', json_encode($finishRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $state['msg'] = 'تم حفظ إعدادات التسعير.';
            if (empty($state['isAjaxRequest'])) {
                $_SESSION['md_flash'] = [
                    'message' => $state['msg'],
                    'type' => 'ok',
                ];
                header('Location: ' . md_tab_url('pricing'));
                exit;
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'message' => $state['msg'],
                'status' => 'ok',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'append_pricing_paper_line') {
            $name = trim((string)($_POST['paper_name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('أدخل اسم الورق أولاً.');
            }
            $line = implode(' | ', [
                $name,
                trim((string)($_POST['paper_price_ton'] ?? '')),
            ]);
            $current = trim((string)($_POST['pricing_paper_lines'] ?? ''));
            $_POST['pricing_paper_lines'] = $current === '' ? $line : ($current . PHP_EOL . $line);
            $state['msg'] = 'تمت إضافة سطر الورق. احفظ الإعدادات لتثبيته نهائياً.';
            return true;
        }

        if ($action === 'append_pricing_machine_line') {
            $key = trim((string)($_POST['machine_key'] ?? ''));
            if ($key === '') {
                throw new RuntimeException('أدخل مفتاح الماكينة أولاً.');
            }
            $line = implode(' | ', [
                $key,
                trim((string)($_POST['machine_label_ar'] ?? '')),
                trim((string)($_POST['machine_label_en'] ?? '')),
                trim((string)($_POST['machine_yield'] ?? '')),
                trim((string)($_POST['machine_min'] ?? '')),
                trim((string)($_POST['machine_tray'] ?? '')),
                trim((string)($_POST['machine_thousand'] ?? '')),
                trim((string)($_POST['machine_plate'] ?? '')),
                trim((string)($_POST['machine_min_charge'] ?? '')),
            ]);
            $current = trim((string)($_POST['pricing_machine_lines'] ?? ''));
            $_POST['pricing_machine_lines'] = $current === '' ? $line : ($current . PHP_EOL . $line);
            $state['msg'] = 'تمت إضافة سطر الماكينة. احفظ الإعدادات لتثبيته نهائياً.';
            return true;
        }

        if ($action === 'append_pricing_finish_line') {
            $key = trim((string)($_POST['finish_key'] ?? ''));
            if ($key === '') {
                throw new RuntimeException('أدخل مفتاح العملية أولاً.');
            }
            $line = implode(' | ', [
                $key,
                trim((string)($_POST['finish_label_ar'] ?? '')),
                trim((string)($_POST['finish_label_en'] ?? '')),
                trim((string)($_POST['finish_price_piece'] ?? '')),
                trim((string)($_POST['finish_price_tray'] ?? '')),
                trim((string)($_POST['finish_faces'] ?? '')),
                trim((string)($_POST['finish_unit'] ?? '')),
            ]);
            $current = trim((string)($_POST['pricing_finish_lines'] ?? ''));
            $_POST['pricing_finish_lines'] = $current === '' ? $line : ($current . PHP_EOL . $line);
            $state['msg'] = 'تمت إضافة سطر العملية. احفظ الإعدادات لتثبيته نهائياً.';
            return true;
        }

        if ($action === 'run_cloud_sync_now') {
            $sync = app_cloud_sync_run($conn, true);
            if (!empty($sync['ok'])) {
                $applied = (int)($sync['applied_rules'] ?? 0);
                $state['msg'] = 'تمت مزامنة البيانات بنجاح.' . ($applied > 0 ? ' تم تطبيق ' . $applied . ' قاعدة ترقيم من السحابة.' : '');
            } elseif (!empty($sync['skipped'])) {
                $state['msg'] = 'تم تخطي المزامنة حالياً: ' . (string)($sync['reason'] ?? 'skipped');
            } else {
                throw new RuntimeException('فشلت المزامنة: ' . (string)($sync['reason'] ?? 'unknown_error'));
            }
            return true;
        }

        return false;
    }
}
