# Librowse Book Exchange — Project Overview & Demo Presentation Guide
**Course:** ITS122P — Web Systems and Technologies  
**Project:** Librowse Book Exchange Web Application  
**Architecture:** Client-Server / RESTful API / MySQL Relational Database / XML Session Registry  

---

## 1. Executive Summary

### 1.1 Problem Statement
Informal book exchanges and peer-to-peer sales (conducted via social media groups, chat threads, or physical pinboards) suffer from recurring operational issues:
- Absence of a centralized, real-time inventory catalog.
- High friction in finding matching buyers, sellers, or trading partners.
- Inconsistent and unmonitored transaction state progression (leading to lost orders and scam risks).
- Lack of formal dispute resolution, refund processing, and condition verification.

### 1.2 Solution: Librowse Platform
The **Librowse Book Exchange** is an integrated web platform delivering an end-to-end marketplace tailored for academic and general book communities. It features:
- **Role-Based Access Control (RBAC)** across three distinct personas: **Customer**, **Staff**, and **Administrator**.
- **Real-Time Catalog & Inventory Synchronization** enabling multi-category browsing, pricing, and book condition ratings (`New`, `Good`, `Acceptable`).
- **Transaction Lifecycle Engine** tracking purchases and trades through distinct operational states (`Pending`, `Accepted`, `Completed`, `Cancelled`, `Disputed`).
- **Comprehensive Audit & Governance Infrastructure** with integrated refund handling, moderation reporting, and automated administrative audit logs.

---

## 2. System Architecture & Tech Stack

```
                                      +------------------------------------+
                                      |          Web Browser (Client)      |
                                      |  HTML5 / CSS3 / Vanilla JS (ES6+)  |
                                      +-----------------+------------------+
                                                        |
                                       HTTP Requests    | Bearer Token Header
                                      (JSON Payloads)   | (sessionStorage)
                                                        v
                                      +-----------------+------------------+
                                      |         PHP 8+ Backend API         |
                                      |  /api/*.php REST Endpoints         |
                                      +--------+-----------------+---------+
                                               |                 |
                   PDO Prepared Queries / SQL  |                 | Read / Write
                                               v                 v
                    +--------------------------+----+   +--------+---------+
                    |        MySQL 8+ Database      |   |  XML Session     |
                    | (InnoDB, FKs, Transactions)   |   |  Registry        |
                    |                               |   |  (security.xml)  |
                    +-------------------------------+   +------------------+
```

### Front-End Layer
- **Semantic HTML5**: Modular views partitioned by access tier (`index.html`, `browse.html`, `customer-dashboard.html`, `transactions.html`, `support.html`, `admin.html`, `staff.html`).
- **Responsive CSS3**: CSS Grid and Flexbox layouts, status badges (`badge-green`, `badge-yellow`, etc.), summary metric cards, and accessible forms.
- **Vanilla JavaScript (ES6+)**: Token management (`session.js`), authentication routing (`auth.js`), dynamic client-side filtering and transaction state handling (`script.js`), real-time personal metric calculations (`customer-dashboard.js`), and administrative management interfaces (`management.js`).

### Back-End Layer
- **PHP 8+ REST Services**: Standardized JSON responses via `Response.php`, centralized HTTP method routing via `dispatch.php`, and a generic CRUD abstraction engine in `Crud.php`.
- **Prepared Statements (PDO)**: 100% parameterized SQL queries preventing SQL Injection attacks.
- **XML Session Security Engine (`security.php`)**: Bearer session tokens hashed with SHA-256 and persisted in an XML store (`security.xml`) with file-locking (`flock`) concurrency and automatic time-to-live expiration.

