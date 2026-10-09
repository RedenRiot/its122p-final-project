<?php
/**
 * /api/activity_logs.php
 *
 * GET    Staff/Admin only — view activity and visit logs.
 * POST   Public or authenticated — record a new activity log row.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];

function activity_logs_json($value): array
{
    if (is_array($value)) return $value;
    if (!is_string($value) || $value === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : ['value' => $value];
}

try {
    if ($method === 'GET') {
        require_authenticated_user($pdo, ['Staff', 'Admin']);
        ensure_activity_logs_table($pdo);

        $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));

        $conditions = [
            '1=1',
            "activity_action NOT IN ('ResetPassword', 'RequestPasswordReset')",
            "activity_action NOT LIKE '%ResetPassword%'",
            "activity_action NOT LIKE '%PasswordReset%'",
        ];
        $params = [];
        if (!empty($_GET['activity_type'])) {
            $conditions[] = 'activity_type = :activity_type';
            $params['activity_type'] = (string) $_GET['activity_type'];
        }
        if (!empty($_GET['outcome'])) {
            $conditions[] = 'outcome = :outcome';
            $params['outcome'] = (string) $_GET['outcome'];
        }
        if (!empty($_GET['visitor_key'])) {
            $conditions[] = 'visitor_key = :visitor_key';
            $params['visitor_key'] = (string) $_GET['visitor_key'];
        }

        $sql = 'SELECT * FROM `ACTIVITY_LOGS` WHERE ' . implode(' AND ', $conditions) . ' ORDER BY activity_id DESC LIMIT :limit OFFSET :offset';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            $row['details'] = activity_logs_json($row['details'] ?? null);
        }
        Response::json($rows);
    }

    if ($method === 'POST') {
        $body = request_body();
        $actor = current_authenticated_user($pdo);
        ensure_activity_logs_table($pdo);

        $type = trim((string) ($body['activity_type'] ?? 'Activity'));
        $action = trim((string) ($body['activity_action'] ?? 'Unknown'));
        if ($type === '' || $action === '') {
            Response::error('activity_type and activity_action are required.', 422);
        }

        if (in_array($action, ['ResetPassword', 'RequestPasswordReset'], true)
            || stripos($action, 'passwordreset') !== false
            || stripos($action, 'resetpassword') !== false) {
            Response::json(['message' => 'Ignored confidential action.'], 200);
        }

        record_activity_log($pdo, [
            'actor_user_id'   => $actor['user_id'] ?? null,
            'visitor_key'     => trim((string) ($body['visitor_key'] ?? '')) ?: null,
            'activity_type'   => $type,
            'activity_action' => $action,
            'outcome'         => (string) ($body['outcome'] ?? 'Neutral'),
            'reason'          => isset($body['reason']) ? (string) $body['reason'] : null,
            'page_path'       => isset($body['page_path']) ? (string) $body['page_path'] : null,
            'target_type'     => isset($body['target_type']) ? (string) $body['target_type'] : null,
            'target_id'       => isset($body['target_id']) ? (string) $body['target_id'] : null,
            'details'         => $body['details'] ?? null,
        ]);

        Response::json(['message' => 'Activity logged.'], 201);
    }

    Response::error('Method not allowed.', 405);
} catch (PDOException $e) {
    database_error_response($e);
}