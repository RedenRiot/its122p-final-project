<?php
/**
 * /api/feedback.php — allows users to submit feedback about their experience.
 *
 *   POST    Creates a new report of type 'General_Feedback'.
 *           If a transaction_id is provided, it's linked as the related entity.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$authUser = require_authenticated_user($pdo);
$me = (int) $authUser['user_id'];

$crud = new Crud(
    pdo: $pdo,
    table: 'REPORTS',
    primaryKey: 'report_id',
    insertable: [
        'submitted_by_id', 'report_category', 'related_entity_type', 'form_data', 'status'
    ],
    required: ['submitted_by_id', 'report_category'],
    enums: [
        'report_category' => ['Verification_Form', 'Seller_Application', 'User_Violation', 'Listing_Dispute', 'General_Feedback'],
        'related_entity_type' => ['User', 'Book_Listing', 'Transaction', 'None'],
        'status' => ['Pending', 'Under_Review', 'Approved', 'Rejected', 'Resolved', 'Dismissed'],
    ],
);

try {
    if ($method === 'POST') {
        $body = request_body();
        
        // Feedback is always 'General_Feedback'
        $category = 'General_Feedback';
        
        // Link to transaction if provided
        $relatedType = 'None';
        $formData = [];
        
        if (isset($body['transaction_id'])) {
            $relatedType = 'Transaction';
            $formData['transaction_id'] = (int) $body['transaction_id'];
        }
        
        if (isset($body['comment'])) {
            $formData['comment'] = (string) $body['comment'];
        }
        
        if (isset($body['rating'])) {
            $formData['rating'] = (int) $body['rating'];
        }

        $created = $crud->create([
            'submitted_by_id'    => $me,
            'report_category'    => $category,
            'related_entity_type' => $relatedType,
            'form_data'           => json_encode($formData),
            'status'              => 'Pending',
        ]);

        Response::json($created, 201);
    } else {
        Response::error('Method not allowed', 405);
    }
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (PDOException $e) {
    database_error_response($e);
}