### Database Layer (MySQL 8+ InnoDB)
- **`USER`**: Account credentials (Bcrypt hashes), operational status (`Active`, `Suspended`, `Banned`, `Pending Verification`), roles (`Customer`, `Staff`, `Admin`), and granular JSON permissions.
- **`BOOK_CATEGORIES`**: Master categorization with admin audit reference.
- **`BOOKS_CATALOG`**: Canonical master book catalog (ISBN, title, author).
- **`BOOK_CATEGORY_MAP`**: Associative junction table supporting many-to-many relationships between books and categories.
- **`USER_BOOKS`**: Seller inventory entries with listing types (`For_sale`, `For_trade`, `Both`), price, condition, and availability status.
- **`TRANSACTIONS`**: Ledger for purchase and trade agreements linking buyer, requested item, offered trade item, assigned staff mediator, and state.
- **`REFUND_REQUEST`**: Customer post-transaction refund petitions and staff decision logging.
- **`REPORTS`**: Moderation tickets covering verification applications, user violations, listing disputes, and general feedback.
- **`SYSTEM_RECORDS`**: Tamper-evident admin audit logs and financial ledger entries.

---

## 3. Function & Module Index

### 3.1 Backend Architecture Functions

#### Security & Authentication (`book-marketplace-backend/lib/security.php`)
- `issue_auth_token(array $user): string`: Issues a 64-character random hex token, records the SHA-256 hash in XML with expiration timestamp.
- `bearer_token_from_request(): ?string`: Parses HTTP `Authorization: Bearer <token>` headers.
- `current_authenticated_user(PDO $pdo): ?array`: Resolves valid bearer tokens against active XML sessions and fetches database user attributes.
- `require_authenticated_user(PDO $pdo): array`: Route guard terminating unauthorized API requests with HTTP 401.
- `revoke_auth_token(?string $token): bool`: Removes token upon user sign-out.
- `security_sessions()` & `save_security_sessions(array $sessions)`: Thread-safe XML reader/writer using exclusive file locking (`flock(..., LOCK_EX)`).

#### Generic CRUD Engine (`book-marketplace-backend/lib/Crud.php`)
- `index(array $queryParams): array`: Queries resource table dynamically using query parameter filters (`?status=Active&role=Customer`), supporting pagination (`limit`, `offset`).
- `show(mixed $id): ?array`: Fetches single record by primary key.
- `create(array $input): array`: Validates input against whitelists, enforces required columns and ENUM values, inserts via PDO prepared statements, and returns the created record.
- `update(mixed $id, array $input): ?array`: Computes partial SQL UPDATE statements safely and returns the updated entity.
- `delete(mixed $id): bool`: Executes parameterized DELETE by primary key.

#### Dispatch & Routing (`book-marketplace-backend/lib/dispatch.php`)
- `dispatch_crud_request(Crud $crud, string $primaryKeyName): void`: Inspects HTTP method (`GET`, `POST`, `PUT`, `DELETE`, `OPTIONS`), parses JSON payloads via `read_json_body()`, enforces authentication, and routes to the matching CRUD operation.
- `Response::json(mixed $data, int $statusCode = 200)`: Serializes structured payloads with UTF-8 JSON headers.
- `Response::error(string $message, int $statusCode = 400)`: Dispatches standardized error envelopes `{ "error": "..." }`.

#### Backend API Endpoints (`book-marketplace-backend/api/`)
- `auth.php`: Implements `action=login`, `action=register`, `action=me`, and `action=logout`.
- `books_catalog.php`: Catalog endpoints with category relationship sync (`extract_category_ids`, `fetch_category_ids`, `save_category_map`).
- `book_categories.php`: CRUD operations for book categories.
- `user_books.php`: CRUD for user listings in personal inventory.
- `transactions.php`: CRUD for order tracking and trade requests.
- `refund_request.php`: CRUD for transaction refunds.
- `reports.php`: CRUD for dispute reports, seller applications, and feedback.
- `system_records.php`: Audit log and financial entries.
- `user.php`: User management endpoints.

---

### 3.2 Frontend Client Functions

#### Session & Auth Client (`session.js` & `auth.js`)
- `saveSession(user, token)` / `getToken()` / `getUser()`: Manages session storage synchronization.
- `validateSession()`: Verifies token validity against `/api/auth.php?action=me` on page load.
- `requireRole(allowedRoles)`: Protects restricted views from unauthorized role access.
- `logout()`: Cleans up local state, calls backend logout, and redirects to login view.
- `handleLogin(e)`: Form event listener verifying credentials and routing user to their role-specific dashboard.
- `handleRegister(e)`: Client validation (regex checks, password match) and registration dispatch.

