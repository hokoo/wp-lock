# E3-02 retained B1 runtime evidence — 2026-10-01

The isolation-guard repair boundary was an uncommitted two-file diff atop `09cbd2d7713bd17b268a46b81a4b27415ab71cc1`, with binary diff SHA-256 `a0ebafeafd66e37547f9b9748e6c0ecb2c1a0a7d83e9c8a1b1aabb70ea045c03`. The matrix runner's `git-before.txt` and `git-after.txt` were identical. The source SHA-256 values below identify the tested code; [PR #8 final delivery](../E3-02.md#final-pr-review-and-delivery--2026-10-01) subsequently delivered the tested source. `test_monitor` ran the serial matrix once after the repair writer stopped:

```bash
D1_DIAGNOSTIC_SCRIPT=tests/diagnostics/e3-foundations.php D1_REQUIRE_PASS=1 D1_DOCKER='/mnt/c/Program Files/Docker/Docker/resources/bin/docker' bash tests/diagnostics/d1-matrix.sh
```

The run was saved at `/tmp/wp-lock-d1.Hcs35G8y`. It used disposable network-disabled database containers with tmpfs data and Unix sockets; all six were stopped. Both PHP images passed `mysqli`, PCNTL, and POSIX preflight. WordPress source archives were `7.1.2` SHA-256 `c0c666689d66b870d8825500bb8e402ed04de52602c61a6f516c6236b8c9ac67` and `6.2.13` SHA-256 `50a11569de9b23b2ab073f531316f52fb90578a89ae667fa3facb6e6bed16747`.

| Retained JSONL | PHP / WordPress / actual `VERSION()` | Database image digest | JSONL SHA-256 |
| --- | --- | --- | --- |
| [mysql267.jsonl](mysql267.jsonl) | 8.5.11 / 7.1.2 / 26.7.0 | `mysql@sha256:9d48c42f8341068f199116dfccb919b607c99765b5c61e549a548a43033471a4` | `31627a47e0a1ee6f8837bcd83ec88c02e1a0ea6ec1d64169c6c6f9685f511e23` |
| [mysql97.jsonl](mysql97.jsonl) | 8.5.11 / 7.1.2 / 9.7.2 | `mysql@sha256:e2bde46db6563855d7177adb5f0b57b9dc663f5a20927a90f4259d3312068497` | `394813465fb88d32219d16b425fde2fc5552d05280cdc3626775b9e870579a7b` |
| [maria130.jsonl](maria130.jsonl) | 8.5.11 / 7.1.2 / 13.0.2-MariaDB-ubu2604 | `mariadb@sha256:d4fdec0510ad498e4f3127da30a99df3745bd6d5e611ae6ac5f76403d9284a8d` | `e0f6ea0a547f6bfced444dc474495d8889f143acf87decaf99319f5c3b10dfb4` |
| [maria123.jsonl](maria123.jsonl) | 8.5.11 / 7.1.2 / 12.3.3-MariaDB-ubu2404 | `mariadb@sha256:805c8e104bd563d5bfa24fadd3f31cd419ea859cb5277f32b5dbf2db714f9ed1` | `d604a6061e87e1990e3097214f4502d80728509a46bcbd535f0d50c136872c28` |
| [mysql97-floor.jsonl](mysql97-floor.jsonl) | 7.4.33 / 6.2.13 / 9.7.2 | `mysql@sha256:e2bde46db6563855d7177adb5f0b57b9dc663f5a20927a90f4259d3312068497` | `7f1075495fe25fb8e1eca624caa1379b0c31fad03787bfc161fe6fc59845cc63` |
| [maria123-floor.jsonl](maria123-floor.jsonl) | 7.4.33 / 6.2.13 / 12.3.3-MariaDB-ubu2404 | `mariadb@sha256:805c8e104bd563d5bfa24fadd3f31cd419ea859cb5277f32b5dbf2db714f9ed1` | `8374e06062c5f65d16f71684a5c27b07cf6ce5cec6295db7a92f26ac6088932e` |

For every row, the paired `.exit` file contains `0`, its `.stderr` is empty, and its JSONL ends in `pass=true`. Every environment record reports InnoDB, `STRICT_ALL_TABLES`, actual RR or RC, autocommit, and different caller/controlled connection IDs. All named directed cases passed for both isolations. MariaDB records caller `innodb_snapshot_isolation=ON` and controlled `OFF` in both RR and RC, with caller state asserted unchanged during outer-transaction work. MySQL reports the variable absent. Each row's 24 records include schema/DDL refusal, strict full-ID and finite timeout, accepted implicit isolation, refusal after a controlled-session change to `READ UNCOMMITTED` or `SERIALIZABLE` before a transaction or owner write, first creators, current owner reads, shared readers, outer transaction/namespace/tokens, lost INSERT/COMMIT acknowledgment, and routing/reconnect refusal. The acknowledgment faults throw after successful SQL calls in a test-only `wpdb` subclass; this is deterministic post-query response-loss simulation, not evidence from physical packet loss. Connection identity/routing checks conservatively refuse unknown proxies; particular drop-ins need separate evidence.

