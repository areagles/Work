<?php
// manager_api.php - (Royal Ops Bridge V30.0)

require_once __DIR__ . '/config.php';

// ⚙️ إعدادات الاتصال (يفضل ضبطها من Environment)
define('MANAGER_BASE_URL', rtrim((string)app_env('MANAGER_BASE_URL', ''), '/'));
define('MANAGER_BUSINESS_ID', (string)app_env('MANAGER_BUSINESS_ID', ''));
define('MANAGER_TOKEN', (string)app_env('MANAGER_TOKEN', ''));

// دالة الاتصال الرئيسية
function callManager($method, $endpoint, $data = null) {
    if (MANAGER_BASE_URL === '' || MANAGER_BUSINESS_ID === '' || MANAGER_TOKEN === '') {
        return ['success' => false, 'error' => 'Manager API settings are missing.', 'data' => null];
    }
    if (stripos(MANAGER_BASE_URL, 'https://') !== 0) {
        return ['success' => false, 'error' => 'MANAGER_BASE_URL must use HTTPS.', 'data' => null];
    }

    $url = MANAGER_BASE_URL . '/api2/' . rawurlencode(MANAGER_BUSINESS_ID) . '/' . rawurlencode($endpoint) . '.json';
    $ch = curl_init($url);
    $headers = ['X-Access-Token: ' . MANAGER_TOKEN, 'Content-Type: application/json'];
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    if ($method == 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr) {
        return ['success' => false, 'error' => $curlErr, 'data' => null];
    }

    $decoded = json_decode((string)$response, true);
    $success = ($httpCode >= 200 && $httpCode < 300);
    return [
        'success' => $success,
        'data' => $decoded,
        'http_code' => $httpCode,
        'error' => $success ? null : ('HTTP ' . $httpCode),
    ];
}

function getManagerCustomerUUID($name, $phone = '') {
    $res = callManager('GET', 'Customers');
    if ($res['success'] && is_array($res['data'])) {
        foreach ($res['data'] as $c) {
            if (isset($c['Name']) && trim($c['Name']) == trim($name)) return $c['Key'];
        }
    }
    $create = callManager('POST', 'Customers', ['Name' => $name, 'BillingAddress' => $phone ? "Phone: $phone" : ""]);
    return $create['success'] ? $create['data']['Key'] : false;
}

function getManagerCashAccountUUID() {
    $res = callManager('GET', 'CashAccounts');
    return ($res['success'] && !empty($res['data'])) ? $res['data'][0]['Key'] : false;
}

function createManagerInvoice($data) {
    $custUUID = getManagerCustomerUUID($data['client_name'], $data['client_phone']);
    if (!$custUUID) return ['success' => false, 'msg' => 'العميل غير موجود'];

    $inv = [
        'IssueDate' => date('Y-m-d'),
        'Customer' => $custUUID,
        'Reference' => $data['ref'],
        'Description' => $data['desc'],
        'Lines' => [['Description' => $data['desc'], 'Qty' => 1, 'UnitPrice' => (float)$data['amount']]]
    ];
    return callManager('POST', 'SalesInvoices', $inv);
}

function createManagerReceipt($data) {
    $custUUID = getManagerCustomerUUID($data['client_name']);
    $cashUUID = getManagerCashAccountUUID();
    if (!$custUUID || !$cashUUID) return ['success' => false];

    $rec = [
        'Date' => date('Y-m-d'),
        'Reference' => $data['ref'],
        'Payee' => $custUUID,
        'ReceivedIn' => $cashUUID,
        'Description' => $data['desc'],
        'Lines' => [['Description' => $data['desc'], 'Amount' => (float)$data['amount']]]
    ];
    return callManager('POST', 'Receipts', $rec);
}

function createManagerPayment($data) {
    $cashUUID = getManagerCashAccountUUID();
    if (!$cashUUID) return ['success' => false];

    $pay = [
        'Date' => date('Y-m-d'),
        'Reference' => $data['ref'],
        'PaidFrom' => $cashUUID,
        'Description' => $data['desc'],
        'Lines' => [['Description' => $data['desc'], 'Amount' => (float)$data['amount']]]
    ];
    return callManager('POST', 'Payments', $pay);
}
?>