#### Marketplace Operations (`script.js`)
- `loadBooks()` & `renderBooks()`: Renders interactive book listing cards with prices and seller information.
- `filterBooks()`: Real-time multi-attribute search filtering by query string, categories, price range, and condition.
- `submitBookListing(e)`: Form handler to list a book for sale or trade.
- `buyBook(inventoryId)`: Creates a `Purchase` transaction.
- `tradeBook(inventoryId)`: Opens trade dialog, pairs user's offered inventory item, and submits a `Trade` transaction.
- `loadTransactions()` & `cancelTransaction(id)`: Fetches user orders and cancels eligible pending transactions.
- `submitRefund(e)` & `submitReport(e)`: Submits customer refund requests and dispute/verification forms.

#### Customer Dashboard (`customer-dashboard.js`)
- Aggregates live user metrics:
  - **Listings Summary**: Total, Active (`Available`), Sold/Traded, and Pending.
  - **Orders Summary**: Total orders, Pending, Completed, Cancelled.
  - **Support Summary**: Active refunds and submitted reports.
  - **Inventory Table**: Real-time tabular display of current user's listings.

#### Administration & Staff Management (`management.js`)
- `initManagementPage(pageRole)`: Initializes tab navigation and loads metrics.
- `renderDashboardStats()`: Aggregates top-level cards (Total Users, Active Listings, Open Disputes, etc.).
- `renderUsersTable()`, `saveUser(id)`, `deleteUser(id)`: Allows Admins to elevate roles, ban users, or update status.
- `renderBooksTable()`, `submitBookForm()`, `deleteBook(id)`: Master catalog creation and multi-category tagging.
- `renderCategoriesTable()`, `submitCategoryForm()`, `deleteCategory(id)`: Category management.
- `renderTransactionsTable()`, `saveTransaction(id)`: Transaction progression and mediator assignment.
- `renderReportsTable()`, `saveReport(id)`: Dispute moderation and seller verification review.
- `renderRefundsTable()`, `saveRefund(id)`: Staff processing of customer refund requests.
- `renderRecordsTable()`, `submitRecordForm()`: Admin inspection and creation of system audit records.
- `submitStaffIssue(e)`: Dedicated channel for staff members to report operational anomalies to administrators.

---

## 4. Role Permission Matrix

| Capability | Customer | Staff | Administrator |
|---|:---:|:---:|:---:|
| Browse & Search Books | Yes | Yes | Yes |
| List Personal Books (Sale / Trade) | Yes | No | No |
| Purchase / Initiate Trade | Yes | No | No |
| View Personal Dashboard & History | Yes | No | No |
| Submit Refund Request | Yes | No | No |
| Submit Dispute / Violation / Feedback | Yes | No | No |
| Review & Approve / Reject Refunds | No | Yes | No |
| Review Customer Forms & Moderation | No | Yes | Yes |
| Update Transaction Statuses | No | Yes | Yes |
| Submit Internal Issue to Admin | No | Yes | No |
| Manage User Roles & Accounts | No | No | Yes |
| Master Catalog & Category Management | No | No | Yes |
| System Records & Audit Logs | No | No | Yes |

---

## 5. Live Demonstration & Presentation Script

Use this step-by-step presentation script to walk your professor through a full system evaluation.

### Pre-Configured Test Credentials
| Role | Username | Password | Default Destination |
|---|---|---|---|
| **Administrator** | `alice_wong` | `password` | `admin.html` |
| **Staff Moderator** | `priya_singh` | `password` | `staff.html` |
| **Customer** | `emma_clarke` | `password` | `customer-dashboard.html` |

---

### Act 1: The Customer Experience (Buyer & Seller)
1. **Sign-In & Dynamic Role Routing**:
   - Open `login.html`.
   - Enter `emma_clarke` / `password`.
   - Highlight that the system automatically detects her `Customer` role and redirects to `customer-dashboard.html`.
