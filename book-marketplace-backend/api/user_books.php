<?php
/**
 * /api/user_books.php
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
    table: 'USER_BOOKS',
    primaryKey: 'inventory_id',
    insertable: ['book_id', 'seller_id', 'listing_type', 'price', 'condition', 'status'],
    required: ['book_id', 'seller_id', 'listing_type', 'condition'],
    enums: [
        'listing_type' => ['For_trade', 'For_sale', 'Both'],
        'condition'    => ['New', 'Good', 'Acceptable'],
        'status'       => ['Available', 'In_transaction', 'Sold', 'Traded', 'Removed'],
    ],
);

try {
    switch ($method) {
        case 'GET':
            if ($id !== null) {
                $row = $crud->show($id);
                if (!$row) {
                    Response::error("Record with inventory_id = {$id} not found.", 404);
                }
                Response::json($row);
            } else {
                Response::json($crud->index($_GET));
            }
            break;

        case 'POST':
            $body = read_json_body();
            if (!$isStaffOrAdmin) {
                $body['seller_id'] = (int) $user['user_id'];
            }
            $created = $crud->create($body);
            Response::json($created, 201);
            break;

        case 'PUT':
        case 'PATCH':
            if ($id === null) {
                Response::error("Query parameter 'inventory_id' (as ?id=) is required for updates.", 400);
            }
            $existing = $crud->show($id);
            if (!$existing) {
                Response::error("Record with inventory_id = {$id} not found.", 404);
            }
            if (!$isStaffOrAdmin && (int)$existing['seller_id'] !== (int)$user['user_id']) {
                Response::error('You are not authorized to update this listing.', 403);
            }
            $body = read_json_body();
            if (!$isStaffOrAdmin) {
                unset($body['seller_id']);
            }
            $updated = $crud->update($id, $body);
            Response::json($updated);
            break;

        case 'DELETE':
            if ($id === null) {
                Response::error("Query parameter 'inventory_id' (as ?id=) is required for deletes.", 400);
            }
            $existing = $crud->show($id);
            if (!$existing) {
                Response::error("Record with inventory_id = {$id} not found.", 404);
            }
            if (!$isStaffOrAdmin && (int)$existing['seller_id'] !== (int)$user['user_id']) {
                Response::error('You are not authorized to delete this listing.', 403);
            }
            $ok = $crud->delete($id);
            Response::json(['message' => 'Deleted', 'inventory_id' => $id]);
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
