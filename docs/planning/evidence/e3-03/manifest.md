# E3-03 B2 retained local evidence — 2026-10-02

This manifest identifies the uncommitted two-file repair on `batch/b2` atop `a88da270abc65c5d67a4079edc703ff54ae3f9dd`. The public matrix ran in `/tmp/wp-lock-d1.A3Q6HRK6`; its `git-before.txt` and `git-after.txt` status lists were identical, containing only the foundation and diagnostic edits. The ten source/document paths below define the tested source boundary. The refreshed six [JSONL outputs and exit files](#matrix-results), existing-suite outputs, and [source parity](source-parity.txt) are retained here for independent E3-QA.

| Tested path | SHA-256 |
| --- | --- |
| [DB backend](../../../../lib/backend/class-wp-lock-backend-db.php) | `325f485e9f59cfa88c3371997a64d4ad81ef1f6d5f45eda7a624446f106567f6` |
| [controlled DB subclass](../../../../lib/backend/class-wp-lock-foundation-db.php) | `cfef2168238329f36a64170d918a1aa68d1143e335a90fa582720400b1c252f3` |
| [foundation](../../../../lib/backend/class-wp-lock-foundations.php) | `57ae93a077cde48f64ce28fc8cce23389c10cab758941687a6a3e4425acd5619` |
| [wrapper](../../../../lib/class-wp-lock.php) | `c2319fde5c83e63de13a87aa032a40486e1aac90b2812814077e2d74bed20afc` |
| [matrix runner](../../../../tests/diagnostics/d1-matrix.sh) | `99ef40461d37d195f92202d51b4e472d5e2196334a5c234e235e0cb92acb0121` |
| [public diagnostic](../../../../tests/diagnostics/e3-ownership.php) | `e75d02798215081e745a5d90abc0a23769a3d0ff24137d8965c56eabd913249b` |
| [test support](../../../../tests/include.php) | `cf274aee0336bf0a7486eb8a43fc9907a17d2b29fe98f65eb229639770437903` |
| [DB tests](../../../../tests/lock/db.php) | `03b46adb45de18d3c9808b115d26920dba33e1d54b9455580a6b98a247d625cf` |
| [generic tests](../../../../tests/lock/generic.php) | `e7d23f11deaddec43fee614c1f3cbf3c9a8415fa721b8a8021a869cb22cf9e9e` |
| [README](../../../../README.md) | `fa381ac69e23fd2567b5b17452fb4694f3d3486c8e3c0a4e1f5c4079569bd9c3` |

`test_monitor` ran the public matrix after the acquire deadlock repair writer stopped. The retained run used:

```bash
D1_DIAGNOSTIC_SCRIPT=tests/diagnostics/e3-ownership.php D1_REQUIRE_PASS=1 D1_DOCKER='/mnt/c/Program Files/Docker/Docker/resources/bin/docker' bash tests/diagnostics/d1-matrix.sh
```

All six `.exit` files contain `0`. Every output is strict JSONL with 31 records: two actual InnoDB environment records (RR and RC), 14 passing directed cases per isolation, and a passing summary. Every `.stderr` is empty. Both PHP images passed the required process-control preflight. The diagnostic records these actual runtimes:

## Matrix results

| Row | PHP / WordPress / actual database `VERSION()` | Retained output / exit | JSONL SHA-256 |
| --- | --- | --- |
| `mysql267` | 8.5.11 / 7.1.2 / MySQL 26.7.0 | [JSONL](mysql267.jsonl) / [exit](mysql267.exit) | `201c4885655d3b1f10f9af2fd6e24805c4974e1dbe94b7b16b7a7ee811b01db1` |
| `mysql97` | 8.5.11 / 7.1.2 / MySQL 9.7.2 | [JSONL](mysql97.jsonl) / [exit](mysql97.exit) | `9dde7d7bd4654aba38154a627c5bf7e1b9c6126d969a6aae68bdd0e1726fd5ad` |
| `maria130` | 8.5.11 / 7.1.2 / MariaDB 13.0.2 | [JSONL](maria130.jsonl) / [exit](maria130.exit) | `e66610e17ab1f50007a39719d6f5c8fc9da7fe8dfabe9c61681ecf68f38a07d8` |
| `maria123` | 8.5.11 / 7.1.2 / MariaDB 12.3.3 | [JSONL](maria123.jsonl) / [exit](maria123.exit) | `c6870103b79a848691289b40680af2f434d325434006d5f92e706ae8b59c18dc` |
| `mysql97-floor` | 7.4.33 / 6.2.13 / MySQL 9.7.2 | [JSONL](mysql97-floor.jsonl) / [exit](mysql97-floor.exit) | `21a7e236976c7274bfc4d2d5cae16cbed33061e2ad81bf5c65138998ad71f36d` |
| `maria123-floor` | 7.4.33 / 6.2.13 / MariaDB 12.3.3 | [JSONL](maria123-floor.jsonl) / [exit](maria123-floor.exit) | `8b10111a00d3c127d239633edce4323e265565b07b7ad58d8c844701b0fafb5b` |

An earlier provisional run passed all directed cases but emitted `ReflectionProperty::setAccessible()` deprecation notices on PHP 8.5; the fixture guard was repaired. A preceding directed run exposed a real MySQL 9.7 RC release deadlock (`1213`), resolved by bounded rollback/retry. Independent review then found that a zero-row DELETE during cleanup of a committed but unconfirmed owner could be mistaken for success; the repaired backend requires one affected row. The `committed_unconfirmed_zero_row_cleanup` case verifies that behavior at RR and RC in every row. [PR #9 CI run 36925740101](https://github.com/hokoo/wp-lock/actions/runs/36925740101) on prior head `a88da27` then failed PHP 8.1/Coverage due a MySQL `1213` resource-select deadlock under contention (13 of 25 repeated attempts in 0.111 seconds). The current repair replaces `INSERT IGNORE` duplicate-key locking with an exclusive no-op upsert before the locking read; `repeated_shared_read` checks two independent readers each making 25 acquire/release attempts on an existing resource. This local matrix passes; CI has not yet run on the repaired head. The runner used disposable network-disabled database containers with tmpfs data and Unix sockets; no container remains. The exact image digests and broader D1 environment contract are recorded in [the D1 matrix](../D1-matrix.md). Each retained [stderr file](mysql267.stderr) is empty (likewise for the other five rows).

The B1 foundations matrix also ran on this source in `/tmp/wp-lock-d1.RQzlZZfd`, using `D1_REQUIRE_PASS=1 D1_DOCKER='/mnt/c/Program Files/Docker/Docker/resources/bin/docker' bash tests/diagnostics/d1-matrix.sh`. All six rows exited `0`, with 21 passing directed checks per row, two InnoDB RR/RC environments, passing summaries, and empty stderr. Its raw output remains in that temporary run directory; it is supporting evidence, not part of the retained public E3 files.

The existing suite was run from an isolated code copy at `/tmp/wp-lock-b2-upsert-suite.onvRhH/project`, using `composer test -- --bootstrap /tmp/wp-lock-b2-upsert-suite.onvRhH/phpunit-bootstrap.php`. The Composer stderr records that expanded test command; the full surrounding shell invocation was not retained. The temporary bootstrap points to the copied project and a disposable WordPress test configuration. [stdout](composer-test.stdout), [stderr](composer-test.stderr), [exit](composer-test.exit), and [retained suite-copy source parity](source-parity.txt) are retained. The two changed PHP files, `tests/include.php`, and `composer.json` match the checkout SHA-256 in the retained parity file; the other six library/test/runner paths in the table matched the checkout by direct byte comparison. README was not copied into the test project; its hash identifies the reviewed checkout documentation. Exit `0`: PHPUnit 9.6.37 and Polyfills 4.0.0, **55 tests, 144 assertions, zero skips**. Runtime: PHP CLI 7.4.3, WordPress `6.7-alpha-58576-src`, MariaDB `10.11.10-MariaDB-ubu2204`, InnoDB, `REPEATABLE-READ`. This development WordPress snapshot differs from the six released matrix targets. The disposable database was removed. The suite output hashes are `d740960438a1a1941c18101614865c438bc890b5f71d81c87d64cf567c1cd27f` (stdout) and `ea3d163a816c5d8a707e040fe5dc5edb11dd865bb6f38b150c147bfa7a65d495` (stderr).

These local runs do not establish universal concurrency safety, a repaired-head CI result, final E4 timing/recovery, E5 protocol switching/support, coverage, or an E3-QA verdict. No exception is approved.
