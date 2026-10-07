<?php
/**
 * /api/transactions.php — purchases and trades.
 *
 *   GET     Customers: only transactions they are part of (as the buyer, or
 *           as the seller of a book involved). Staff/Admin: all.
 *   POST    Customers only. The server checks the listing and fills in the
 *           price itself; the requested book (and the offered book for a
 *           trade) is reserved (In_transaction) in the same database
 *           transaction, so two people can never buy the same copy.
 *   PUT     Customers: may only cancel their own Pending request.
 *           Staff/Admin: move the status along a valid path. Completing
 *           marks books Sold/Traded; cancelling puts them back on the shelf.
 *   DELETE  Admin only (soft delete).
 *
 *   Valid status changes:
 *     Pending  -> Accepted | Cancelled | Disputed
 *     Accepted -> Completed | Cancelled | Disputed
 *     Disputed -> Completed | Cancelled
 *     Completed, Cancelled -> (final)
 */
require_once __DIR__ . '/../lib/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$authUser = require_authenticated_user($pdo);
$isCustomer = !is_staff_or_admin($authUser);
$me = (int) $authUser['user_id'];

const TX_FLOW = [
    'Pending'   => ['Accepted', 'Cancelled', 'Disputed'],
    'Accepted'  => ['Completed', 'Cancelled', 'Disputed'],
    'Disputed'  => ['Completed', 'Cancelled'],
    'Completed' => [],
    'Cancelled' => [],
];

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
        'status'            => array_keys(TX_FLOW),
    ],
);

function load_listing(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM `USER_BOOKS` WHERE inventory_id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Moves listings from one status to another; returns how many changed. */
function set_listing_status(PDO $pdo, array $ids, string $to, array $from): int
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return 0;
    $params = ['to' => $to];
    $idMarks = [];
    foreach ($ids as $i => $id) { $idMarks[] = ":i{$i}"; $params["i{$i}"] = $id; }
    $fromMarks = [];
    foreach (array_values($from) as $i => $st) { $fromMarks[] = ":f{$i}"; $params["f{$i}"] = $st; }
    $stmt = $pdo->prepare(
        'UPDATE `USER_BOOKS` SET status = :to WHERE inventory_id IN (' . implode(',', $idMarks) . ')
         AND status IN (' . implode(',', $fromMarks) . ') AND deleted_at IS NULL'
    );
    $stmt->execute($params);
    return $stmt->rowCount();
}

function log_transaction_activity(PDO $pdo, array $authUser, array $tx, string $action, string $outcome, ?string $reason = null, array $details = []): void
{
    record_activity_log($pdo, [
        'actor_user_id'   => (int) ($authUser['user_id'] ?? 0),
        'activity_type'   => 'Transaction',
        'activity_action' => $action,
        'outcome'         => $outcome,
        'reason'          => $reason,
        'page_path'       => '/transactions.php',
        'target_type'     => 'Transaction',
        'target_id'       => (string) ($tx['transaction_id'] ?? ''),
        'details'         => $details + [
            'transaction_id'   => (int) ($tx['transaction_id'] ?? 0),
            'transaction_type' => $tx['transaction_type'] ?? null,
            'status'           => $tx['status'] ?? null,
        ],
    ]);
}

function transaction_error(PDO $pdo, array $authUser, array $context, string $message, int $status = 422, string $action = 'Validation Failed'): void
{
    log_transaction_activity($pdo, $authUser, $context, $action, 'Failed', $message, ['error' => $message]);
    Response::error($message, $status);
}

