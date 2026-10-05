# E4 directed matrix evidence — 2026-10-02

The final E4-03 diagnostic ran once with:

```bash
D1_DIAGNOSTIC_SCRIPT=tests/diagnostics/e4-timing.php D1_REQUIRE_PASS=1 D1_DOCKER='/mnt/c/Program Files/Docker/Docker/resources/bin/docker' bash tests/diagnostics/d1-matrix.sh
```

The retained run is `/tmp/wp-lock-d1.ER6VsWVt`, with recorded base revision `9dac654cd68558892c40f0df34e2ab79fe4f302f`. Its identical before/after Git status lists show the uncommitted E4-03 implementation and diagnostic on that base. Those three source paths were subsequently committed in `7e5a92c897f98e9045a2f196ee9b0016c2773664`; the current `lib/` and matrix diagnostic paths match that commit byte for byte. The later native PHPUnit test in `tests/lock/db.php` is outside the matrix-run source boundary. The six JSONL outputs are copied here; temporary container and build logs are not delivery artifacts.

Each row exited `0`, had empty stderr, and records two actual InnoDB environment entries (RR and RC), 64 passing behavioral cases (32 per isolation), 16 manual-recovery audit events (eight per isolation), and one passing summary. No case was skipped. Audit entries are fixture observations, not extra behavioral cases. Each row records 12 retained-barrier events and four postcondition-confirmed barrier removals. The containers used Unix sockets, `--network none`, and tmpfs database storage; the runner stopped each container before advancing to the next row.

| Row | Actual PHP / WordPress / database `VERSION()` | Database image digest | JSONL SHA-256 |
| --- | --- | --- | --- |
| [mysql267.jsonl](mysql267.jsonl) | 8.5.11 / 7.1.2 / 26.7.0 | `mysql@sha256:9d48c42f8341068f199116dfccb919b607c99765b5c61e549a548a43033471a4` | `ea4db197da93a6bf865cb63adcac071d5bb04cf2bfa94cda888ff104cc7c67c6` |
| [mysql97.jsonl](mysql97.jsonl) | 8.5.11 / 7.1.2 / 9.7.2 | `mysql@sha256:e2bde46db6563855d7177adb5f0b57b9dc663f5a20927a90f4259d3312068497` | `343c95d3073a8199180264bd3e7f67e244f1235bbbb3433ba54e07e4516e56e1` |
| [maria130.jsonl](maria130.jsonl) | 8.5.11 / 7.1.2 / 13.0.2-MariaDB-ubu2604 | `mariadb@sha256:f1bba652ba57bea3099ca2fe1af692af537c27d96e0bcde39dce29e2ba1ec4f3` | `42aa9858dfbf9999b787360f8bdf95c2fe4ea56856018f96dcb2cd79f6ce2183` |
| [maria123.jsonl](maria123.jsonl) | 8.5.11 / 7.1.2 / 12.3.3-MariaDB-ubu2404 | `mariadb@sha256:2bdff1534a7e569fecaf4b10b66ad0d410806e382d9db29ba305448addda97c4` | `723ecca6038629798dd743d1be274f78d3a73662b80a0264c2ed2272569d5646` |
| [mysql97-floor.jsonl](mysql97-floor.jsonl) | 7.4.33 / 6.2.13 / 9.7.2 | `mysql@sha256:e2bde46db6563855d7177adb5f0b57b9dc663f5a20927a90f4259d3312068497` | `8536b1ba08b87507ad2f7789f91b6af48c1fcd5ef202d870ae70d1b087f70fab` |
| [maria123-floor.jsonl](maria123-floor.jsonl) | 7.4.33 / 6.2.13 / 12.3.3-MariaDB-ubu2404 | `mariadb@sha256:2bdff1534a7e569fecaf4b10b66ad0d410806e382d9db29ba305448addda97c4` | `8bd8cc26d9551cdda738792b9f3ee556a357a98725749929f8db2a2b932e60a7` |

The PHP image bases were `php:8.5.11-cli-bookworm` (`php@sha256:d551e79d694fd91c4fdf34c4adcc52dd2042a064b881e25c319b2533ead0682c`, built image `sha256:061894dc3cbbac282db196d657261ef2a102b75b6c805715db10c9ed77753d`) and `php:7.4.33-cli` (`php@sha256:620a6b9f4d4feef2210026172570465e9d0c1de79766418d3affd09190a7fda5`, built image `sha256:bff73ccbe994d957241d1c2b1f695ff9833e73cb7e85f4f09018d918c98b9aee`). Both passed the runner's `mysqli`, PCNTL, and POSIX preflight. WordPress tarball SHA-256 values were `c0c666689d66b870d8825500bb8e402ed04de52602c61a6f516c6236b8c9ac67` for 7.1.2 and `50a11569de9b23b2ab073f531316f52fb90578a89ae667fa3facb6e6bed16747` for 6.2.13.

