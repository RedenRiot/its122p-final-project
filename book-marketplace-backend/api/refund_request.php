<?php
/**
 * /api/refund_request.php
 * GET (list/show), POST (create), PUT (update), DELETE
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$user = require_authenticated_user($pdo);
$isStaffOrAdmin = in_array($user['role'], ['Staff', 'Admin'], true);
$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$crud = new Crud(
    pdo: $pdo,
    table: 'REFUND_REQUEST',
    primaryKey: 'refund_id',
    insertable: ['transaction_id', 'customer_id', 'processed_by_staff_id', 'reason', 'status'],
    required: ['transaction_id', 'customer_id', 'reason'],
    enums: [
        'status' => ['Pending', 'Approved', 'Rejected'],
    ],
);

try {
    switch ($method) {
        case 'GET':
            if ($id !== null) {
                $row = $crud->show($id);
                if (!$row || (!$isStaffOrAdmin && (int)$row['customer_id'] !== (int)$user['user_id'])) {
                    Response::error('Refund request not found or access denied.', 404);
                }
                Response::json($row);
            } else {
                if (!$isStaffOrAdmin) {
                    $_GET['customer_id'] = (string) $user['user_id'];
                }
                Response::json($crud->index($_GET));
            }
            break;

        case 'POST':
            $body = read_json_body();
            if (!$isStaffOrAdmin) {
                $body['customer_id'] = (int) $user['user_id'];
                $body['status'] = 'Pending';
                unset($body['processed_by_staff_id']);

                $txStmt = $pdo->prepare('SELECT buyer_id FROM `TRANSACTIONS` WHERE transaction_id = :tid LIMIT 1');
                $txStmt->execute(['tid' => (int) ($body['transaction_id'] ?? 0)]);
                $tx = $txStmt->fetch();
                if (!$tx || (int)$tx['buyer_id'] !== (int)$user['user_id']) {
                    Response::error('You can only request a refund for your own transactions.', 403);
                }
            }
            $created = $crud->create($body);
            Response::json($created, 201);
            break;

        case 'PUT':
        case 'PATCH':
            if (!$isStaffOrAdmin) {
                Response::error('Only Staff or Administrators can process refund requests.', 403);
            }
            if ($id === null) {
                Response::error("Query parameter 'refund_id' (as ?id=) is required for updates.", 400);
            }
            $body = read_json_body();
            $body['processed_by_staff_id'] = (int) $user['user_id'];
            $updated = $crud->update($id, $body);
            if ($updated === null) {
                Response::error('Refund request not found.', 404);
            }
            Response::json($updated);
            break;

        case 'DELETE':
            if (!$isStaffOrAdmin) {
                Response::error('Only Staff or Administrators can delete refund requests.', 403);
            }
            if ($id === null) {
                Response::error("Query parameter 'refund_id' (as ?id=) is required for deletes.", 400);
            }
            $ok = $crud->delete($id);
            if (!$ok) {
                Response::error('Refund request not found.', 404);
            }
            Response::json(['message' => 'Deleted', 'refund_id' => $id]);
            break;

        default:
            Response::error('Method not allowed.', 405);
    }
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (PDOException $e) {
    $isDebug = filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?? false, FILTER_VALIDATE_BOOLEAN);
    $details = $isDebug ? ['details' => $e->getMessage()] : [];
    Response::error('Database error.', 500, $details);
}
