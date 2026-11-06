# Verdant Studio

> Botanical nursery, retail e-commerce, and scheduled plant rental platform.

Verdant Studio is a complete botanical retail and plant rental web application built in pure PHP and MySQL. It delivers a fast, dependency-free architectural foundation with strict domain separation between customer shopping and administrative store management.

---

## Live Deployments

The live application is hosted on **X10 Hosting**, fronted by **Cloudflare**, with transactional emails delivered via **Resend** and payments handled via **Paystack**:

| Surface | Primary Domain | Alternative Domain (X10 Hosting) |
| --- | --- | --- |
| **Customer Storefront** | [https://verdant.thisisphila.co.za](https://verdant.thisisphila.co.za) | [https://verdant.x10.mx/](https://verdant.x10.mx/) |
| **Administration Console** | [https://admin.verdant.thisisphila.co.za](https://admin.verdant.thisisphila.co.za) | [https://admin.verdant.x10.mx/](https://admin.verdant.x10.mx/) |

---

## Highlights

- **Dual-Surface Architecture**: Dedicated customer storefront (`verdant.localhost:8088`) and administrative console (`admin.verdant.localhost:8088`) driven from a single codebase and database.
- **Botanical Catalogue**: Filterable plant catalogue with size variants, light/water requirements, curated pots, and compatibility pairings.
- **Retail Sales & Scheduled Rentals**: Unified inventory engine powering both retail checkouts and time-bounded plant rental reservations.
- **Curated Compositions**: Pre-arranged plant and planter pairings for home and office environments.
- **Secure Administration**: Protected console with TOTP-based two-factor authentication (2FA), inventory auditing, order fulfilment, and rental return logging.
- **Payments & Reconciliation**: Paystack test checkout integration with server-side webhook verification.
- **Pure Native Implementation**: Built with zero external PHP dependencies or bloated frameworks — fast, auditable, and resilient.

---

## Architecture Overview

```
verdant-studio/
├── app/               # Application domain services, controllers, identity & views
│   ├── Admin/         # Administration console logic & navigation
│   ├── Catalogue/     # Product, category, variant & compatibility services
│   ├── Compositions/  # Botanical styling & arrangement models
│   ├── Identity/      # Authentication, password recovery & TOTP 2FA
│   ├── Inventory/     # Physical stock control & reservation engine
│   ├── Notifications/ # Transactional email queue & templating
│   ├── Payments/      # Paystack checkout & webhook handling
│   ├── Rentals/       # Scheduled rental bookings & return workflows
│   ├── Retail/        # Cart, orders & checkout services
│   ├── Storefront/    # Customer pages & content presentation
│   └── Views/         # Modular PHP templates & layouts
├── config/            # Application configuration & trusted origins
├── database/          # SQL migrations, data fixtures & bin setup utilities
├── public/            # Web server document root (storefront, router & assets)
└── tests/             # Automated test suite (PHP & Python HTTP tests)
```

---

## Getting Started

### Prerequisites

- **PHP 8.2+** (tested on PHP 8.3 & 8.5) with `pdo_mysql`, `session`, `json`, and `mbstring` extensions.
- **MySQL 8.0+** (or MariaDB 10.5+) with support for `CHECK` constraints.
- **Python 3.10+** (optional, for automated HTTP integration tests).

### 1. Configure the Environment

Copy the example environment file and adjust your database connection credentials:

```bash
cp .env.example .env
```

### 2. Set Up the Database

Create a MySQL database and run the migrations and initial seeds:

```bash
set -a
source .env
set +a

# Apply migrations
php database/bin/setup.php migrate

# Seed initial store configuration
php database/bin/setup.php seed

# Seed demonstration botanical catalogue
php database/bin/seed-catalogue.php apply
```

For advanced database configuration, see [database/README.md](database/README.md).

### 3. Launch the Application

Start the local PHP development server:

```bash
APP_ENV=local php -S 127.0.0.1:8088 -t public public/router.php
```

Once running, access:
- **Storefront**: [http://127.0.0.1:8088/](http://127.0.0.1:8088/) or [http://verdant.localhost:8088/](http://verdant.localhost:8088/)
- **Admin Console**: [http://admin.verdant.localhost:8088/](http://admin.verdant.localhost:8088/)

---

## Demonstration Credentials

The seeded development database includes test accounts:

| Role | Username / Email | Password | Notes |
| --- | --- | --- | --- |
| **Customer** | `customer@example.com` | `VerdantDemo!2026` | Compatible with Paystack TEST checkout |
| **Administrator** | Configured via seed | Generated/Configured | Requires TOTP setup on first access |

---

## Testing

Run the automated test suites from the project root:

```bash
# Run individual PHP service tests
php tests/catalogue.php
php tests/cart.php
php tests/rentals.php

# Run full HTTP integration verification
python3 tests/verify.py
```

---

## License

This project is licensed under the MIT License - see the LICENSE file for details.
