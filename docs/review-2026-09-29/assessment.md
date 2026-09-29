# wp-lock analysis review: identified issues

Date: 2026-09-29. Reviewed code: `26d3a07ca773c61138dc881f4a0c6ac922f37617`, version 2.0.0.
This is the same commit referenced by the [original analysis](https://chatgpt.com/share/6abb8b17-14cc-83ed-9589-0e50a76e616f).

Conclusion: the analysis identifies real limitations, but its explanations vary in accuracy. The acquisition race was reproduced on both database engines under READ COMMITTED. The absence of a UNIQUE constraint alone does not prove that race: the tested concurrent scenarios passed under REPEATABLE READ. The cleanup race claim needs to be replaced with specific owner liveness check failures. Fixes are necessary; a complete redesign before establishing the configuration and contract would be premature.

The library does not currently guarantee mutual exclusion across all configurations covered by the README's "MySQL-compatible database" requirement. Even correct acquisition provides exclusion only while the lease remains valid and all participants follow the protocol. Credit accounting requires its own guarantees for writes and repeated operations.

## Review of the original claims

| Claim | Verdict | Evidence and qualification |
| --- | --- | --- |
| No UNIQUE constraint means two WRITE acquisitions can succeed | Outcome confirmed; explanation incomplete | WRITE/WRITE and READ/WRITE both succeed concurrently under READ COMMITTED. This did not occur in our REPEATABLE READ runs. The SQL, InnoDB behavior, and isolation level matter alongside the index. |
| A simple UNIQUE(lock_key) constraint would fix the problem | The original analysis correctly rejects this fix | Multiple legitimate READ owners share a key; a UNIQUE constraint on the owner table would prohibit them. |
| TTL permits overlapping critical sections | Confirmed | After expiration, another object acquires WRITE while the original object still considers itself held. There is no renewal or notification of ownership loss. |
| expiration=0 solves long-running operations | It only removes time-based expiration | Cleanup failures, connection and transaction dependencies, and repeated business operations remain concerns. |
| Selecting cleanup candidates and then deleting by id races with a new owner | Not established for the current protocol | Owner rows are neither renewed nor reassigned. A new acquisition receives a different AUTO_INCREMENT id; releasing the old acquisition preserves the new owner's row. The gap between these queries alone does not establish the claimed failure. |
| PID and CID are unreliable | Confirmed, with different failure mechanisms | A matching local PID can retain a stale row. An invisible or incorrectly missing CID can cause a live row to be deleted. A different host alone does not imply incorrect deletion: a visible live CID prevents it. |
| Existing tests do not adequately cover the first-acquisition race | Confirmed | Tests cover an already occupied key and a concurrent counter, but lack a synchronized start on an empty key and an isolation matrix. Claiming that concurrency is entirely untested would be inaccurate. |
| The README example is insufficient for accounting | Confirmed when treated as a production recipe | The example adds funds, so the absence of an insufficient-funds check for debits is not itself a defect in that example. Stale caches, floating-point arithmetic, write errors, retries, and other balance mutation paths remain relevant. |
| Timeout and TTL need separate explanations | Already explained in the README | Version 2.0.0 has separate sections and settings. Their limits need clarification; the explanation does not need to be introduced from scratch. |
| Renewal using a token makes a long-running operation safe | Useful, but insufficient | A paused process can outlive its lease before the next renewal. The protected resource or a transactional business protocol must reject stale writes. |

## Experimental results

The unmodified repository backend ran with the real WordPress 6.2 `wpdb` and PHP 7.4.3. The table followed the 2.0.0 schema with InnoDB explicitly selected. Both databases ran in temporary containers without networking, accessed through local Unix sockets. Each concurrency scenario used two independent PHP processes and connections with a shared start signal; release was permitted only after both acquisition results had been collected. Settings were TTL=30 seconds, autocommit=1, nonblocking acquisition, and the standard three error retries. Neither the SQL nor the schema was changed for the concurrency tests.

| Database | Isolation | Two successful WRITE acquisitions / 200 | Successful READ and WRITE acquisitions / 200 | Two successful READ acquisitions / 200 |
| --- | --- | ---: | ---: | ---: |
| MySQL 8.0.46 | REPEATABLE READ | 0 | 0 | 200 |
| MySQL 8.0.46 | READ COMMITTED | 197 | 200 | 200 |
| MariaDB 10.11.10 | REPEATABLE READ | 0 | 0 | 200 |
| MariaDB 10.11.10 | READ COMMITTED | 162 | 180 | 200 |

Each failing scenario had two persisted owner rows before release. These runs produced no exceptions. The counts come from the saved final run; frequency depends on process scheduling and is not an estimate of production incident probability. Observing no violation in 200 iterations does not prove correctness for every REPEATABLE READ execution schedule.

An explanation consistent with these observations is provided by the [MySQL INSERT SELECT locking documentation](https://dev.mysql.com/doc/refman/8.0/en/innodb-locks-set.html): source reads do not set locks under READ COMMITTED, while other isolation levels use shared next-key locks. The [MariaDB documentation](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-lock-modes) also describes gap locking being disabled under READ COMMITTED. This explains the observations; it is not a proof that this particular SQL is correct across all versions.

Additional checks on both databases:

| Scenario | Result | Interpretation |
| --- | --- | --- |
| A acquires with TTL=1; B acquires WRITE after 1.1 seconds | Both acquire=true; A still held | TTL does not stop the original owner's PHP code. |
| A then releases its acquisition | B's row remains | Deletion using the stored id protects the new owner during ordinary reacquisition. |
| START TRANSACTION → acquire → ROLLBACK → second acquire | Both acquire=true | Rolling back an outer transaction removes the lock row without resetting PHP state. |
| A BEFORE INSERT trigger delays SQL by 1.2 seconds with TTL=1 | acquire=true, then exists=false immediately after return | The lease starts before SQL execution; acquisition can return an already expired lease. The trigger was explicit delay instrumentation for this test only. |
| The same delay with blocking_timeout=0.01 and blocking=true | Returns true after approximately 1.2 seconds | The polling budget does not bound query duration; a success arriving after the deadline is accepted. |
| Live CID, PID=0, processlist query error | Second acquire=true | A liveness check error is treated as owner absence. PID=0 simulates an unavailable local process check; a query filter injects the SQL error. |
| Live local PID, CID=0, TTL=0 | 0 ghosts | A local PID alone excludes the row from cleanup. This models a PID match or incorrect identity; it is not a multi-host experiment. |
| A 51-character resource ID under strict SQL mode | RuntimeException, Data too long | original_key=varchar(50) does not match the promise of an arbitrary string. WordPress normally removes strict mode, so the observed behavior depends on connection settings. |
| An existing lock with its table temporarily unavailable | lock_exists=false with last_error set | A database error is indistinguishable from lock absence. |

Artifacts: [probe.php](probe.php), [MySQL JSONL](mysql-results.jsonl), and [MariaDB JSONL](maria-results.jsonl). The script uses the dedicated `wp_lock_audit` database and creates or removes only `audit_lock`, a temporary trigger, and a temporary rename of that table. Run it against a separate test database. It is a research reproducer, not a replacement for the PHPUnit suite.

The existing PHPUnit suite also ran in a separate temporary project copy with dependencies from the current composer.json: PHP 8.3.33, WordPress `7.1-src`, MariaDB 10.11.10, PHPUnit 9.6.37, and polyfills 4.0.0. Result: **51 tests, 140 assertions, no failures or skips**, including PCNTL concurrency tests. See the [full log](phpunit.log). Five tests were initially skipped before PCNTL was installed in the container; the final run included the extension. This shows that the existing suite passes despite the defects reproduced outside it. The full PHP CI matrix and coverage were not run in this investigation.

## Risks missing from the original analysis

1. **An outer transaction affects ownership.** The backend uses the global `$wpdb`, creates no independent transaction context, and does not check for an active transaction. A rollback can remove the lock row; an internal deadlock can also roll back the caller's transaction. Tests explicitly disable the WordPress test transaction and therefore do not exercise this contract. See [acquire](../../lib/backend/class-wp-lock-backend-db.php#L198).
2. **TTL starts before SQL, rather than after acquire returns.** SQL latency reduces the remaining usable lease. Expiration is calculated using PHP hosts' `microtime(true)`, so clock differences also affect expiration. The clock observation comes from code inspection; no separate multi-host experiment was performed. See [query construction](../../lib/backend/class-wp-lock-backend-db.php#L209).
3. **Blocking timeout is a soft polling budget.** It is checked after a conflicting query, not in every branch or inside the driver. Error retries have a separate delay and a resettable counter. The current synchronous API cannot promise a strict return-time limit.
4. **Check failures hide the actual state.** `exists()` returns false after a SELECT error; get_ghosts does not check last_error after processlist. See [exists](../../lib/backend/class-wp-lock-backend-db.php#L316) and [ghost detection](../../lib/backend/class-wp-lock-backend-db.php#L141).

This audit covers specific claims and related correctness scenarios. It does not validate throughput capacity, clustered databases, failover, proxies, persistent database connections, read replicas, or every multisite configuration.

## Feasible technical changes

### Temporary restrictions on supported configurations

During development, explicitly define the configuration to be validated: InnoDB, REPEATABLE READ, autocommit, no outer transaction at acquisition or release, and a consistent primary database and resource namespace. Reject READ COMMITTED for the current algorithm with a clear error. Do not silently change isolation or autocommit on the shared `$wpdb`, or commit another component's transaction. Active transaction detection must be checked on the declared MySQL and MariaDB versions: `@@autocommit=1` alone is insufficient because an explicit START TRANSACTION remains possible.

These restrictions reduce the confirmed acquisition race risk; they do not address TTL, cleanup failures, or stale writes. Stop automatically deleting TTL=0 owners based on incomplete or failed liveness checks. Existing consumers need release documentation for these stricter behaviors.

### Target design retaining shared READ ownership

A proposed design for evaluation uses a resource row with PRIMARY KEY(namespace, resource_key), separate owner rows, and a short acquisition transaction on a controlled connection. Every acquisition, renewal, or cleanup operation locks the existing resource row, reads current owners, checks conflicts, changes state, and commits before returning success. A unique key protects the initial creation of the resource row; owner reads must avoid a stale snapshot under REPEATABLE READ.

READ owners can coexist after their short acquisition transactions finish. The resource row serializes changes to ownership records. Release, renewal, and cleanup must follow the same lock order and ownership checks. Each acquisition needs an opaque owner token; fencing requires a separate monotonic generation number validated by the protected system. These serve different purposes.

An independent connection must not commit or roll back a WordPress business transaction. Costs include connection management, database drop-in support, primary routing, schema permissions, and migration. Establish the ADR and tests before implementation. Adding `FOR UPDATE` around the existing check without establishing the protocol is insufficient evidence of a fix.

A separate, simpler backend with a unique resource row is possible for WRITE-only use. It does not replace the advertised shared READ behavior. MySQL [GET_LOCK](https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html) releases locks when the session ends and is independent of COMMIT/ROLLBACK, but requires its own contract: it provides connection ownership without an integrated READ/TTL protocol and has proxy and failover limitations. This audit does not justify automatically replacing the entire library with GET_LOCK.

### Leases and long-running code

Use a single database time authority for expiration and PHP monotonic time for the waiting budget. Determine lease issuance time after obtaining the internal resource lock. Replacing `microtime` with `NOW()` alone is insufficient: MySQL NOW is tied to the start of the SQL statement, which matters when the statement itself waits. See the [MySQL documentation](https://dev.mysql.com/doc/refman/8.0/en/date-and-time-functions.html).

Successful acquisition does not imply permanent ownership. The contract must define the remaining TTL check before return, ownership loss behavior, and, if renewal is added, renewal by the current token only. Even checking ownership immediately before a write leaves a race window: rejecting stale writes requires an atomic check at the protected resource. Heartbeats and renewal reduce the likelihood of lease loss but do not remove this requirement for strict invariants.

### Applying the findings to credits

A mutex alone does not make a debit transactional or idempotent. A practical design for material balances uses a dedicated table with integer minor units or exact DECIMAL values, a conditional update checking `balance >= amount` and affected rows, and an operation identifier recorded in the same transaction. Operation uniqueness needs a defined scope and matching payload checks for retries. A ledger write failure must roll back the balance change as well. External side effects need their own retry protocol; the lock library does not provide it.

This belongs to the application; wp-lock does not need to become an accounting system. The README needs a safe API example and an explanation of these limits. `get_user_meta()` can return cached values: the [WordPress implementation](https://developer.wordpress.org/reference/functions/get_metadata_raw/) reads the object cache first. Acquiring a lock does not invalidate another request's cache. An initial read followed by waiting for a lock and then reading the old cached value must be considered separately.

## Identified issues and follow-up work

Confirmed findings are recorded in the [issue register](../issues/README.md), with evidence and closure criteria. The single [ROADMAP.md](../../ROADMAP.md) defines target versions and work priorities; [planning documents](../planning/README.md) contain the epic and task cards.

This report preserves research results and technical recommendations. Task statuses and a separate release schedule are not maintained here.

## Reproduction

Requirements: PHP with mysqli and pcntl, WordPress to load wpdb, and separate MySQL or MariaDB instances. This audit used local Docker images `mysql:8.0.46` and `mariadb:10.11.10`; passwordless root access was enabled only inside temporary containers without networking.

```bash
mkdir -p /tmp/wp-lock-audit/mysql
chmod 777 /tmp/wp-lock-audit/mysql
docker run --rm -d --name wp-lock-audit-mysql --network none \
  --mount type=bind,src=/tmp/wp-lock-audit/mysql,dst=/audit \
  --tmpfs /var/lib/mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  -e MYSQL_DATABASE=wp_lock_audit mysql:8.0.46 \
  --socket=/audit/mysql.sock --skip-networking --mysqlx=0
# After the database is ready:
WP_LOCK_AUDIT_SOCKET=/tmp/wp-lock-audit/mysql/mysql.sock \
WP_LOCK_AUDIT_WP=/path/to/wordpress \
php7.4 docs/review-2026-09-29/probe.php
docker stop wp-lock-audit-mysql
```

For MariaDB, use `mariadb:10.11.10`, set `MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=yes` and `MARIADB_DATABASE=wp_lock_audit`, and remove `--mysqlx=0`. Run the probe separately for each server. For newer WordPress or PHP versions, use the appropriate runtime and adapt the minimal wpdb bootstrap if necessary.
