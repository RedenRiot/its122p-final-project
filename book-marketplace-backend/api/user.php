<?php
/**
 * /api/user.php
 * GET (list/show), POST (create), PUT (update), DELETE
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$auth = require_authenticated_user($pdo);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    require_authenticated_user($pdo, ['Admin']);
}

// Customers only need public username and ID for listings; Admin and Staff see management fields.
// Sensitive password_hash is NEVER exposed to any caller.
$hidden = ['password_hash'];
if (!in_array($auth['role'], ['Admin', 'Staff'], true)) {
    $hidden[] = 'email';
    $hidden[] = 'permission';
}

$crud = new Crud(
    pdo: $pdo,
    table: 'USER',
    primaryKey: 'user_id',
    insertable: ['username', 'email', 'password_hash', 'role', 'status', 'permission'],
    required: ['username', 'email', 'password_hash'],
    enums: [
        'role'   => ['Customer', 'Staff', 'Admin'],
        'status' => ['Active', 'Suspended', 'Banned', 'Pending Verification'],
    ],
    hidden: $hidden,
);

dispatch_crud_request($crud, 'user_id');
