# E3-03 B2 retained local evidence — 2026-10-01

This manifest identifies the uncommitted two-file repair on `batch/b2` atop `ca2b10aa9df74a6374e1350f0cba5888afe2f5c1`. The repaired matrix ran in `/tmp/wp-lock-d1.pIQFFUOE`; its `git-before.txt` and `git-after.txt` status lists were identical, containing only the backend and diagnostic edits. The nine source/document paths below define the tested source boundary. The repaired six [JSONL outputs and exit files](#matrix-results), existing-suite outputs, and [source parity](source-parity.txt) are retained here for independent E3-QA.

| Tested path | SHA-256 |
| --- | --- |
| [DB backend](../../../../lib/backend/class-wp-lock-backend-db.php) | `325f485e9f59cfa88c3371997a64d4ad81ef1f6d5f45eda7a624446f106567f6` |
| [controlled DB subclass](../../../../lib/backend/class-wp-lock-foundation-db.php) | `cfef2168238329f36a64170d918a1aa68d1143e335a90fa582720400b1c252f3` |
| [foundation](../../../../lib/backend/class-wp-lock-foundations.php) | `cd466fa3213116305f8acf32976ade64f34ce1029fee4f6bfde99f61a3ec9b97` |
| [wrapper](../../../../lib/class-wp-lock.php) | `c2319fde5c83e63de13a87aa032a40486e1aac90b2812814077e2d74bed20afc` |
| [matrix runner](../../../../tests/diagnostics/d1-matrix.sh) | `99ef40461d37d195f92202d51b4e472d5e2196334a5c234e235e0cb92acb0121` |
| [public diagnostic](../../../../tests/diagnostics/e3-ownership.php) | `714039480dffbcc0ea2a4b2702e5a436798a5e28d5c756e5dc3ac95ae0ba88ce` |
| [DB tests](../../../../tests/lock/db.php) | `03b46adb45de18d3c9808b115d26920dba33e1d54b9455580a6b98a247d625cf` |
| [generic tests](../../../../tests/lock/generic.php) | `53875631b15b458ab915cadf0b6b9325fa6a3347b35cc4cb32c823a70840b712` |
| [README](../../../../README.md) | `fa381ac69e23fd2567b5b17452fb4694f3d3486c8e3c0a4e1f5c4079569bd9c3` |

`test_monitor` ran the public matrix once after the zero-row cleanup repair writer stopped. An initial Docker approval wait timed out before the command started; process inspection found no run, and a permitted retry produced the retained evidence:

```bash
D1_DIAGNOSTIC_SCRIPT=tests/diagnostics/e3-ownership.php D1_REQUIRE_PASS=1 D1_DOCKER='/mnt/c/Program Files/Docker/Docker/resources/bin/docker' bash tests/diagnostics/d1-matrix.sh
```

All six `.exit` files contain `0`. Every output is strict JSONL with 29 records: two actual InnoDB environment records (RR and RC), 13 passing directed cases per isolation, and a passing summary. Every `.stderr` is empty. Both PHP images passed the required process-control preflight. The diagnostic records these actual runtimes:

## Matrix results

| Row | PHP / WordPress / actual database `VERSION()` | Retained output / exit | JSONL SHA-256 |
| --- | --- | --- |
| `mysql267` | 8.5.11 / 7.1.2 / MySQL 26.7.0 | [JSONL](mysql267.jsonl) / [exit](mysql267.exit) | `eb9eea4cc628837424d84f9b6adb199c7da7da7be7f5f74aee7a08cab3cbfa81` |
| `mysql97` | 8.5.11 / 7.1.2 / MySQL 9.7.2 | [JSONL](mysql97.jsonl) / [exit](mysql97.exit) | `8d0c3d08f38f45007774916a6d5f29758e860c9f862c7256f1e9899d83797fc5` |
| `maria130` | 8.5.11 / 7.1.2 / MariaDB 13.0.2 | [JSONL](maria130.jsonl) / [exit](maria130.exit) | `0b194bfa088be9c6a7334e519834aae8b1c5df7361d491e32aed16a0f2f372d4` |
| `maria123` | 8.5.11 / 7.1.2 / MariaDB 12.3.3 | [JSONL](maria123.jsonl) / [exit](maria123.exit) | `1cb7e4c829f989c7847fdef4db81205dcc59a835549eb27c8651b9cb5999f572` |
| `mysql97-floor` | 7.4.33 / 6.2.13 / MySQL 9.7.2 | [JSONL](mysql97-floor.jsonl) / [exit](mysql97-floor.exit) | `d342f26885926021b5fbf28507e63727a2e5dabdf71cb6cd12621da3825069d4` |
| `maria123-floor` | 7.4.33 / 6.2.13 / MariaDB 12.3.3 | [JSONL](maria123-floor.jsonl) / [exit](maria123-floor.exit) | `54b9a26d24b64c3abb42e098519fbfc79137920a999d5cceb0df9c013c1667cc` |

An earlier provisional run passed all directed cases but emitted `ReflectionProperty::setAccessible()` deprecation notices on PHP 8.5. The diagnostic fixture guard was repaired. A preceding directed run also exposed a real MySQL 9.7 RC release deadlock (`1213`), resolved in the tested product source by bounded rollback/retry. Independent review found that a zero-row DELETE during cleanup of a committed but unconfirmed owner could be mistaken for success. The repaired backend requires one affected row; the new `committed_unconfirmed_zero_row_cleanup` case verifies uncertainty after both release and reacquisition cleanup attempts, then successful cleanup, at RR and RC in every row. The runner used disposable network-disabled database containers with tmpfs data and Unix sockets; no container remains. The exact image digests and broader D1 environment contract are recorded in [the D1 matrix](../D1-matrix.md). Each retained [stderr file](mysql267.stderr) is empty (likewise for the other five rows).

The existing suite was run after the zero-row cleanup repair from an isolated source copy at `/tmp/wp-lock-b2-suite-zero-cleanup.K6gPm1/project`, using `composer test -- --bootstrap /tmp/wp-lock-b2-suite-zero-cleanup.K6gPm1/phpunit-bootstrap.php`. The Composer stderr records that expanded test command; the full surrounding shell invocation was not retained. The temporary bootstrap points to the copied project and a disposable WordPress test configuration. [stdout](composer-test.stdout), [stderr](composer-test.stderr), [exit](composer-test.exit), and [source parity at suite execution](source-parity.txt) are retained. The two changed PHP files and `composer.json` matched the checkout SHA-256 in the retained parity file; the other six library/test/runner paths in the table matched the checkout by direct byte comparison. Exit `0`: PHPUnit 9.6.37 and Polyfills 4.0.0, **55 tests, 144 assertions, zero skips**. Runtime: PHP CLI 7.4.3, WordPress `6.7-alpha-58576-src`, MariaDB `10.11.10-MariaDB-ubu2204`, InnoDB, `REPEATABLE-READ`. This development WordPress snapshot differs from the six released matrix targets. The disposable database was removed. The suite output hashes are `58ebba7d03cd0e1008da5e18295f4b52fd2943ab5806507c26e1213ec04d7ba9` (stdout) and `c452ffd7f9c95f852dc6759b73b0831def73867a59f35d099ceb37259213b833` (stderr).

Neither run establishes universal concurrency safety, final E4 timing/recovery, E5 protocol switching/support, coverage, or an E3-QA verdict. No exception is approved.
