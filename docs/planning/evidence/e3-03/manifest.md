# E3-03 B2 retained local evidence — 2026-10-01

This manifest identifies the uncommitted implementation diff on `batch/b2` atop `e0ce475344a4fdda60a0cc5f1362b65e2fe7df81`. The final matrix ran in `/tmp/wp-lock-d1.F2sSCGFw`; its `git-before.txt` and `git-after.txt` status lists were identical. The nine source/document paths below define the tested source boundary. The final six [JSONL outputs and exit files](#matrix-results), existing-suite outputs, and [source parity](source-parity.txt) are retained here for independent E3-QA.

| Tested path | SHA-256 |
| --- | --- |
| [DB backend](../../../../lib/backend/class-wp-lock-backend-db.php) | `8dbc94a31aef12d309936f23024e3f3411362671d0f1ffbe555966d52c020fd0` |
| [controlled DB subclass](../../../../lib/backend/class-wp-lock-foundation-db.php) | `cfef2168238329f36a64170d918a1aa68d1143e335a90fa582720400b1c252f3` |
| [foundation](../../../../lib/backend/class-wp-lock-foundations.php) | `cd466fa3213116305f8acf32976ade64f34ce1029fee4f6bfde99f61a3ec9b97` |
| [wrapper](../../../../lib/class-wp-lock.php) | `c2319fde5c83e63de13a87aa032a40486e1aac90b2812814077e2d74bed20afc` |
| [matrix runner](../../../../tests/diagnostics/d1-matrix.sh) | `99ef40461d37d195f92202d51b4e472d5e2196334a5c234e235e0cb92acb0121` |
| [public diagnostic](../../../../tests/diagnostics/e3-ownership.php) | `352d2a454b351d5ba1622b7a022192e55186cfa480fc03b79dccbef7a35760e4` |
| [DB tests](../../../../tests/lock/db.php) | `03b46adb45de18d3c9808b115d26920dba33e1d54b9455580a6b98a247d625cf` |
| [generic tests](../../../../tests/lock/generic.php) | `53875631b15b458ab915cadf0b6b9325fa6a3347b35cc4cb32c823a70840b712` |
| [README](../../../../README.md) | `fa381ac69e23fd2567b5b17452fb4694f3d3486c8e3c0a4e1f5c4079569bd9c3` |

`test_monitor` ran the final public matrix once after the diagnostic warning-guard repair writer stopped:

```bash
D1_DIAGNOSTIC_SCRIPT=tests/diagnostics/e3-ownership.php D1_REQUIRE_PASS=1 D1_DOCKER='/mnt/c/Program Files/Docker/Docker/resources/bin/docker' bash tests/diagnostics/d1-matrix.sh
```

All six `.exit` files contain `0`. Every output is strict JSONL with 29 records: two actual InnoDB environment records (RR and RC), 13 passing directed cases per isolation, and a passing summary. Every `.stderr` is empty. Both PHP images passed the required process-control preflight. The diagnostic records these actual runtimes:

## Matrix results

| Row | PHP / WordPress / actual database `VERSION()` | Retained output / exit | JSONL SHA-256 |
| --- | --- | --- |
| `mysql267` | 8.5.11 / 7.1.2 / MySQL 26.7.0 | [JSONL](mysql267.jsonl) / [exit](mysql267.exit) | `4acafe77d7c1a61cb06565e4ee0c14cb96fed34f4163a7d7ed4e1d3eb0a5806f` |
| `mysql97` | 8.5.11 / 7.1.2 / MySQL 9.7.2 | [JSONL](mysql97.jsonl) / [exit](mysql97.exit) | `46058271efefa06d40070d18e4360e90cf70ab66ed828974884ba2e785d2b4ab` |
| `maria130` | 8.5.11 / 7.1.2 / MariaDB 13.0.2 | [JSONL](maria130.jsonl) / [exit](maria130.exit) | `6d4b4a5354ebdebf157c1a547ff40c08067004ba213a84a62a1115cd0243c3d1` |
| `maria123` | 8.5.11 / 7.1.2 / MariaDB 12.3.3 | [JSONL](maria123.jsonl) / [exit](maria123.exit) | `dd55738131f3f0431aeb63e19cc71d8c5694d6c04b653aeed07833aa6c47fb2b` |
| `mysql97-floor` | 7.4.33 / 6.2.13 / MySQL 9.7.2 | [JSONL](mysql97-floor.jsonl) / [exit](mysql97-floor.exit) | `289db92da4061cc750ec3f9c6a8f288f06ab53d809eacb73abc32ca9e78ea5d2` |
| `maria123-floor` | 7.4.33 / 6.2.13 / MariaDB 12.3.3 | [JSONL](maria123-floor.jsonl) / [exit](maria123-floor.exit) | `1e14e41504c24947b398d3fb278c9425cce089b00af391fbf4b320bcc2aae59f` |

An earlier provisional run passed all directed cases but emitted `ReflectionProperty::setAccessible()` deprecation notices on PHP 8.5. The diagnostic fixture guard was repaired; the final retained outputs have no warnings. A preceding directed run also exposed a real MySQL 9.7 RC release deadlock (`1213`), resolved in the tested product source by bounded rollback/retry. The runner used disposable network-disabled database containers with tmpfs data and Unix sockets; no container remains. The exact image digests and broader D1 environment contract are recorded in [the D1 matrix](../D1-matrix.md). Each retained [stderr file](mysql267.stderr) is empty (likewise for the other five rows).

The existing suite was run before the fixture-only warning repair from an isolated source copy at `/tmp/wp-lock-b2-suite-final.BgDWgj/project`, using `composer test -- --bootstrap /tmp/wp-lock-b2-suite-final.BgDWgj/phpunit-bootstrap.php`. The Composer stderr records that expanded test command; the full surrounding shell invocation was not retained. The temporary bootstrap points to the copied project and a disposable WordPress test configuration. [stdout](composer-test.stdout), [stderr](composer-test.stderr), [exit](composer-test.exit), and [source parity at suite execution](source-parity.txt) are retained. The four product files, matrix runner, two generic-test files, and `composer.json` in that copy still match the final checkout hashes. Only the diagnostic fixture changed afterward; it is outside the existing suite. Exit `0`: PHPUnit 9.6.37 and Polyfills 4.0.0, **55 tests, 144 assertions, zero skips**. Runtime: PHP CLI 7.4.3, WordPress `6.7-alpha-58576-src`, MariaDB `10.11.10-MariaDB-ubu2204`, InnoDB, `REPEATABLE-READ`. This development WordPress snapshot differs from the six released matrix targets. The disposable database was removed. The suite output hashes are `ebb035c89a4abc957d20198d7a802feb361c56e05083d9af4845d9b8c2d753cf` (stdout) and `e0f7dfb7c8d2a76e867e97228a3d4649df413eb6240ea623cc6a7af30a6c6ca7` (stderr).

Neither run establishes universal concurrency safety, final E4 timing/recovery, E5 protocol switching/support, coverage, or an E3-QA verdict. No exception is approved.
