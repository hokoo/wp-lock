# D1 accepted target validation matrix — 2026-10-01

OWNER accepted these six B0 validation targets and the future CI split on 2026-10-01; this is not an accepted release support matrix. The [E1-02 record](E1-02.md) is historical evidence for the **legacy 2.0 backend**: PHP 7.4.3, a WordPress 6.7 alpha source snapshot, MySQL 8.0.46 and MariaDB 10.11.10. The [new serial run](d1/manifest.md) adds six released-version configurations, with exact [JSONL results](d1/mysql267.jsonl) and hashes retained. Neither run tests the 3.0 protocol or makes WordPress `7.1-src` in the older CI a released version.

## Released candidates and smallest matrix

| Track | Exact released candidate on 2026-10-01 | Validation role and provenance note |
| --- | --- | --- |
| PHP | [8.5.11, 2026-09-24](https://www.php.net/ChangeLog-8.php) | Newest stable reference; observed in the legacy diagnostic. PHP `>=7.4` remains the Composer constraint. |
| WordPress | [7.1.2, 2026-09-22](https://wordpress.org/news/2026/09/wordpress-7-1-2-release/) | Newest stable source used by the partial `wpdb` bootstrap. The earlier `7.1-src` suite is a development snapshot. |
| MySQL Innovation | [26.7.0, 2026-07-28](https://dev.mysql.com/doc/relnotes/mysql/26.7/en/) | Newest released server series; `mysql:26.7.0` is the [official image tag](https://hub.docker.com/_/mysql). Oracle's 26.7.1 note concerns a [Docker-image-only security update](https://dev.mysql.com/doc/relnotes/mysql/26.7/en/news-26-7-1.html), but the official image list has no `mysql:26.7.1` tag; the recorded `26.7.0` digest does not establish whether that update is included. |
| MySQL LTS | [9.7.2, 2026-07-28](https://dev.mysql.com/doc/relnotes/mysql/9.7/en/news-9-7-2.html) | Latest LTS comparison, `mysql:9.7.2`; [Oracle identifies 9.7 as LTS and 26.7 as Innovation](https://dev.mysql.com/doc/refman/9.7/en/which-version.html). |
| MariaDB rolling | [13.0.2, 2026-09-15](https://mariadb.org/mariadb/all-releases/) | Newest **stable** rolling release, `mariadb:13.0.2`; 13.1.1 is RC and 13.2.0 preview. |
| MariaDB LTS | [12.3.3, 2026-08-22](https://mariadb.org/mariadb/all-releases/) | Latest LTS comparison, `mariadb:12.3.3`; [maintenance policy](https://mariadb.org/about/) and [official image tags](https://raw.githubusercontent.com/docker-library/official-images/master/library/mariadb). |
| Backward anchors | [WordPress 6.2.13, 2026-09-22](https://wordpress.org/download/releases/); [PHP 7.4.33, 2022-11-03](https://www.php.net/releases/) | The 6.2 security branch and existing PHP floor, paired here with MySQL 9.7.2 and MariaDB 12.3.3. This pair is an anchor, not an implicit universal WordPress floor. PHP 7.4 is an archived, unsupported PHP line; the library constraint is unchanged. |

The serial diagnostic ran PHP 8.5.11 + WordPress 7.1.2 against all four database candidates and PHP 7.4.33 + WordPress 6.2.13 against the two LTS candidates, each at RR and RC. This small matrix samples current versions, both engine families, LTS behavior, and backward anchors without asserting a full Cartesian support range. The MySQL 8.0.46/MariaDB 10.11.10 results remain a historical comparison.

## Observed legacy results and accepted validation decision

The exact [six JSONL files and provenance](d1/manifest.md) show these dual-acquisition counts in 100 rounds per case. Every RR WRITE/WRITE and READ/WRITE count was 0/100; READ/READ was 100/100 under both isolations for every row. Each row had zero pair SQL-error rounds and row mismatches, distinct independent connections, passing ordered owner/contender controls at RR and RC, a passing rollback defect reproduction, a passing finite-TTL/predecessor-release control, strict diagnostic-ID behavior, nullable `original_key` raw-SQL feasibility, and a final `pass=true` with no failures. Here, `pass` means **expected legacy RED findings and GREEN controls matched**, not safe RC acquisition.

| Row ([exact result](d1/manifest.md)) | PHP / WordPress / actual server | RR conflicting duals WW / RW | RC conflicting duals WW / RW | Empirical classification |
| --- | --- | ---: | ---: | --- |
| [mysql267](d1/mysql267.jsonl) | 8.5.11 / 7.1.2 / MySQL 26.7.0 | 0 / 0 | 100 / 100 | Confirmed sampled legacy controls and RC defect; target protocol untested |
| [mysql97](d1/mysql97.jsonl) | 8.5.11 / 7.1.2 / MySQL 9.7.2 | 0 / 0 | 100 / 99 | Confirmed sampled legacy controls and RC defect; target protocol untested |
| [maria130](d1/maria130.jsonl) | 8.5.11 / 7.1.2 / MariaDB 13.0.2 | 0 / 0 | 50 / 91 | Confirmed sampled legacy controls and RC defect; target protocol untested |
| [maria123](d1/maria123.jsonl) | 8.5.11 / 7.1.2 / MariaDB 12.3.3 | 0 / 0 | 81 / 95 | Confirmed sampled legacy controls and RC defect; target protocol untested |
| [mysql97-floor](d1/mysql97-floor.jsonl) | 7.4.33 / 6.2.13 / MySQL 9.7.2 | 0 / 0 | 100 / 98 | Confirmed sampled legacy controls and RC defect; target protocol untested |
| [maria123-floor](d1/maria123-floor.jsonl) | 7.4.33 / 6.2.13 / MariaDB 12.3.3 | 0 / 0 | 87 / 98 | Confirmed sampled legacy controls and RC defect; target protocol untested |

**D1 decision accepted by OWNER, 2026-10-01:** these six exact configurations are mandatory validation targets for E3/E4 and the E5 release-candidate gate. Require successful target-protocol shared READ/exclusive WRITE evidence at both RR and RC on each row before claiming support for that row. E3/E4 also need their directed ownership, transaction, deadline, recovery, and failure checks; E5 owns migration, full-suite/coverage, the final support claim, and OWNER acceptance. Future regular PR CI uses the four current/floor LTS rows (`mysql97`, `maria123`, `mysql97-floor`, `maria123-floor`); newest MySQL Innovation and MariaDB rolling rows run periodically and again at the mandatory candidate gate. Current PHP 7.4–8.3 CI and the PHP `>=7.4` constraint remain. WordPress 6.2 is a backward anchor, not a universal support floor. Add other combinations if observed failures or consumer risk require them. This decision does not authorize B1 implementation.

The target D2 capability checklist for later E3 feasibility is: primary InnoDB and unique permanent resource row; two first acquirers serialize; owner rows use a **current locking read** even after an RR snapshot; distinct READ owners coexist; independent connection and primary/schema identity survive caller rollback; captured namespace survives prefix/blog changes; attempt-token reconciliation after uncertain INSERT/COMMIT; fail closed on routing or connection uncertainty. B0's legacy diagnostic does **not** prove these. D3 manual recovery, deadlines/TTL, D5 switching, and D6 diagnostics likewise need their assigned later directed tests.

## Serial, disposable legacy diagnostic recipe for `test_monitor`

Run [the bounded serial runner](../../../tests/diagnostics/d1-matrix.sh) from this frozen checkout after the writer stops:

```bash
D1_DOCKER='/mnt/c/Program Files/Docker/Docker/resources/bin/docker' bash tests/diagnostics/d1-matrix.sh
```

Use `D1_DOCKER=docker` where Docker is in `PATH`. The runner requires Docker daemon access, `curl`, `tar`, and `sha256sum`; it pulls exact database and PHP image tags from the six-row matrix, records their pulled digests, and builds two temporary PHP CLI images with mysqli and PCNTL. It fails if PHP/POSIX preflight fails. It downloads released WordPress 7.1.2 and 6.2.13 tarballs from wordpress.org and records their SHA-256 values. It creates one server at a time with `--network none`, a tmpfs data directory, a Unix socket, and only the literal disposable `wp_lock_e1` database. [The existing diagnostic](../../../tests/diagnostics/e1-baseline.php) retains its own database-name, socket, process-control, connection, InnoDB, strict-mode, and RR/RC guards; it drops and recreates only `e1_lock` in that disposable database. Do not point the runner at a consumer database or run the destructive audit probe.

The runner prints its `/tmp/wp-lock-d1.XXXXXXXX` artifact directory, saves the exact revision and before/after Git status, source checksums, PHP built image IDs, image digests, per-row JSONL, stderr, container logs, and exit codes. It stops on failed pull/build, PHP extension/version preflight, database readiness, missing final summary, wrong PHP/WordPress version, non-InnoDB, missing RR/RC evidence, or container cleanup failure; an EXIT trap stops the active server on interruption. A diagnostic exit 1 **with** a complete summary is retained and the next row runs: on this legacy backend it can be an inconclusive race sample or a genuine changed observation. Inspect errors and final summaries before classification. An RC run without a reproduced race is inconclusive, never a GREEN safety result. A sampled RR pass is only a sampled control.

Classify actual configuration evidence as **confirmed** only for the stated legacy control or defect, **conditional** if a specific requirement limits use, **incompatible** on a reproducible capability failure, **environment-blocked** on setup failure, or **untested** before execution. No class grants 3.0 support. After B0 acceptance, E3 must establish independent writable-primary routing, schema and namespace identity, privileges, current locking reads, transaction isolation, and uncertain-outcome handling on the chosen target protocol. Later E4/E5 gates own TTL, recovery, migration, `composer test`, coverage, and final release support claims.
