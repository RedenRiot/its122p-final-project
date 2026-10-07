# Librowse Book Exchange — Project Overview & Demo Guide

**Course:** ITS122P — Web Systems and Technologies  
**Project:** Librowse Book Exchange Web Application  
**Tech Stack:** HTML5 / CSS3 / Vanilla JavaScript / PHP 8+ / MySQL / XML Sessions  

---

## 1. Project Overview

### 1.1 The Problem
Buying and trading used books through chat groups or social media is unorganized and risky:
- No centralized catalog or live inventory tracking.
- Hard to find matching buyers, sellers, or trade partners.
- No clear order status tracking (leading to missed deals or lost items).
- No built-in way to report issues or request refunds.

### 1.2 The Solution
**Librowse** is a web-based book exchange platform that gives users a single place to buy, sell, and trade books with built-in moderation:
- **Role-Based Access (RBAC):** Three user roles: **Customer**, **Staff**, and **Administrator**.
- **Searchable Catalog:** Browse listings with instant filters for category, price, and condition (`New`, `Good`, `Acceptable`).
- **Order & Trade Tracking:** Clear status lifecycle (`Pending`, `Accepted`, `Completed`, `Cancelled`, `Disputed`).
- **Support & Moderation:** Built-in refund requests, dispute reports, and staff review tools.

---

## 2. System Architecture

```text
[ Browser (Client) ]
  HTML5 / CSS3 / Vanilla JavaScript (ES6+)
       │
       │ HTTP Requests (JSON Payloads + Bearer Token)
       ▼
[ PHP 8+ Backend API ]
  REST Endpoints (/api/*.php) + PDO Prepared Statements
       │                          │
       ▼ (SQL Queries)            ▼ (File Locking)
[ MySQL 8+ Database ]      [ XML Session Store ]
  Tables & Relationships     (data/security.xml)
```

### 2.1 Technology Breakdown

- **Frontend (Client-side):**
  - **HTML5 & CSS3:** Semantic markup and responsive layout (Grid & Flexbox) with status badges and summary cards.
  - **Vanilla JavaScript:** Clean modular scripts without heavy frameworks. Manages session tokens in `sessionStorage`, handles real-time catalog filtering, and connects forms to API endpoints.

- **Backend (Server-side):**
  - **PHP 8+ REST API:** Standardized JSON responses via `Response.php` and centralized request dispatching via `dispatch.php`.
  - **PDO Prepared Statements:** Parameterized queries to prevent SQL injection.
  - **XML Session Storage:** Session tokens are hashed (SHA-256) and saved in `security.xml` with file locking (`flock`) to prevent race conditions.

- **Database (MySQL 8+ InnoDB):**
  - `USER`: Accounts, roles (`Customer`, `Staff`, `Admin`), statuses, and password hashes.
  - `BOOKS_CATALOG`: Master catalog of book titles, authors, and ISBNs.
  - `BOOK_CATEGORIES` & `BOOK_CATEGORY_MAP`: Category definitions and many-to-many book tags.
  - `USER_BOOKS`: Seller inventory with condition ratings, price, and listing type (`For_sale`, `For_trade`, `Both`).
  - `TRANSACTIONS`: Purchases and trades linking buyer, seller, items, and status.
  - `REFUND_REQUEST`: Customer refund tickets with staff approval tracking.
  - `REPORTS`: Dispute reports, seller verification tickets, and user feedback.
  - `SYSTEM_RECORDS`: Administrative audit logs and system activity tracking.

---

## 3. Key Modules & Capabilities

### 3.1 Backend Services

- **Authentication & Security (`security.php`):**
  - Issues 64-character random session tokens upon login.
  - Stores token SHA-256 hashes in `security.xml` with expiration dates.
  - Validates `Authorization: Bearer <token>` headers on protected endpoints.
- **CRUD Engine (`Crud.php`):**
  - Handles standard database operations (read, create, update, delete) safely with parameter binding and column whitelisting.
- **API Endpoints (`/api/`):**
  - `auth.php`: Login, register, logout, and current user validation.
  - `books_catalog.php` & `book_categories.php`: Master catalog and category mapping.
  - `user_books.php`: Personal inventory listings for sellers.
  - `transactions.php`: Placing orders, creating trades, and updating status.
  - `refund_request.php` & `reports.php`: Customer support claims and disputes.
  - `user.php` & `system_records.php`: User management and audit logs.

### 3.2 Frontend Interfaces

- **Auth & Session (`auth.js`, `session.js`):**
  - Verifies logins, stores tokens in `sessionStorage`, and routes users to their role-specific view.
- **Marketplace Browsing (`script.js`):**
  - Displays book cards and filters live by search keyword, category, price, and condition.
  - Handles buying an item or submitting a trade proposal.