| Tested source path | SHA-256 at matrix run and in `7e5a92c` |
| --- | --- |
| `lib/backend/class-wp-lock-backend-db.php` | `6765053c9d3dc1ae13457b3a1b0cda2b4303ac6710c58fc66c9946fcfd194838` |
| `lib/backend/class-wp-lock-foundations.php` | `277671fedb07359d68a732f156b24d046ca562086650a7d1aa1bba0a885eb6d0` |
| `lib/backend/class-wp-lock-foundation-db.php` | `40511ec91c500d17a5d4ba8ce15c4c3db4c9de7e69e68e4264555341cbd9eec6` |
| `lib/class-wp-lock.php` | `c2319fde5c83e63de13a87aa032a40486e1aac90b2812814077e2d74bed20afc` |
| `tests/diagnostics/d1-matrix.sh` | `93fbd3fc2783be520179db9eb3363ac235ee2535999ecd307391f40f027334ad` |
| `tests/diagnostics/e3-ownership.php` | `b6f109226c8a9623da37c711166eb51e710bda51ae7564c87f352b45357f0891` |
| `tests/diagnostics/e4-timing.php` | `15f5a79d7eaf4319270205d2acb36a8e67b3eb2db7429583be6d0870f0c119e8` |
| `tests/diagnostics/e4-timing-cases.php` | `77f65ed4ebaa16a1b1a93622ed80eaba074ee0f74245b3e8c49b67d698de69fa` |
| `tests/diagnostics/e4-recovery-cases.php` | `8045cf752e0ca0db02bd1593a6f9af8bc597316e903b74e6ee0072e2391f0d99` |

The matrix uses the guarded partial WordPress bootstrap. Full WordPress PHPUnit and Clover coverage are separate B3 gates. [The E4-03 boundary](../E4-03.md) records earlier failed diagnostic runs; they are not represented as passing evidence here. This rehearsal establishes the directed behavior in disposable databases, not real production admissions stopping or E5 migration.

## Integrated native PHPUnit and coverage

On 2026-10-02, `/root/b3_native_gate_verified` ran the following ladder once successfully after a sandbox denial before container startup:

```bash
E4_DOCKER='/mnt/c/Program Files/Docker/Docker/resources/bin/docker' E4_POLYFILLS_SOURCE=/tmp/wp-lock-b1-suite-final.5tLHjEKQ/vendor/yoast/phpunit-polyfills bash tests/diagnostics/e4-suite.sh
```

Artifacts are retained in `/tmp/wp-lock-e4-suite.AsDXvprQ`. The source boundary is `7e5a92c` plus the native test/runner diff; `tests/lock/db.php` SHA-256 is `66f211e317f4f32a639d963f786b7029f0119cf2e9e4c1e5eeeee4f552604105`, and library/matrix sources remain identical to the hashes above. Checkout/snapshot source comparison and before/after status checks passed.

`composer test` and `composer test:coverage` each exited 0 with **102 tests, 454 assertions, zero skips**. The runner's JUnit checks and the existing **>=90% Clover gate passed: 568/615 statements, 92.36%**. Actual runtime was PHP 7.4.3 with Xdebug 2.9.2, PHPUnit 9.6.7, WordPress 6.7-alpha-58576-src, and MariaDB 10.11.10 InnoDB at REPEATABLE-READ with autocommit enabled. The isolated vendor copy loaded Polyfills 4.0.0; checkout Composer metadata still describes 1.0.5, and checkout dependencies were not changed. WordPress was an existing local test fixture, not a verified pristine upstream release. The socket-only database used `--network none` and tmpfs; the stop trap completed, and an independent container query confirmed removal.

Earlier attempts remain separate: `A3Svec41` stopped before tests on incompatible Polyfills; `TlmJvMIU` and `YGdt88pw` passed plain PHPUnit but failed coverage because 20 ms fixture budgets expired before the intended delay injections. The native tests now allow one second for setup and inject 1.2-second delays while retaining trigger and behavior assertions. Their generated Clover percentages were not passing gates. No library change followed those fixture repairs. This local suite does not replace the six-row diagnostic matrix or final-head CI.
