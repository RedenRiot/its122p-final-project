<?php
/**
 * /api/transactions.php
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
    table: 'TRANSACTIONS',
    primaryKey: 'transaction_id',
    insertable: [
        'buyer_id', 'requested_inventory_id', 'offered_inventory_id',
        'managed_by_staff_id', 'transaction_type', 'amount_paid', 'status',
    ],
    required: ['buyer_id', 'requested_inventory_id', 'transaction_type'],
    enums: [
        'transaction_type' => ['Purchase', 'Trade'],
        'status'            => ['Pending', 'Accepted', 'Completed', 'Cancelled', 'Disputed'],
    ],
);

try {
    switch ($method) {
        case 'GET':
            if ($id !== null) {
                $row = $crud->show($id);
                if (!$row || (!$isStaffOrAdmin && (int)$row['buyer_id'] !== (int)$user['user_id'])) {
                    Response::error('Transaction not found or access denied.', 404);
                }
                Response::json($row);
            } else {
                if (!$isStaffOrAdmin) {
                    $_GET['buyer_id'] = (string) $user['user_id'];
                }
                Response::json($crud->index($_GET));
            }
            break;

        case 'POST':
            $body = read_json_body();
            if (!$isStaffOrAdmin) {
                $body['buyer_id'] = (int) $user['user_id'];
                $body['status'] = 'Pending';
                unset($body['managed_by_staff_id']);
            }
            $created = $crud->create($body);
            Response::json($created, 201);
            break;

        case 'PUT':
        case 'PATCH':
            if ($id === null) {
                Response::error("Query parameter 'transaction_id' (as ?id=) is required for updates.", 400);
            }
            $existing = $crud->show($id);
            if (!$existing) {
                Response::error('Transaction not found.', 404);
            }
            $body = read_json_body();
            if (!$isStaffOrAdmin) {
                if ((int)$existing['buyer_id'] !== (int)$user['user_id']) {
                    Response::error('You are not authorized to modify this transaction.', 403);
                }
                if ($existing['status'] !== 'Pending') {
                    Response::error('Only pending transactions can be cancelled.', 400);
                }
                if (($body['status'] ?? '') !== 'Cancelled') {
                    Response::error('Customers may only cancel pending transactions.', 403);
                }
                $body = ['status' => 'Cancelled'];
            }
            $updated = $crud->update($id, $body);
            Response::json($updated);
            break;

        case 'DELETE':
            if (!$isStaffOrAdmin) {
                Response::error('Only Staff or Administrators can delete transactions.', 403);
            }
            if ($id === null) {
                Response::error("Query parameter 'transaction_id' (as ?id=) is required for deletes.", 400);
            }
            $ok = $crud->delete($id);
            if (!$ok) {
                Response::error('Transaction not found.', 404);
            }
            Response::json(['message' => 'Deleted', 'transaction_id' => $id]);
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
