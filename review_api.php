<?php
// review_api.php - (V3.0) - Now with designer replies

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once 'config.php';
app_start_session();

http_response_code(400);
$response = ['status' => 'error', 'message' => 'Invalid Action'];
$conn->set_charset("utf8mb4");
$isAuthenticated = isset($_SESSION['user_id']);

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

function readApiInput(): array {
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string)$raw, true);
    if (is_array($decoded)) {
        return $decoded + $_POST;
    }
    return $_POST;
}

function requireAuthenticatedCsrf(bool $isAuthenticated, array $input): void {
    if (!$isAuthenticated) {
        return;
    }
    $csrfToken = trim((string)($input['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
    if (!app_verify_csrf($csrfToken)) {
        http_response_code(419);
        echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
        exit;
    }
}

function proofAccessibleByToken(mysqli $conn, int $proofId, string $token): bool {
    if ($proofId <= 0 || $token === '') {
        return false;
    }
    $stmt = $conn->prepare("
        SELECT jp.id
        FROM job_proofs jp
        JOIN job_orders jo ON jo.id = jp.job_id
        WHERE jp.id = ? AND jo.access_token = ? AND LOWER(TRIM(jo.current_stage)) IN ('client_rev', 'design_review')
        LIMIT 1
    ");
    $stmt->bind_param("is", $proofId, $token);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function proofAccessibleForAuthenticatedUser(mysqli $conn, int $proofId, bool $needEdit = false): bool {
    if ($proofId <= 0) {
        return false;
    }
    $stmt = $conn->prepare("
        SELECT jo.id AS job_id
        FROM job_proofs jp
        JOIN job_orders jo ON jo.id = jp.job_id
        WHERE jp.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $proofId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return false;
    }
    return app_user_can_access_job($conn, (int)$row['job_id'], $needEdit);
}

function commentAccessibleByToken(mysqli $conn, int $commentId, string $token): bool {
    if ($commentId <= 0 || $token === '') {
        return false;
    }
    $stmt = $conn->prepare("
        SELECT pc.id
        FROM proof_comments pc
        JOIN job_proofs jp ON jp.id = pc.proof_id
        JOIN job_orders jo ON jo.id = jp.job_id
        WHERE pc.id = ? AND jo.access_token = ? AND LOWER(TRIM(jo.current_stage)) IN ('client_rev', 'design_review')
        LIMIT 1
    ");
    $stmt->bind_param("is", $commentId, $token);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function commentAccessibleForAuthenticatedUser(mysqli $conn, int $commentId, bool $needEdit = false): bool {
    if ($commentId <= 0) {
        return false;
    }
    $stmt = $conn->prepare("
        SELECT jo.id AS job_id
        FROM proof_comments pc
        JOIN job_proofs jp ON jp.id = pc.proof_id
        JOIN job_orders jo ON jo.id = jp.job_id
        WHERE pc.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $commentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return false;
    }
    return app_user_can_access_job($conn, (int)$row['job_id'], $needEdit);
}

// --- ACTION 1: Get all comments for a specific proof ---
if ($action == 'get_comments' && isset($_GET['proof_id'])) {
    $proof_id = intval($_GET['proof_id']);
    if ($proof_id <= 0) {
        $response = ['status' => 'error', 'message' => 'Invalid proof id.'];
        echo json_encode($response);
        exit;
    }

    if ($isAuthenticated) {
        if (!proofAccessibleForAuthenticatedUser($conn, $proof_id, false)) {
            http_response_code(403);
            $response = ['status' => 'error', 'message' => 'Unauthorized proof access.'];
            echo json_encode($response);
            exit;
        }
    } else {
        $token = trim((string)($_GET['token'] ?? ''));
        if (!proofAccessibleByToken($conn, $proof_id, $token)) {
            http_response_code(403);
            $response = ['status' => 'error', 'message' => 'Unauthorized proof access.'];
            echo json_encode($response);
            exit;
        }
    }

    $comments = [];

    // ✨ NOW SELECTING THE REPLY COLUMNS AS WELL
    $stmt = $conn->prepare("SELECT id, pos_x, pos_y, comment_text, status, author, designer_reply, replied_at, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as created_at FROM proof_comments WHERE proof_id = ? ORDER BY created_at ASC");
    $stmt->bind_param("i", $proof_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $row['comment_text'] = htmlspecialchars($row['comment_text']);
        $row['designer_reply'] = htmlspecialchars($row['designer_reply'] ?? ''); // Ensure it's not null
        $comments[] = $row;
    }

    $stmt->close();
    http_response_code(200);
    $response = ['status' => 'success', 'comments' => $comments];
}

// --- ACTION 2: Add a new comment ---
if ($action == 'add_comment' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $input = readApiInput();
    requireAuthenticatedCsrf($isAuthenticated, $input);

    $proof_id = intval($input['proof_id'] ?? 0);
    $comment_text = mb_substr(trim((string)($input['comment_text'] ?? '')), 0, 1500);
    $pos_x = isset($input['pos_x']) ? (float)$input['pos_x'] : 0.0;
    $pos_y = isset($input['pos_y']) ? (float)$input['pos_y'] : 0.0;
    $token = trim((string)($input['token'] ?? ($_GET['token'] ?? '')));

    if ($proof_id <= 0 || $comment_text === '') {
        $response = ['status' => 'error', 'message' => 'Incomplete comment data.'];
        echo json_encode($response);
        exit;
    }

    if ($isAuthenticated) {
        if (!proofAccessibleForAuthenticatedUser($conn, $proof_id, true)) {
            http_response_code(403);
            $response = ['status' => 'error', 'message' => 'Unauthorized proof access.'];
            echo json_encode($response);
            exit;
        }
    } else {
        if (!proofAccessibleByToken($conn, $proof_id, $token)) {
            http_response_code(403);
            $response = ['status' => 'error', 'message' => 'Unauthorized proof access.'];
            echo json_encode($response);
            exit;
        }
    }

    $author = $isAuthenticated ? (string)($_SESSION['name'] ?? 'Staff') : 'Client';
    $author = mb_substr(trim($author), 0, 100);
    if ($author === '') {
        $author = 'Client';
    }

    if ($pos_x < 0) $pos_x = 0;
    if ($pos_x > 100) $pos_x = 100;
    if ($pos_y < 0) $pos_y = 0;
    if ($pos_y > 100) $pos_y = 100;

    $stmt = $conn->prepare("INSERT INTO proof_comments (proof_id, pos_x, pos_y, comment_text, author) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iddss", $proof_id, $pos_x, $pos_y, $comment_text, $author);
    if ($stmt->execute()) {
        http_response_code(201);
        $response = ['status' => 'success', 'message' => 'Comment added.', 'comment_id' => $stmt->insert_id];
    } else {
        error_log('review_api add_comment DB error: ' . $stmt->error);
        $response = ['status' => 'error', 'message' => 'Database error while adding comment.'];
    }
    $stmt->close();
}

// --- ACTION 3: Update a comment status ---
if ($action == 'update_comment_status' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $input = readApiInput();
    requireAuthenticatedCsrf($isAuthenticated, $input);

    $comment_id = intval($input['comment_id'] ?? 0);
    $status = strtolower(trim((string)($input['status'] ?? 'open')));
    $token = trim((string)($input['token'] ?? ($_GET['token'] ?? '')));
    $allowedStatuses = ['open', 'done', 'resolved', 'pending'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'open';
    }

    if ($comment_id <= 0) {
        $response = ['status' => 'error', 'message' => 'Invalid comment id.'];
        echo json_encode($response);
        exit;
    }

    if ($isAuthenticated) {
        if (!commentAccessibleForAuthenticatedUser($conn, $comment_id, true)) {
            http_response_code(403);
            $response = ['status' => 'error', 'message' => 'Unauthorized comment access.'];
            echo json_encode($response);
            exit;
        }
    } else {
        if (!commentAccessibleByToken($conn, $comment_id, $token)) {
            http_response_code(403);
            $response = ['status' => 'error', 'message' => 'Unauthorized comment access.'];
            echo json_encode($response);
            exit;
        }
    }

    $stmt = $conn->prepare("UPDATE proof_comments SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $comment_id);
    if ($stmt->execute()) {
        http_response_code(200);
        $response = ['status' => 'success', 'message' => 'Status updated.'];
    } else {
        error_log('review_api update_comment_status DB error: ' . $stmt->error);
        $response = ['status' => 'error', 'message' => 'Database error while updating status.'];
    }
    $stmt->close();
}

// --- ✨ ACTION 4: NEW - Add or update a designer's reply ---
if ($action == 'add_designer_reply' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!$isAuthenticated) {
        http_response_code(401);
        $response = ['status' => 'error', 'message' => 'Unauthorized.'];
        echo json_encode($response);
        exit;
    }
    $allowedRoles = ['admin', 'manager', 'designer', 'production'];
    $role = strtolower((string)($_SESSION['role'] ?? ''));
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        $response = ['status' => 'error', 'message' => 'Permission denied.'];
        echo json_encode($response);
        exit;
    }

    $input = readApiInput();
    requireAuthenticatedCsrf($isAuthenticated, $input);

    if (isset($input['comment_id'], $input['reply_text'])) {
        $comment_id = intval($input['comment_id']);
        $reply_text = mb_substr(trim((string)$input['reply_text']), 0, 1000);
        if (!commentAccessibleForAuthenticatedUser($conn, $comment_id, true)) {
            http_response_code(403);
            $response = ['status' => 'error', 'message' => 'Unauthorized comment access.'];
            echo json_encode($response);
            exit;
        }

        $stmt = $conn->prepare("UPDATE proof_comments SET designer_reply = ?, replied_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $reply_text, $comment_id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                http_response_code(200);
                $response = ['status' => 'success', 'message' => 'Designer reply saved.'];
            } else {
                $response = ['status' => 'error', 'message' => 'Comment not found or reply is the same.'];
            }
        } else {
            error_log('review_api add_designer_reply DB error: ' . $stmt->error);
            $response = ['status' => 'error', 'message' => 'Database error.'];
        }
        $stmt->close();
    } else {
        $response = ['status' => 'error', 'message' => 'Incomplete data for designer reply.'];
    }
}

$conn->close();
echo json_encode($response);
exit;
?>