2. **Review Customer Dashboard**:
   - Showcase the real-time summary cards: Total Listings, Active, Sold/Traded, and Pending.
   - Point out the personal listings table displaying book title, condition, pricing, and availability.
3. **Marketplace Discovery & Filtering**:
   - Navigate to `browse.html`.
   - Demonstrate client-side search filtering by typing a keyword (e.g. "Silent").
   - Filter by condition (`Good`, `New`) and toggle category checkboxes (`Fiction`, `Science Fiction & Fantasy`).
4. **Order Placement**:
   - Locate an available book listing and click **Buy** or **Trade**.
   - Show how the transaction is posted directly to `/api/transactions.php`.
   - Navigate to `transactions.html` to confirm the order appears in `Pending` state.
5. **Support & Disputes**:
   - Navigate to `support.html`.
   - Submit a sample refund claim or report ticket (e.g. `Listing_Dispute` or `General_Feedback`).

---

### Act 2: The Staff Experience (Moderation & Operations)
1. **Sign-In as Staff**:
   - Log out, then log in using `priya_singh` / `password`.
   - System immediately routes to `staff.html`.
2. **Review Customer Forms & Reports**:
   - Navigate to the **Customer Forms** / **Reports** tab.
   - Inspect the dispute or verification report filed in Act 1.
   - Add resolution notes and update status from `Pending` to `Under_Review` or `Resolved`.
3. **Transaction State Progression**:
   - Open the **Transactions** tab.
   - Find Emma's pending transaction. Update status to `Accepted` or `Completed`.
4. **Refund Processing**:
   - Open the **Refunds** tab.
   - Inspect pending refund requests and change status to `Approved` or `Rejected`.
5. **Internal Incident Escalation**:
   - Open the **Report an Issue** tab.
   - Demonstrate how staff members submit operational feedback directly to administrators.

---

### Act 3: The Administrator Experience (System Governance)
1. **Sign-In as Administrator**:
   - Log in using `alice_wong` / `password`.
   - System routes to `admin.html`.
2. **User Account Governance**:
   - Open the **Users** tab.
   - Demonstrate elevating a user to `Staff` or updating user status to `Suspended`/`Active`.
3. **Master Catalog & Category CRUD**:
   - Open **Book Categories**: add a new category (e.g., "Technology & Computing").
   - Open **Book Catalog**: register a new book title with ISBN, author, and assign it multiple categories via the many-to-many relationship.
4. **Audit Trail & System Records**:
   - Open **System Records**.
   - Show the immutable audit trail verifying financial transactions and administrative activities.

---

## 6. Technical Defense Q&A for Professor Evaluation

**Q: How does the system defend against SQL Injection?**  
> **A:** All database interaction in `Crud.php` and `api/*.php` is strictly executed using PDO prepared statements with named or positional parameter bindings. User inputs are never directly concatenated into SQL queries.

**Q: How does session management work without relying on third-party frameworks?**  
> **A:** When a user logs in, `issue_auth_token()` produces a cryptographically secure 64-character token. The raw token is delivered to the browser and stored in `sessionStorage`. On the server, only the SHA-256 hash of the token is persisted inside the XML session store (`data/security.xml`) with an expiration timestamp. Every protected request includes the token via the `Authorization: Bearer` header and is validated in `security.php`.

**Q: How is database referential integrity maintained?**  
> **A:** Foreign key constraints (`ON DELETE RESTRICT` and `ON DELETE CASCADE`) are explicitly enforced across all relational tables. For instance, `BOOK_CATEGORY_MAP` entries cascade when a book is removed, while transactions restrict deletion of associated inventory or customer records.

**Q: Why was a separate customer dashboard introduced?**  
> **A:** Separating the customer dashboard (`customer-dashboard.html`) from the administrative interfaces provides clear separation of concerns. Customers receive an intuitive view of their listings, transactions, and support tickets, while Staff and Admin have dedicated panels with elevated operational capabilities.
