# Database foundation

Verified locally: PHP 8.5.10/mysqli and MySQL 8.4.11/InnoDB. Source requires PHP 8.3+ and MySQL with enforced CHECK constraints (8.0.16+); verify the selected host before deployment. No Composer or database framework is required.

## Setup

Create a dedicated empty database using a private administrator connection:

```sql
CREATE DATABASE verdant_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'verdant_app'@'localhost' IDENTIFIED BY 'replace-with-your-app-password';
GRANT SELECT, INSERT, UPDATE ON verdant_dev.* TO 'verdant_app'@'localhost';
CREATE USER 'verdant_migrator'@'localhost' IDENTIFIED BY 'replace-with-your-migration-password';
GRANT SELECT, INSERT, UPDATE, CREATE, ALTER, INDEX, REFERENCES
    ON verdant_dev.* TO 'verdant_migrator'@'localhost';
```

Choose unique passwords. Application credentials cannot alter the schema or delete history. Replace the example names/host as required by your server. Supply DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD and optional DB_SOCKET through the process environment. `.env.example` lists placeholders; PHP does not load it automatically. Keep real configuration outside public/ and Git, with owner-only file permissions. Use the migration account for setup and the application account at runtime.

```sh
# From Verdant root; .env contains private, shell-quoted migration settings.
set -a
source .env
set +a
php database/bin/setup.php migrate
php database/bin/setup.php seed
# Add the representative catalogue fixture to this local demonstration database.
php database/bin/seed-catalogue.php apply
```

After applying migration 020, give the runtime account permission to prune only expired recovery rate-limit buckets. It still cannot delete customers, orders, products, or email history:

```sql
GRANT DELETE ON verdant_dev.auth_recovery_rate_limits TO 'verdant_app'@'localhost';
```

After applying migration 025, give the runtime account permission to remove allowlist entries only. It still cannot delete customers, orders, products, or email history:

```sql
GRANT DELETE ON verdant_dev.admin_allowlist TO 'verdant_app'@'localhost';
```

Migrations are ordered, checksum-tracked and serialized with a database advisory lock. Repeating an unchanged successful migration/seed reports Already applied. Changed applied SQL is rejected; create a new migration. The demo seed requires empty application tables and runs transactionally. It never overwrites existing records. The separate catalogue fixture is CLI-only and add-only: it records each inserted SKU or collision in `catalogue_fixture_ledger`, reports previously processed rows on replay, and does not change product, category, inventory or transaction data on replay. A collision is preserved and reported. Run seeds only for a local demonstration; accounts/passwords and payment references in demo.sql are synthetic, publicly documented fixtures.

MySQL DDL commits implicitly. A failed migration leaves a running ledger marker and possibly partial tables; automatic retry stops. Inspect the isolated database privately and recover from a clean backup or rebuild a disposable database, then reapply unchanged SQL. Do not clear a marker on a populated database without assessing partial effects. No destructive rebuild command is included in the runner.

## This workspace's isolated server

The phase verification server uses `.local/mysql84`, socket `/tmp/verdant-phase3.sock`, and disabled TCP/MySQL X networking. It does not use Homebrew's shared data directory. Ignored owner-only `.local/verdant_app.env` and `.local/verdant_migrator.env` contain generated credentials; corresponding `.cnf` files support private CLI access. These are machine-local setup, not portable application source.

To start the already initialized instance after it is stopped:

```sh
"$(brew --prefix mysql@8.4)/bin/mysqld" --no-defaults \
  --datadir="$PWD/.local/mysql84" --socket=/tmp/verdant-phase3.sock \
  --pid-file="$PWD/.local/mysql84.pid" --log-error="$PWD/.local/mysql84.log" \
  --skip-networking --mysqlx=0
```

Run in a separate terminal. Stop only this isolated instance:

```sh
mysqladmin --defaults-extra-file="$PWD/.local/root.cnf" shutdown
```

For a new machine use MySQL's initialization instructions with a fresh directory; never initialize over existing data. Root/server credentials are used only for local provisioning and server lifecycle.

## Verification

Create a separate `verdant_test_...` database with the same account grants, migrate it using the migration account, then run tests using the app account:

```sh
set -a
source .local/verdant_app.env
set +a
DB_NAME=verdant_test_phase003 php tests/database.php
```

Tests reject non-test database names, create synthetic fixtures in transactions and roll them back. They check actual constraints, prepared queries, UTF-8, archives, rollback and competing row locks. The schema has 42 application tables plus the migration ledger after migration 015 adds support conversations, recipient notifications, guest-access hashes and the email outbox. Cross-row capacity, payment amount/state, full-line issuance and cumulative-return rules belong to later domain services under the shared transaction boundary; CHECK constraints cannot enforce those across tables.

Timestamps use UTC database sessions; rental calendar dates use Africa/Johannesburg and an exclusive stored end date. Prices use integer cents. Runtime repositories receive the same mysqli connection; the service owns TransactionManager and never holds locks over a provider network call.

For setup safeguard checks, provide a separate empty test database with migration-account grants and run `php tests/setup.php`. It applies/recognizes migrations and the synthetic seed, checks checksum/dirty-state refusal, and restores its test ledger. Run this with the migration account, separately from runtime permission checks.