- **Customer Dashboard (`customer-dashboard.js`):**
  - Displays personal activity counters (Total Listings, Active, Sold, Pending Orders) and user inventory.
- **Staff & Admin Management (`management.js`):**
  - **Staff view (`staff.html`):** Manage transactions, review customer reports, and approve/reject refunds.
  - **Admin view (`admin.html`):** Full control over user accounts, master book catalog, categories, and audit records.

---

## 4. Role Permissions Matrix

| Feature | Customer | Staff | Administrator |
|---|:---:|:---:|:---:|
| Browse & Search Catalog | Yes | Yes | Yes |
| List Personal Books (Sale/Trade) | Yes | No | No |
| Purchase or Trade Books | Yes | No | No |
| View Personal Dashboard | Yes | No | No |
| Submit Refund or Report Ticket | Yes | No | No |
| Review & Process Refunds | No | Yes | No |
| Moderate Reports & Disputes | No | Yes | Yes |
| Update Transaction Status | No | Yes | Yes |
| Submit Internal Staff Issue | No | Yes | No |
| Manage User Roles & Accounts | No | No | Yes |
| Edit Master Books & Categories | No | No | Yes |
| View System Audit Records | No | No | Yes |

---

## 5. Live Demonstration Guide

### Demo Test Accounts

| Role | Username | Password | Default Page |
|---|---|---|---|
| **Customer** | `emma_clarke` | `password` | `customer-dashboard.html` |
| **Staff Moderator** | `priya_singh` | `password` | `staff.html` |
| **Administrator** | `alice_wong` | `password` | `admin.html` |

---

### Step-by-Step Demo Walkthrough

#### Step 1: Customer Journey (Buy, Trade, and Dashboard)
1. **Login:** Go to `login.html`, log in as `emma_clarke` / `password`.
   - The system detects the `Customer` role and redirects to `customer-dashboard.html`.
2. **Customer Dashboard:** Review the live summary cards (Listings, Orders, Refunds) and personal inventory table.
3. **Browse & Filter:** Go to `browse.html`.
   - Search for a book (e.g., "Silent").
   - Filter by condition (`Good`, `New`) and category tags (`Fiction`).
4. **Place an Order:** Click **Buy** or **Trade** on an available listing.
5. **Check Orders:** Open `transactions.html` to verify the order is created in `Pending` status.
6. **Support Ticket:** Go to `support.html` and submit a sample dispute or refund request.

#### Step 2: Staff Moderation (Orders, Disputes, and Refunds)
1. **Login:** Log out and log in as `priya_singh` / `password`.
   - The app opens `staff.html`.
2. **Review Reports:** Open the **Customer Forms / Reports** tab, view the dispute from Step 1, and update it to `Under_Review` or `Resolved`.
3. **Advance Transaction:** Open the **Transactions** tab, locate the pending order, and update it to `Accepted` or `Completed`.
4. **Process Refund:** Open the **Refunds** tab and approve or reject a customer refund request.
5. **Staff Escalation:** Open **Report an Issue** to show how staff report internal problems directly to admins.

#### Step 3: Admin Management (Users, Catalog, and Audit Logs)
1. **Login:** Log out and log in as `alice_wong` / `password`.
   - The app opens `admin.html`.
2. **User Accounts:** Open the **Users** tab to view all accounts, change roles, or toggle account status.
3. **Master Catalog & Categories:**
   - Under **Book Categories**, add a new category.
   - Under **Book Catalog**, add a new book and link it to categories.
4. **Audit Trail:** Open **System Records** to show recorded administrative and system actions.

---

## 6. Technical Defense Q&A

**Q: How does the app prevent SQL Injection?**  
> **A:** All database queries in `Crud.php` and individual API endpoints use PDO prepared statements with bound parameters. User inputs are never directly concatenated into SQL strings.

**Q: How do sessions work without third-party libraries or frameworks?**  
> **A:** When a user logs in, the backend generates a random 64-character token. The client stores this in `sessionStorage` and sends it in the `Authorization: Bearer` header. The server stores only the SHA-256 hash in `data/security.xml` with an expiration date and verifies it on protected requests.

**Q: How is database referential integrity protected?**  
> **A:** Foreign key constraints (`ON DELETE RESTRICT` and `ON DELETE CASCADE`) are set on all relational tables. For example, deleting a book cascades to its category mappings, while active transactions prevent deleting referenced inventory items.

**Q: Why have a separate customer dashboard instead of one general page?**  
> **A:** To keep responsibilities clean. Customers have a focused view of their personal listings, orders, and tickets (`customer-dashboard.html`), while Staff (`staff.html`) and Admins (`admin.html`) have specialized tools for moderation and governance.
