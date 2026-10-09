# Librowse Book Exchange — ITS122P Final Project

An integrated, full-stack web platform for peer-to-peer book listing, purchasing, trading, and transaction management, built for **ITS122P — Web Systems and Technologies**.

---

## Group Members

- **Advincula, Jordan Christopher**
- **Domingo, Nicholas Reuben**
- **Lualhati, Jethro Lee**
- **Medina, Gabriel Aze**
- **Riovaldez, Miguel**

---

## Key Features

- **Role-Based Access Control (RBAC)**: Secure access tiers for **Customer**, **Staff**, and **Administrator**.
- **Customer Portal**: Browse books, search and filter by category/condition, list personal books for sale or trade, request refunds, file moderation reports, and view activity metrics via the dedicated **Customer Dashboard** (`customer-dashboard.html`).
- **Staff Panel (`staff.html`)**: Manage customer statuses and permissions, approve/reject customer verification forms and seller applications, review moderation reports, mediate transactions, and process refund requests.
- **Admin Panel (`admin.html`)**: Full CRUD over users, categories, master book catalog, system records, transactions, and site-wide reports.
- **Transaction & Dispute Lifecycle**: Multi-state transaction progression (`Pending`, `Accepted`, `Completed`, `Cancelled`, `Disputed`) with audit tracking.
- **Dual-Mode Deployment**: Runs seamlessly on local development environments (PHP built-in server + MySQL) and serverless cloud hosting (**Vercel** + **TiDB Cloud** via TLS/SSL).

---

## Repository Structure

```text
its122p-final-project/
├── api/                             # Serverless entrypoints for Vercel deployment
│   ├── auth.php
│   ├── books_catalog.php
│   ├── book_categories.php
│   ├── index.php
│   ├── refund_request.php
│   ├── reports.php
│   ├── system_records.php
│   ├── transactions.php
│   ├── user.php
│   └── user_books.php
├── book-marketplace-backend/        # PHP REST API Backend
│   ├── api/                         # Backend API handlers
│   ├── config/                      # Database configuration & TLS certificate (isrgrootx1.pem)
│   ├── data/                        # XML session backup & security rules
│   ├── lib/                         # Bootstrap, Crud engine, dispatch router, response, security
│   ├── sql/                         # Seed data & queries
│   ├── BOOK DATABASE/               # Database schema spreadsheets, queries, populated data
│   ├── REMOVE_ON_SUBMISSION/        # Submission schema & reference documentation
│   ├── books.xml                    # Master XML book database
│   ├── index.php                    # Backend health check & route directory
│   ├── setup.php                    # One-click local database installer
│   └── vercel.json                  # Standalone backend Vercel configuration
├── book-marketplace-frontend/       # Client-side Web Application
│   ├── assets/                      # Diagrams & static images (ERD.png)
│   ├── admin.html                   # Administrator management dashboard
│   ├── staff.html                   # Staff moderation & verification dashboard
│   ├── customer-dashboard.html      # Customer personal activity & metrics dashboard
│   ├── index.html                   # Marketplace home landing page
│   ├── browse.html                  # Catalog browsing & filtering
│   ├── list-book.html               # Book listing form with validation
│   ├── transactions.html            # User transaction history
│   ├── support.html                 # Refund request & issue reporting
│   ├── login.html                   # User authentication login
│   ├── register.html                # User registration
│   ├── auth.js                      # Authentication & token management
│   ├── script.js                    # Marketplace client logic
│   ├── management.js                # Admin & Staff dashboard logic
│   ├── customer-dashboard.js        # Customer dashboard metrics
│   ├── session.js                   # Client-side session & role guard
│   ├── style.css                    # Marketplace stylesheet
│   └── management.css               # Management dashboard stylesheet
├── docs/                            # Project Documentation
│   ├── ProjectDraft.md              # Original project specification & requirements draft
│   └── ADMIN_STAFF_GUIDE.md         # Guide to Admin and Staff management panels
├── PROJECT_OVERVIEW_AND_DEMO_GUIDE.md # Comprehensive demo presentation & architecture guide
├── vercel.json                      # Root Vercel configuration (routing, rewrites, PHP runtime)
└── README.md                        # Project documentation (this file)
```

---

## Quick Start & Local Setup

### 1. Prerequisites
- **PHP 8.0+** with PDO MySQL enabled
- **MySQL 8.0+** (or TiDB Cloud instance)
- A modern web browser

### 2. Configure Database Credentials
Copy `.env.example` in `book-marketplace-backend/` to `.env` or `.env.local`:
```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=book_marketplace
DB_USER=root
DB_PASS=
DB_SSL=false
```
*(If connecting to TiDB Cloud, set `DB_SSL=true` or use a host ending in `tidbcloud.com`)*.

### 3. Initialize the Database
- **Option A (One-Click Setup via Browser)**:
  Start the PHP backend server:
  ```bash
  php -S 127.0.0.1:8000 -t book-marketplace-backend
  ```
  Visit `http://127.0.0.1:8000/setup.php` in your browser. This will automatically create the tables and seed default users, catalog items, listings, transactions, and categories.
- **Option B (MySQL CLI)**:
  Import `book-marketplace-backend/REMOVE_ON_SUBMISSION/schema.sql` and `book-marketplace-backend/sql/queries.sql`.

### 4. Run the Application
1. **Start the Backend**:
   ```bash
   php -S 127.0.0.1:8000 -t book-marketplace-backend
   ```
2. **Open the Frontend**:
   Open `book-marketplace-frontend/index.html` in your browser, or serve it via VS Code Live Server / local HTTP server. The frontend automatically detects local environments and communicates with `http://127.0.0.1:8000/api`.

---

## Demo Accounts (Seeded Data)

| Role | Username | Password | Default Landing Page |
|---|---|---|---|
| **Admin** | `alice_wong` | `password` | `admin.html` |
| **Staff** | `priya_singh` | `password` | `staff.html` |
| **Customer** | `emma_clarke` | `password` | `customer-dashboard.html` |
| **Customer** | `liam_smith` | `password` | `customer-dashboard.html` |

---

## Vercel Deployment

This repository is pre-configured for one-click deployment on **Vercel**:
- `vercel.json` maps incoming requests to static frontend files in `book-marketplace-frontend/` and API endpoints via the `vercel-php@0.9.0` runtime.
- For production database connectivity, configure the following Environment Variables in your Vercel Project Settings:
  - `DB_HOST`: Your TiDB Cloud / MySQL host
  - `DB_PORT`: `4000` (or `3306`)
  - `DB_NAME`: `book_marketplace`
  - `DB_USER`: Database username
  - `DB_PASS`: Database password
  - `DB_SSL`: `true`

---

## Additional Documentation

- [Project Overview & Demo Presentation Guide](PROJECT_OVERVIEW_AND_DEMO_GUIDE.md)
- [Admin & Staff Guide](docs/ADMIN_STAFF_GUIDE.md)
- [Project Draft & Functional Specification](docs/ProjectDraft.md)
