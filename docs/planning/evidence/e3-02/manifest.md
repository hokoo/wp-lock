# E3-02 retained B1 runtime evidence — 2026-10-01

The implementation boundary was uncommitted atop `3b68af36e9378588442c2ab0ae5579ee70044865`. The matrix runner's `git-before.txt` and `git-after.txt` were identical. The source SHA-256 values below identify the frozen code at verification; the batch PR and merge remain pending. `test_monitor` ran the serial matrix once after the final writer stopped:

```bash
D1_DIAGNOSTIC_SCRIPT=tests/diagnostics/e3-foundations.php D1_REQUIRE_PASS=1 D1_DOCKER='/mnt/c/Program Files/Docker/Docker/resources/bin/docker' bash tests/diagnostics/d1-matrix.sh
```

The run was saved at `/tmp/wp-lock-d1.HkRINW48`. It used disposable network-disabled database containers with tmpfs data and Unix sockets; all six were stopped. Both PHP images passed `mysqli`, PCNTL, and POSIX preflight. WordPress source archives were `7.1.2` SHA-256 `c0c666689d66b870d8825500bb8e402ed04de52602c61a6f516c6236b8c9ac67` and `6.2.13` SHA-256 `50a11569de9b23b2ab073f531316f52fb90578a89ae667fa3facb6e6bed16747`.

| Retained JSONL | PHP / WordPress / actual `VERSION()` | Database image digest | JSONL SHA-256 |
| --- | --- | --- | --- |
| [mysql267.jsonl](mysql267.jsonl) | 8.5.11 / 7.1.2 / 26.7.0 | `mysql@sha256:9d48c42f8341068f199116dfccb919b607c99765b5c61e549a548a43033471a4` | `279601a4eabb6ae7838eacb43c597325792174e85ef44da414d3512911332f9c` |
| [mysql97.jsonl](mysql97.jsonl) | 8.5.11 / 7.1.2 / 9.7.2 | `mysql@sha256:e2bde46db6563855d7177adb5f0b57b9dc663f5a20927a90f4259d3312068497` | `99c94ff73b7b357f995752b77016c9248d482f02a1a2a15cdd130a1c348e2d09` |
| [maria130.jsonl](maria130.jsonl) | 8.5.11 / 7.1.2 / 13.0.2-MariaDB-ubu2604 | `mariadb@sha256:d4fdec0510ad498e4f3127da30a99df3745bd6d5e611ae6ac5f76403d9284a8d` | `77947d7b4cbcccf33927ab9bb2bda4d7cf61fe5bf17be7b6dd29ddfd04d86ee3` |
| [maria123.jsonl](maria123.jsonl) | 8.5.11 / 7.1.2 / 12.3.3-MariaDB-ubu2404 | `mariadb@sha256:805c8e104bd563d5bfa24fadd3f31cd419ea859cb5277f32b5dbf2db714f9ed1` | `afabaf58c15447d825773e3d943d636260af89ee4931fd245328e8a83299d6fb` |
| [mysql97-floor.jsonl](mysql97-floor.jsonl) | 7.4.33 / 6.2.13 / 9.7.2 | `mysql@sha256:e2bde46db6563855d7177adb5f0b57b9dc663f5a20927a90f4259d3312068497` | `89cb3d7a64ededb721bb60437f6e27de5a90d6db2201282870698246fe1590c6` |
| [maria123-floor.jsonl](maria123-floor.jsonl) | 7.4.33 / 6.2.13 / 12.3.3-MariaDB-ubu2404 | `mariadb@sha256:805c8e104bd563d5bfa24fadd3f31cd419ea859cb5277f32b5dbf2db714f9ed1` | `096d927184c5762cba1700aaeace5bb4aa1c1ec8a9be20bc122a46329f1f9bdf` |

For every row, the paired `.exit` file contains `0`, its `.stderr` is empty, and its JSONL ends in `pass=true`. Every environment record reports InnoDB, `STRICT_ALL_TABLES`, actual RR or RC, autocommit, and different caller/controlled connection IDs. All named directed cases passed for both isolations. MariaDB records caller `innodb_snapshot_isolation=ON` and controlled `OFF` in both RR and RC, with caller state asserted unchanged during outer-transaction work. MySQL reports the variable absent. Each row's 20 records include schema/DDL refusal, strict full-ID and finite timeout, first creators, current owner reads, shared readers, outer transaction/namespace/tokens, lost INSERT/COMMIT acknowledgment, and routing/reconnect refusal. The acknowledgment faults throw after successful SQL calls in a test-only `wpdb` subclass; this is deterministic post-query response-loss simulation, not evidence from physical packet loss. Connection identity/routing checks conservatively refuse unknown proxies; particular drop-ins need separate evidence.