try {
    /* ── GET ─────────────────────────────────────────────────────────── */
    if ($method === 'GET' && $isCustomer) {
        $sql = 'SELECT t.* FROM `TRANSACTIONS` t
                LEFT JOIN `USER_BOOKS` r ON r.inventory_id = t.requested_inventory_id
                LEFT JOIN `USER_BOOKS` o ON o.inventory_id = t.offered_inventory_id
                WHERE t.deleted_at IS NULL AND (t.buyer_id = :me1 OR r.seller_id = :me2 OR o.seller_id = :me3)';
        $params = ['me1' => $me, 'me2' => $me, 'me3' => $me];
        if (isset($_GET['id'])) {
            $sql .= ' AND t.transaction_id = :id';
            $params['id'] = (int) $_GET['id'];
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY t.transaction_id DESC LIMIT 1000');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (isset($_GET['id'])) {
            if (!$rows) Response::error('Transaction not found.', 404);
            Response::json($rows[0]);
        }
        Response::json($rows);
    }

    /* ── POST: a customer requests a purchase or a trade ─────────────── */
    if ($method === 'POST') {
        if (!$isCustomer) Response::error('Only customers can buy or trade books.', 403);
        $body = request_body();
        $type = (string) ($body['transaction_type'] ?? '');
        if (!in_array($type, ['Purchase', 'Trade'], true)) {
            transaction_error($pdo, $authUser, ['transaction_id' => 0, 'transaction_type' => $type, 'status' => 'Rejected'], 'transaction_type must be Purchase or Trade.', 422, 'Create Failed');
        }

        $requested = load_listing($pdo, (int) ($body['requested_inventory_id'] ?? 0));
        if (!$requested) transaction_error($pdo, $authUser, ['transaction_id' => 0, 'transaction_type' => $type, 'status' => 'Rejected'], 'That book listing no longer exists.', 404, 'Create Failed');
        if ((int) $requested['seller_id'] === $me) transaction_error($pdo, $authUser, ['transaction_id' => 0, 'transaction_type' => $type, 'status' => 'Rejected'], 'You cannot buy or trade for your own book.', 422, 'Create Failed');
        if ($requested['status'] !== 'Available') transaction_error($pdo, $authUser, ['transaction_id' => 0, 'transaction_type' => $type, 'status' => 'Rejected'], 'Sorry, this book is no longer available.', 409, 'Create Failed');

        $offeredId = null;
        $amount = null;
        if ($type === 'Purchase') {
            if (!in_array($requested['listing_type'], ['For_sale', 'Both'], true)) {
                transaction_error($pdo, $authUser, ['transaction_id' => 0, 'transaction_type' => $type, 'status' => 'Rejected'], 'This book is listed for trade only.', 422, 'Create Failed');
            }
            $amount = $requested['price'];           // price comes from the listing, not the browser
            if ($amount === null || (float) $amount <= 0) transaction_error($pdo, $authUser, ['transaction_id' => 0, 'transaction_type' => $type, 'status' => 'Rejected'], 'This book has no price set.', 422, 'Create Failed');
        } else {
            if (!in_array($requested['listing_type'], ['For_trade', 'Both'], true)) {
                transaction_error($pdo, $authUser, ['transaction_id' => 0, 'transaction_type' => $type, 'status' => 'Rejected'], 'This book is listed for sale only.', 422, 'Create Failed');
            }
            $offered = load_listing($pdo, (int) ($body['offered_inventory_id'] ?? 0));
            if (!$offered) transaction_error($pdo, $authUser, ['transaction_id' => 0, 'transaction_type' => $type, 'status' => 'Rejected'], 'Choose one of your own books to offer.', 422, 'Create Failed');
            if ((int) $offered['seller_id'] !== $me) transaction_error($pdo, $authUser, ['transaction_id' => 0, 'transaction_type' => $type, 'status' => 'Rejected'], 'You can only offer your own books.', 403, 'Create Failed');
            if ($offered['status'] !== 'Available') transaction_error($pdo, $authUser, ['transaction_id' => 0, 'transaction_type' => $type, 'status' => 'Rejected'], 'The book you offered is not available.', 409, 'Create Failed');
            $offeredId = (int) $offered['inventory_id'];
        }

        $pdo->beginTransaction();
        try {
            // Reserve the book(s) first; if someone else got there first, stop
            $ids = $offeredId ? [(int) $requested['inventory_id'], $offeredId] : [(int) $requested['inventory_id']];
            $reserved = set_listing_status($pdo, $ids, 'In_transaction', ['Available']);
            if ($reserved !== count($ids)) {
                $pdo->rollBack();
                transaction_error($pdo, $authUser, ['transaction_id' => 0, 'transaction_type' => $type, 'status' => 'Rejected'], 'Sorry, this book was just requested by someone else.', 409, 'Create Failed');
            }
            $created = $crud->create([
                'buyer_id'               => $me,
                'requested_inventory_id' => (int) $requested['inventory_id'],
                'offered_inventory_id'   => $offeredId,
                'transaction_type'       => $type,
                'amount_paid'            => $amount,
                'status'                 => 'Pending',
            ]);
            $pdo->commit();
            log_transaction_activity($pdo, $authUser, $created, $type === 'Trade' ? 'Trade Requested' : 'Purchase Requested', 'Neutral', 'Transaction requested', [
                'requested_inventory_id' => (int) $requested['inventory_id'],
                'offered_inventory_id'   => $offeredId,
                'amount_paid'            => $amount,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        Response::json($created, 201);
    }

    /* ── PUT: status changes only ────────────────────────────────────── */
    if ($method === 'PUT' || $method === 'PATCH') {
        $id = (int) ($_GET['id'] ?? 0);
        $tx = $crud->show($id);
        if (!$tx) Response::error('Transaction not found.', 404);
        $body = request_body();
        $to = (string) ($body['status'] ?? '');
        $from = (string) $tx['status'];

        if ($isCustomer) {
            if ((int) $tx['buyer_id'] !== $me || $from !== 'Pending' || $to !== 'Cancelled') {
                transaction_error($pdo, $authUser, $tx, 'You can only cancel your own pending requests.', 403, 'Status Change Failed');
            }
        } elseif (array_diff(array_keys($body), ['status', 'managed_by_staff_id'])) {
            transaction_error($pdo, $authUser, $tx, 'Only the status of a transaction can be changed.', 422, 'Status Change Failed');
        }
        if ($to === $from) Response::json($tx);
        if (!in_array($to, TX_FLOW[$from] ?? [], true)) {
            transaction_error($pdo, $authUser, $tx, "A transaction can't go from {$from} to {$to}.", 422, 'Status Change Failed');
        }

        $listingIds = array_filter([(int) $tx['requested_inventory_id'], (int) ($tx['offered_inventory_id'] ?? 0)]);
        $pdo->beginTransaction();
        try {
            $update = ['status' => $to];
            if (!$isCustomer) $update['managed_by_staff_id'] = $me;
            $updated = $crud->update($id, $update);
            if ($to === 'Completed') {
                set_listing_status($pdo, $listingIds, $tx['transaction_type'] === 'Trade' ? 'Traded' : 'Sold', ['In_transaction', 'Available']);
                log_transaction_activity($pdo, $authUser, $updated ?? $tx, $tx['transaction_type'] === 'Trade' ? 'Trade Completed' : 'Purchase Completed', 'Success', 'Transaction completed successfully.', [
                    'updated_status' => $to,
                    'managed_by_staff_id' => $updated['managed_by_staff_id'] ?? ($isCustomer ? null : $me),
                ]);
            } elseif ($to === 'Cancelled') {
                set_listing_status($pdo, $listingIds, 'Available', ['In_transaction']);
                log_transaction_activity($pdo, $authUser, $updated ?? $tx, 'Transaction Cancelled', 'Failed', 'Transaction was cancelled.', [
                    'updated_status' => $to,
                ]);
            } elseif ($to === 'Disputed') {
                log_transaction_activity($pdo, $authUser, $updated ?? $tx, 'Transaction Disputed', 'Failed', 'Transaction was marked as disputed.', [
                    'updated_status' => $to,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        Response::json($updated);
    }

    if ($method === 'DELETE') {
        require_authenticated_user($pdo, ['Admin']);
    }
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (PDOException $e) {
    database_error_response($e);
}

dispatch_crud_request($crud, 'transaction_id');
