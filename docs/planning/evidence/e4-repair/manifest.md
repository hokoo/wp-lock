# E4 acquisition-cleanup repair evidence — 2026-10-02

This is the repair run after the [initial E4 evidence](../e4/manifest.md) and [initial independent QA failure](../../qa/E4.md#initial-gate--2026-10-02). The repair requires an expired-owner DELETE during acquisition to affect exactly one row before a new owner can be inserted. The new diagnostic and native regressions inject a zero-row DELETE, require explicit failure with the predecessor still present, then verify a retry and successor safety. The original E4 JSONL files remain unchanged.

## Source and commands

`/root/b3_qa_repair_verification` ran the following commands serially, once each:

```bash
D1_DIAGNOSTIC_SCRIPT=tests/diagnostics/e4-timing.php D1_REQUIRE_PASS=1 D1_DOCKER='/mnt/c/Program Files/Docker/Docker/resources/bin/docker' bash tests/diagnostics/d1-matrix.sh
E4_DOCKER='/mnt/c/Program Files/Docker/Docker/resources/bin/docker' E4_POLYFILLS_SOURCE=/tmp/wp-lock-b1-suite-final.5tLHjEKQ/vendor/yoast/phpunit-polyfills bash tests/diagnostics/e4-suite.sh
```

The matrix artifacts are in `/tmp/wp-lock-d1.7GxGZlWM`; its recorded HEAD is `fecd10d92093dd38a071b4275eda82b1a70c663f`, with the three repair source paths modified before and after the run. The native artifacts are in `/tmp/wp-lock-e4-suite.Ejm521l0`; its recorded HEAD is also `fecd10d`, with identical before/after status. Those three source edits were then committed unchanged as `6b67bb0a307b9410abc8213c06f342b844a9a9b6` (`fecd10d..6b67bb0`: three paths, 91 insertions and one deletion). The native runner's snapshot/checkout SHA-256 comparison passed. The committed repair paths match the native snapshot byte for byte:

| Source path | Git blob ID in `6b67bb0` | SHA-256 in checkout and native snapshot |
| --- | --- | --- |
| `lib/backend/class-wp-lock-backend-db.php` | `f81985d1ed06994cc8fe3d52b66d364e53a7dc2e` | `a5a707b767c0faf377b22a7e5d502a874177b2f669867580e2306fd5097ea96d` |
| `tests/diagnostics/e4-recovery-cases.php` | `16e748b8bdd35b30ce83e8591439697bc70b36cb` | `b8c1b3fa344cbf0aa4b716f9ded0efce54e4c27c11012d575465c2cd29efa47d` |
| `tests/lock/db.php` | `16eb05e656a3463f7812b4bddefb2bf9f1255e84` | `4c10b0437c2a3f5f8321c812e08d394dfd449df0d9838905172c0f7983aba8fd` |

The matrix mounts the checkout read-only. Its unchanged status and the later three-path commit establish the reported run boundary; the matrix runner does not retain its own byte-level source snapshot. The unchanged matrix entry point `tests/diagnostics/e4-timing.php` has SHA-256 `15f5a79d7eaf4319270205d2acb36a8e67b3eb2db7429583be6d0870f0c119e8`, and `tests/diagnostics/d1-matrix.sh` has `93fbd3fc2783be520179db9eb3363ac235ee2535999ecd307391f40f027334ad` in the checkout and native snapshot.

## Directed six-row matrix

Each row exited `0` with empty stderr and valid JSONL: **85 records = 66 passing behavioral cases (33 RR, 33 RC), 16 manual-recovery audit events, two environment records, and one passing summary**. There were no skips. The new `acquire_zero_row_expired_cleanup` case passed under both isolations in every row. Each row recorded 12 retained-barrier audit events and four removals after verified postconditions. Audit events are procedure evidence, not additional behavioral passes. The serial runner used Unix sockets, network-disabled containers, and tmpfs database storage, stopping each database before the next row.

| Retained output | Actual PHP / WordPress / database `VERSION()` | Image digest | JSONL SHA-256 |
| --- | --- | --- | --- |
| [mysql267.jsonl](mysql267.jsonl) | 8.5.11 / 7.1.2 / 26.7.0 | `mysql@sha256:9d48c42f8341068f199116dfccb919b607c99765b5c61e549a548a43033471a4` | `f83be2cbf497dae3762ff4e0486fa1421088e44680d475712236bab98a34f5bf` |
| [mysql97.jsonl](mysql97.jsonl) | 8.5.11 / 7.1.2 / 9.7.2 | `mysql@sha256:e2bde46db6563855d7177adb5f0b57b9dc663f5a20927a90f4259d3312068497` | `42215184d95884b25fcd79b212936f6cf3b45ca32043bb05fd77ecb48d4d355f` |
| [maria130.jsonl](maria130.jsonl) | 8.5.11 / 7.1.2 / 13.0.2-MariaDB-ubu2604 | `mariadb@sha256:f1bba652ba57bea3099ca2fe1af692af537c27d96e0bcde39dce29e2ba1ec4f3` | `eacb6e486f4c6d94e9e80184175c6b3b5c764d49a71c4977417766eae48c25d1` |
| [maria123.jsonl](maria123.jsonl) | 8.5.11 / 7.1.2 / 12.3.3-MariaDB-ubu2404 | `mariadb@sha256:2bdff1534a7e569fecaf4b10b66ad0d410806e382d9db29ba305448addda97c4` | `a9d674040654404b7687a47843fd4114804b6dd4d3e9d1feae4d28bd2f7eefe9` |
| [mysql97-floor.jsonl](mysql97-floor.jsonl) | 7.4.33 / 6.2.13 / 9.7.2 | `mysql@sha256:e2bde46db6563855d7177adb5f0b57b9dc663f5a20927a90f4259d3312068497` | `44150975acb9fefb1ae88870aadd38e86b463b55cdf6aca430630aa1da2babf4` |
| [maria123-floor.jsonl](maria123-floor.jsonl) | 7.4.33 / 6.2.13 / 12.3.3-MariaDB-ubu2404 | `mariadb@sha256:2bdff1534a7e569fecaf4b10b66ad0d410806e382d9db29ba305448addda97c4` | `134eec3d37211038b90d885216b76a089a25c58eb87c740f1e4bc763e30382e1` |

Both isolation records in every row report InnoDB and actual `REPEATABLE READ` or `READ COMMITTED`. The runner's PHP preflight required `mysqli`, PCNTL, and POSIX. WordPress tarball SHA-256 values were `c0c666689d66b870d8825500bb8e402ed04de52602c61a6f516c6236b8c9ac67` (7.1.2) and `50a11569de9b23b2ab073f531316f52fb90578a89ae667fa3facb6e6bed16747` (6.2.13).

## Native suite and limits

The isolated native runner executed `composer test`, `composer test:coverage`, and the repository's **>=90% Clover line gate**. All three exited `0`; both PHPUnit runs reported **103 tests, 469 assertions, zero errors, failures, or skips**. Clover measured **571/616 lines, 92.69%**. Actual runtime was PHP 7.4.3 with Xdebug 2.9.2, PHPUnit 9.6.7, WordPress `6.7-alpha-58576-src` from a local test fixture, and MariaDB `10.11.10-MariaDB-ubu2204` / InnoDB / REPEATABLE-READ / autocommit `1`. PCNTL and POSIX were loaded. The isolated fixture copied Polyfills 4.0.0 while checkout Composer metadata still described 1.0.5; checkout dependencies were unchanged. Expected fault-injection SQL errors appeared on native stderr without JUnit failures. The runner's before/after Git status matched, and its disposable database container was stopped. This local fixture is not a pristine upstream WordPress release or final-head CI.

The six-row diagnostic uses a guarded partial WordPress bootstrap and samples controlled schedules; it cannot prove every interleaving or real production admission stopping. The disposable TTL=0 procedure rehearsal does not authorize production row deletion. [E3 QA passed with notes](../../qa/E3.md); The initial E4 `fail` is preserved for its earlier boundary; the repair was delivered through [PR #12](https://github.com/hokoo/wp-lock/pull/12), and fresh independent [final E4-QA](../../qa/E4.md#final-delivery--2026-10-02) returned `pass_with_notes` on merge `04f1555`. E5 migration, rollback, and final release support remain pending.
