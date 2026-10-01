# Execution batches

The root [ROADMAP](../../ROADMAP.md) owns release scope. Contracts are in [E1](E1-contract-and-tests.md), [E3](E3-acquisition.md), [E4](E4-leases-and-recovery.md), and [E5](E5-migration-and-release.md); [common DoD](README.md#common-task-contracts) applies. B0 is **completed**; B1 is **in review after local verification**; B2–B5 are **not started**. All batches target one 3.0.0 candidate. Execution authorization covers B0 and B1 only.

A roadmap batch is an observable dependency boundary, not a single worker assignment. Global Gitflow targets `release/3.0`: use one `batch/bN` branch and one MR/PR per batch, starting each next branch from the updated `release/3.0`. Freeze, review, and test each batch branch before merge. Tasks remain `review` until their batch PR merges; a premerge QA readiness review does not close the final epic gate. Use a fresh bounded worker for each implementation, repair, test, or substantial document task. One writer is active at a time; a later writer starts only after the prior boundary is stable and accepted. Long, database-backed, coverage, and broad verification runs serially through `test_monitor`. Independent read-only analysis may overlap.

| Batch | Tasks | Observable output | Gate |
| --- | --- | --- | --- |
| B0 | E1-01, E1-02 → E1-QA | Accepted single ADR and focused diagnostic baseline | Required decisions recorded; E1-QA passes |
| B1 | E3-02 | Verified protocol foundations chosen by ADR | Schema/connection/identity behavior passes directed checks |
| B2 | E3-03 → E3-QA | Verified shared READ/exclusive WRITE ownership | E3-QA passes |
| B3 | E4-01, E4-02, E4-03 → E4-QA | Verified timing and conservative recovery | E4-QA passes |
| B4 | E5-01, E5-02, E5-03 | Migration rehearsal, required CI, consumer guidance | All three tasks delivered |
| B5 | E5-04 → E5-QA | Frozen, verified 3.0.0 candidate | E5-QA passes; handoff for a separate publication decision |

A `pass_with_notes` gate permits only nonblocking notes after every required criterion passes. A failed gate prevents dependent work; independently ready authorized tasks may continue. These are planned gates, not claims of verification.

## B0. Contract and diagnostic baseline

- **Input:** audit, current code/tests, saved probe, baseline revision, and future authorization to execute.
- **Order:** E1-01 drafts the single ADR and obtains OWNER decisions; E1-02 creates focused concurrent/fault baseline checks using existing helpers. These can be scheduled sequentially while decisions await review. E1-QA independently checks the accepted contract and evidence.
- **Verification:** real independent process/connection acquisition under RR/RC with ownership held through observation, READ/READ control, and a small set of directed fault controls. Label expected legacy failures as RED; use the audit probe only on a disposable database. Missing required process control or database is incomplete evidence.
- **Gate:** D1/D2/D3/D5/D6 decisions needed by implementation are recorded; D4 is deferred. E1-QA accepts evidence quality, not current backend safety.

**Execution checkpoint — 2026-09-29:** the complete planning/audit baseline was committed as `534ff82`. E1-01's [ADR draft](adr/001-contract.md) is in review, with OWNER acceptance and review/merge pending. Its local links were checked; root removed two Markdown trailing-space line breaks caught by the staged diff check (minimal integration correction). No runtime or target-protocol verification is claimed. E1-02 is starting independently. A fresh worker owns each document/test assignment; other root edits are limited to delivery bookkeeping. B1 remains outside the user's authorization.

**Diagnostic checkpoint — 2026-09-29:** E1-02 is in review. Fresh workers implemented the diagnostic and bounded repairs; serial `test_monitor` runs now reproduce expected legacy RED findings and pass GREEN controls on MySQL 8.0.46 and MariaDB 10.11.10 at RR/RC. [Evidence](evidence/E1-02.md) preserves the successful outputs and earlier failed setup/diagnostic attempts. A fresh documentation worker clarified the proposed ADR's unresolved-acquire, timeout, and diagnostic-text rules. OWNER acceptance, existing-suite verification, and review/merge delivery remain open; E1-QA waits on those requirements. B1 is neither authorized nor started.

**Runtime checkpoint — 2026-09-29:** the existing suite subsequently passed on an identical temporary source copy with compatible dependencies: 51 tests, 140 assertions, zero skips. No product source changed; diagnostic and suite containers were removed. E1-01 and E1-02 remain in review for OWNER acceptance and the common review/merge boundary; E1-QA remains waiting_dependency. Root persisted verification results as delivery bookkeeping. Stop at B0 pending those decisions; do not start B1.

**D1 decision checkpoint — 2026-09-29:** OWNER accepted the evidence-led compatibility research approach in the [ADR](adr/001-contract.md), prioritizing the newest released stable PHP, WordPress, and MySQL, retaining MariaDB, and expanding backward by evidence. B0 determines candidate configurations and reusable matrix evidence; E3/E4/E5 verify the new protocol and migration before support claims. The final D1 matrix and D2/D3 details/D5/D6 remain pending; E1-01 and E1-02 remain in review, E1-QA waits, and B1 is not authorized. This document update adds no runtime verification or delivery-boundary exception.

**D2 decision checkpoint — 2026-09-29:** OWNER accepted the [ADR](adr/001-contract.md)'s D2 architecture: permanent unique resource row, retained auto-increment owner ID, independent primary connection, captured namespace, and per-attempt token for uncertain INSERT/COMMIT recovery. Feasibility and required tests remain unverified. The final D1 matrix and D3 details/D5/D6 remain pending; E1-01 and E1-02 stay in review, E1-QA waits, and B1 remains unauthorized. No runtime check, QA pass, or merge waiver follows from this decision.

**D3 decision checkpoint — 2026-09-29:** OWNER accepted the [ADR](adr/001-contract.md)'s TTL=0/manual-recovery policy and finite-TTL consumer guidance. The exact recovery procedure and rehearsal remain pending, as do the final D1 matrix and D5/D6 decisions. E1-01 and E1-02 remain in review, E1-QA waits, and B1 is unauthorized. This documentation update adds no runtime verification or release claim.

**Remaining decision and Gitflow checkpoint — 2026-09-29:** OWNER accepted the ADR's proposed D3 manual recovery design, D5 stop/drain/verify/switch and rollback design, and D6 diagnostic/API/timeout design. These are design decisions only; no D2 feasibility, D3 recovery rehearsal, D5 migration/rollback rehearsal, D6 regression, or target-protocol verification is claimed. B0's empirical D1 target validation matrix and OWNER acceptance remain open, as does E1-QA. The six existing local commits are intended for the single B0 PR from `batch/b0` to `release/3.0`; no PR or merge is claimed by this checkpoint. E1-01 and E1-02 stay in `review` through the B0 merge, and E1-QA stays `waiting_dependency` until a frozen, reviewed and tested B0 revision is ready. The independent premerge review records readiness; the final epic gate verifies merge delivery and fails if it is missing. B1 remains unauthorized.

**D1 preparation and runtime checkpoint — 2026-10-01:** the [D1 proposal](evidence/D1-matrix.md), [serial runner](../../tests/diagnostics/d1-matrix.sh), and [six exact JSONL outputs with manifest](evidence/d1/manifest.md) are at HEAD `6086416` plus the identified uncommitted B0 diff. Preparation-worker Docker access was sandbox-blocked; a separate serial monitor then ran `D1_DOCKER='/mnt/c/Program Files/Docker/Docker/resources/bin/docker' bash tests/diagnostics/d1-matrix.sh` once. All six rows exited 0 with empty stderr, expected legacy RC RED and sampled RR/READ controls, and identical before/after Git status; read-only inspection found no runner containers. The runtime record is a legacy partial-WordPress diagnostic, separate from the earlier E1-02 suite pass. OWNER has not accepted the six-row validation-target/CI proposal; E1-01/E1-02 remain `review`, E1-QA remains `waiting_dependency`, and review/merge and later target-protocol gates remain open. B1 remains unauthorized; no broader suite, coverage, or release support claim follows.

**D1 acceptance checkpoint — 2026-10-01:** OWNER accepted the [six exact RR/RC validation targets and future CI split](evidence/D1-matrix.md). The [manifest](evidence/d1/manifest.md) retains six serial legacy diagnostics, all exit 0 with expected RED/GREEN observations. At frozen revision `17c610a`, existing CI runs `36873689653` and `36873685502` reported 12 green checks; these precede this decision-document update and do not test the target protocol. E1-01/E1-02 remain `review` until B0 review/merge; E1-QA is `todo` for independent premerge review, then final delivery review after merge. B1 remains unauthorized. Final support, D2 feasibility, recovery/migration rehearsals, and target-protocol checks remain later gates.

**Final B0 checkpoint — 2026-10-01:** [PR #7](https://github.com/hokoo/wp-lock/pull/7) merged into `release/3.0` at `2026-10-01T15:12:40Z` as `164390fee0e48d89d83845e52c967aba9b5fb62e` from final head `9b48a865af7cb0b8c69143b2504a10d35663c312`. The independent [E1-QA report](qa/E1.md) records premerge `pass_with_notes`, resolution of its CI provenance note, and final delivery `pass` after verifying merge/tree equivalence and all 12 green final-head CI jobs in runs `36882045664` and `36882054046`. The accepted [D1 matrix](evidence/D1-matrix.md) has six retained legacy diagnostic rows, all exit 0; earlier existing-suite evidence records 51 tests, 140 assertions, zero skips. E1-01, E1-02, and E1-QA are `completed`. E3-02 is ready but B1 execution is unauthorized; B1–B5 have not started. Legacy backend defects and D2 feasibility, D3/D5 rehearsals, D6 regressions, target-protocol verification, and final D1 release support remain open.

## B1. Ownership foundations

**Authorization checkpoint — 2026-10-01:** OWNER authorized B1 execution. E1-QA is accepted and E3-02 is `in_progress` on `batch/b1`, based on `release/3.0` at `3b68af3`. A fresh worker owns the bounded foundations and directed fixtures; root owns review and bookkeeping, with serial runtime verification after the writer stops. B2–B5 remain unauthorized. No B1 verification or merge is yet claimed.

**Implementation boundary — 2026-10-01:** worker `/root/b1_foundations` froze an uncommitted diff on `batch/b1` at base `3b68af3`: additive schema/connection/resource-token foundations, diagnostic-ID and finite-timeout fixes, directed diagnostic, and runner reuse. Worker reports PHP syntax, shell syntax, finite-timeout pure check, and diff whitespace checks passed; root independently ran `git diff --check` successfully. Runtime AC remain unverified; serial `test_monitor` and focused read-only review are underway. No public protocol switch, commit, merge, or task completion is claimed.

**Repair checkpoint — 2026-10-01:** the first serial matrix attempt (`/tmp/wp-lock-d1.SCDJzSxQ`) exited 2 on MySQL 26.7 / PHP 8.5.11 / WordPress 7.1.2: schema metadata lacked the expected lowercase `data_type` key. No RR/RC cases or later rows ran. Focused read-only review also left lost-response/write-reconnect evidence and representable UTF-8 diagnostic handling open. The initial diff is not accepted; a fresh bounded repair worker owns those gaps and invalid-unique-index checks. No suite, commit, merge, or B1 completion is claimed.

**D2 feasibility decision gate — 2026-10-01:** after bounded metadata, routing-fixture, and MariaDB `read_only` repairs, the instrumented matrix (`/tmp/wp-lock-d1.1qBqC2M2`) passed MySQL 26.7 and 9.7 at RR/RC, then stopped on MariaDB 13.0.2 at `current_read REPEATABLE READ / resource_lock`: errno 1020, SQLSTATE HY000, record changed since the snapshot. No later matrix rows ran; runner exited 2 and its container was removed. MariaDB documents `innodb_snapshot_isolation=ON` by default from 11.6.2 and transaction rollback on this conflict. Under ADR D2, this negative feasibility result requires a decision before changing controlled-session semantics. OWNER has been asked whether to set and verify this variable OFF only on the independent lock connection; no decision or implementation of that proposal is claimed. Existing-suite verification proceeds independently; B1 is not accepted.

**D2 clarification accepted — 2026-10-01:** OWNER authorized setting and verifying `innodb_snapshot_isolation=OFF` only on the independent WP Lock connection, preserving caller state and RR/RC. The [ADR clarification](adr/001-contract.md#d2-clarification--controlled-mariadb-snapshot-isolation-2026-10-01) records the decision. Implementation waits for the active suite process to finish; the revised matrix must still pass.

**Verified local B1 checkpoint — 2026-10-01:** after OWNER accepted the controlled MariaDB session clarification, the six-row matrix (`/tmp/wp-lock-d1.HkRINW48`) exited 0 with all directed cases passing at RR/RC. MariaDB caller snapshot isolation remained ON while the controlled session verified OFF; MySQL did not expose the variable. The existing suite on an identical temporary source copy (`/tmp/wp-lock-b1-suite-final.5tLHjEKQ`) passed: 54 tests, 143 assertions, zero skips, exit 0. All disposable containers were removed. Fresh workers handled every implementation/repair and evidence assignment; root changed only delivery/decision bookkeeping. [E3-02 evidence](evidence/E3-02.md) retains provenance, earlier failures, deterministic fault-injection limits, and the three ignored Composer autoload files unintentionally regenerated during setup and left untouched. E3-02 is `review`; the local implementation/evidence commit carries this checkpoint, while required PR/merge delivery remains pending. E3-03 waits for B1 merge, and B2 remains unauthorized. No E3 epic QA, coverage, protocol switch, or release-support claim is made.

- **Input:** E1-QA and accepted ADR.
- **Order:** E3-02 implements only schema/connection/identity pieces actually required by the chosen protocol, with directed install, error, namespace, strict-mode/Unicode/shared-prefix, and caller-transaction checks.
- **Verification:** targeted tests and syntax checks by the worker; database and broader checks after the stable writer boundary.
- **Gate:** DDL failure or invalid schema never enables the target; namespace/full resource identity and caller transaction independence are verified. No speculative schema upgrade for diagnostic text.

## B2. Ownership protocol

- **Input:** E3-02 delivered and E1-QA accepted.
- **Order:** E3-03 implements acquire/release and wrapper lifecycle against the chosen protocol; E3-QA independently reviews the integrated result.
- **Verification:** MySQL/MariaDB × supported RR/RC × WRITE/WRITE, READ/WRITE, READ/READ, controlled interleavings, outer rollback, reconnection/uncertain commit, and predecessor release. Retain ownership until observation.
- **Gate:** successful supported RR/RC acquisition with exclusion, shared readers, clear DB error/uncertainty, and no caller-transaction mutation.

## B3. Timing and recovery

- **Input:** accepted E3 and ADR time/error/recovery contracts.
- **Order:** E4-01 lifetime, E4-02 deadlines, E4-03 cleanup/manual recovery in sequential bounded assignments; E4-QA reviews the integrated behavior. Coordinate any shared acquisition-file edits through one writer at a time.
- **Verification:** finite TTL, slow SQL, expiry/takeover, host clock concerns, late success/cleanup, alternating errors/contention, TTL=0 uncertain identity, and verified manual procedure.
- **Gate:** no known-expired success or concealed confirmed owner; database uncertainty preserves ownership or produces explicit recovery state; successor remains intact.

## B4. Migration, CI, and documentation

- **Input:** E1/E3/E4 accepted, agreed D5 procedure.
- **Order:** E5-01 migration and rollback; E5-02 matrix/coverage; E5-03 guidance can draft earlier but finalizes after the verified runbook. Assign sequential workers.
- **Verification:** clean install, upgrade, partial DDL failure, rollback, incompatible-protocol switching, `composer test`, `composer test:coverage`, >=90% line coverage, required process-control preflight, and agreed matrix. No required skip.
- **Gate:** old/new protocols cannot independently own the same resource; unresolved TTL=0 owners block switching; documentation matches tested behavior.

## B5. Candidate and handoff

- **Input:** delivered E5-01–03, required merges, accepted upstream QA.
- **Order:** E5-04 freezes exact revision/package and metadata; E5-QA independently reviews candidate, migration, CI, and issue closure; DO hands evidence to OWNER.
- **Verification:** package installation, metadata, critical migration/rollback paths, actual CI results, and all ISSUE-001–011 criteria.
- **Completion boundary:** verified candidate and handoff. Tagging, publication, and rollout need separate authorization.

## Transition rules

1. Pull only tasks whose DoR and dependencies are met. Scheduling a batch does not approve a pending ADR choice.
2. Honor the batch branch/PR and merge DoD. A local diff or premerge QA readiness finding is provisional and cannot close a task or epic; final epic QA verifies delivery after merge.
3. At each boundary record task status, artifacts, exact checks, evidence, newly ready work, risks, and next batch.
4. Required failures return to the owning task for bounded repair. Re-freeze and re-review the affected revision.
5. Change release scope in ROADMAP first. Renewal remains only deferred ISSUE-012 until separately authorized.