PHP image digests: `php:8.5.11-cli-bookworm` → `php@sha256:d551e79d694fd91c4fdf34c4adcc52dd2042a064b881e25c319b2533ead0682c`; `php:7.4.33-cli` → `php@sha256:620a6b9f4d4feef2210026172570465e9d0c1de79766418d3affd09190a7fda5`. Source SHA-256 at the frozen boundary:

| Source | SHA-256 |
| --- | --- |
| [DB backend](../../../../lib/backend/class-wp-lock-backend-db.php) | `e283ebdad56afbd85619ff8827623e2014f027e826a79ff1bfec7ee968d64925` |
| [controlled DB subclass](../../../../lib/backend/class-wp-lock-foundation-db.php) | `dfc7b6404e9167041d250e651d5d62894ee2e4221d16f939cdd19680ff8bdf80` |
| [foundation](../../../../lib/backend/class-wp-lock-foundations.php) | `a380bfe45d26d07b0ff6305deec6259fc558bb9d4588f2eb33521a2f91a5ddf4` |
| [matrix runner](../../../../tests/diagnostics/d1-matrix.sh) | `7ad7eeb4ecdb4f8d2eb5c7dd1f6b86c602606bc03fc57398f401956b11ad68bf` |
| [E3 diagnostic](../../../../tests/diagnostics/e3-foundations.php) | `16ea99e7ac62bd713415223915f90136af83c56e50ace5cf2cd96ebcee3074b9` |
| [existing DB tests](../../../../tests/lock/db.php) | `38ad867fac4d9d3417593650b1e9060ba3a4d9a1a5e431d32f7a42f7cbc16859` |

The earlier existing-suite run used an isolated source copy and temporary bootstrap at the pre-guard `09cbd2d` revision, with the source parity recorded in [source-parity.txt](source-parity.txt). Its exact command was:

```bash
WP_TESTS_DIR=/tmp/wp-lock-b1-suite-final.5tLHjEKQ/runtime/wp-tests-lib PATH=/tmp/wp-lock-b1-suite-final.5tLHjEKQ/runtime/bin:$PATH composer test -- --bootstrap /tmp/wp-lock-b1-suite-final.5tLHjEKQ/phpunit-temp-bootstrap.php
```

[stdout](composer-test.stdout), [stderr](composer-test.stderr), and [exit](composer-test.exit) are retained verbatim after inspection. Exit `0`; PHPUnit 9.6.37 reported **54 tests, 143 assertions, zero skips** at `09cbd2d`. This suite is not verification of the later isolation-guard repair; the final-head CI suite and coverage gate subsequently passed as recorded in the final delivery report. Actual runtime: PHP CLI 7.4.3 with PCNTL/POSIX, WordPress `6.7-alpha-58576-src`, MariaDB `10.11.10-MariaDB-ubu2204`, InnoDB, `STRICT_TRANS_TABLES` and `REPEATABLE-READ`. The disposable database container used `--network none`, a tmpfs data directory, and a bind-mounted Unix socket directory; it was removed. Source parity: seven library files and nine test files matched the checkout copy by aggregate SHA-256; see the retained parity file. `composer-test.stdout` SHA-256 is `aea0292226f8bb585618550e7a336b46fda5d52a12a00d8fd5958764ad64acdf`; `composer-test.stderr` SHA-256 is `d85d03ec8badde99423276c184de4c34e403a195668831fb348e706200549e33`.

The six diagnostic rows use released WordPress targets; the earlier PHPUnit run uses an older WordPress development snapshot. A task-level reviewer found that transaction start lacked a session-isolation guard; the two-file repair above resolved that source-level blocker and the new six-row matrix verified its behavior. The prior suite verifies legacy public behavior and load compatibility at `09cbd2d`, not the guard revision or the inactive foundation's public acquisition switch. Neither run is the E5 full matrix, E3 epic QA, a coverage threshold, or release support approval. The schema option marks preparation after verification; it does not activate the new protocol. Generated ignored `vendor/composer/autoload_classmap.php`, `autoload_files.php`, and `autoload_static.php` changed during setup and were left untouched; tracked source was unaffected. E3-02 is completed after the recorded PR review, final-head CI, and merge.