PHP image digests: `php:8.5.11-cli-bookworm` → `php@sha256:d551e79d694fd91c4fdf34c4adcc52dd2042a064b881e25c319b2533ead0682c`; `php:7.4.33-cli` → `php@sha256:620a6b9f4d4feef2210026172570465e9d0c1de79766418d3affd09190a7fda5`. Source SHA-256 at the frozen boundary:

| Source | SHA-256 |
| --- | --- |
| [DB backend](../../../../lib/backend/class-wp-lock-backend-db.php) | `e283ebdad56afbd85619ff8827623e2014f027e826a79ff1bfec7ee968d64925` |
| [controlled DB subclass](../../../../lib/backend/class-wp-lock-foundation-db.php) | `dfc7b6404e9167041d250e651d5d62894ee2e4221d16f939cdd19680ff8bdf80` |
| [foundation](../../../../lib/backend/class-wp-lock-foundations.php) | `9717b71007fa4bf4956c506f85cada9779465966880e40c5171a05a0faf86166` |
| [matrix runner](../../../../tests/diagnostics/d1-matrix.sh) | `7ad7eeb4ecdb4f8d2eb5c7dd1f6b86c602606bc03fc57398f401956b11ad68bf` |
| [E3 diagnostic](../../../../tests/diagnostics/e3-foundations.php) | `089c610ca42e2fddd26f0548069f8de0b91de95d84d2dd8266dfc410b5842127` |
| [existing DB tests](../../../../tests/lock/db.php) | `38ad867fac4d9d3417593650b1e9060ba3a4d9a1a5e431d32f7a42f7cbc16859` |

The final existing-suite run used an isolated source copy and temporary bootstrap, with the source parity recorded in [source-parity.txt](source-parity.txt). Its exact command was:

```bash
WP_TESTS_DIR=/tmp/wp-lock-b1-suite-final.5tLHjEKQ/runtime/wp-tests-lib PATH=/tmp/wp-lock-b1-suite-final.5tLHjEKQ/runtime/bin:$PATH composer test -- --bootstrap /tmp/wp-lock-b1-suite-final.5tLHjEKQ/phpunit-temp-bootstrap.php
```

[stdout](composer-test.stdout), [stderr](composer-test.stderr), and [exit](composer-test.exit) are retained verbatim after inspection. Exit `0`; PHPUnit 9.6.37 reported **54 tests, 143 assertions, zero skips**. Actual runtime: PHP CLI 7.4.3 with PCNTL/POSIX, WordPress `6.7-alpha-58576-src`, MariaDB `10.11.10-MariaDB-ubu2204`, InnoDB, `STRICT_TRANS_TABLES` and `REPEATABLE-READ`. The disposable database container used `--network none`, a tmpfs data directory, and a bind-mounted Unix socket directory; it was removed. Source parity: seven library files and nine test files matched the checkout copy by aggregate SHA-256; see the retained parity file. `composer-test.stdout` SHA-256 is `aea0292226f8bb585618550e7a336b46fda5d52a12a00d8fd5958764ad64acdf`; `composer-test.stderr` SHA-256 is `d85d03ec8badde99423276c184de4c34e403a195668831fb348e706200549e33`.

The six diagnostic rows use released WordPress targets; the PHPUnit run uses an older WordPress development snapshot. The suite verifies legacy public behavior and load compatibility, not the inactive foundation's public acquisition switch. Neither run is the E5 full matrix, E3 epic QA, a coverage threshold, or release support approval. The schema option marks preparation after verification; it does not activate the new protocol. Generated ignored `vendor/composer/autoload_classmap.php`, `autoload_files.php`, and `autoload_static.php` changed during setup and were left untouched; tracked source was unaffected. The delivery owner reviewed local implementation and runtime evidence; E3-02 stays in `review` until required PR review and batch merge.
