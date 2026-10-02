<?php
/**
 * /api/reports.php
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
    table: 'REPORTS',
    primaryKey: 'report_id',
    insertable: [
        'submitted_by_id', 'reviewed_by_id', 'report_category', 'related_entity_type',
        'form_data', 'status', 'resolution_notes', 'resolved_at',
    ],
    required: ['submitted_by_id', 'report_category', 'related_entity_type'],
    enums: [
        'report_category' => [
            'Verification_Form', 'Seller_Application', 'User_Violation',
            'Listing_Dispute', 'General_Feedback',
        ],
        'related_entity_type' => ['User', 'Book_Listing', 'Transaction', 'None'],
        'status' => ['Pending', 'Under_Review', 'Approved', 'Rejected', 'Resolved', 'Dismissed'],
    ],
);

try {
    switch ($method) {
        case 'GET':
            if ($id !== null) {
                $row = $crud->show($id);
                if (!$row || (!$isStaffOrAdmin && (int)$row['submitted_by_id'] !== (int)$user['user_id'])) {
                    Response::error('Report not found or access denied.', 404);
                }
                Response::json($row);
            } else {
                if (!$isStaffOrAdmin) {
                    $_GET['submitted_by_id'] = (string) $user['user_id'];
                }
                Response::json($crud->index($_GET));
            }
            break;

        case 'POST':
            $body = read_json_body();
            if (!$isStaffOrAdmin) {
                $body['submitted_by_id'] = (int) $user['user_id'];
                $body['status'] = 'Pending';
                unset($body['reviewed_by_id'], $body['resolution_notes'], $body['resolved_at']);
            }
            $created = $crud->create($body);
            Response::json($created, 201);
            break;

        case 'PUT':
        case 'PATCH':
            if (!$isStaffOrAdmin) {
                Response::error('Only Staff or Administrators can review and update reports.', 403);
            }
            if ($id === null) {
                Response::error("Query parameter 'report_id' (as ?id=) is required for updates.", 400);
            }
            $body = read_json_body();
            $body['reviewed_by_id'] = (int) $user['user_id'];
            if (isset($body['status']) && in_array($body['status'], ['Approved', 'Rejected', 'Resolved', 'Dismissed'], true)) {
                $body['resolved_at'] = gmdate('Y-m-d H:i:s');
            }
            $updated = $crud->update($id, $body);
            if ($updated === null) {
                Response::error('Report not found.', 404);
            }
            Response::json($updated);
            break;

        case 'DELETE':
            if ($user['role'] !== 'Admin') {
                Response::error('Only Administrators can delete reports.', 403);
            }
            if ($id === null) {
                Response::error("Query parameter 'report_id' (as ?id=) is required for deletes.", 400);
            }
            $ok = $crud->delete($id);
            if (!$ok) {
                Response::error('Report not found.', 404);
            }
            Response::json(['message' => 'Deleted', 'report_id' => $id]);
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
