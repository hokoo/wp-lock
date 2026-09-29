# Execution batches

The root [ROADMAP](../../ROADMAP.md) owns release scope. Contracts are in [E1](E1-contract-and-tests.md), [E3](E3-acquisition.md), [E4](E4-leases-and-recovery.md), and [E5](E5-migration-and-release.md); [common DoD](README.md#common-task-contracts) applies. B0 is **in progress**; B1–B5 are **not started**. All batches target one 3.0.0 candidate. The current execution authorization covers B0 only.

A roadmap batch is an observable dependency boundary, not a single worker assignment. Use a fresh bounded worker for each implementation, repair, test, or substantial document task. One writer is active at a time; a later writer starts only after the prior boundary is stable and accepted. Long, database-backed, coverage, and broad verification runs serially through `test_monitor`. Independent read-only analysis may overlap.

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

## B1. Ownership foundations

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
2. Honor commit/merge DoD for future execution. A local diff is provisional and cannot close a task or epic.
3. At each boundary record task status, artifacts, exact checks, evidence, newly ready work, risks, and next batch.
4. Required failures return to the owning task for bounded repair. Re-freeze and re-review the affected revision.
5. Change release scope in ROADMAP first. Renewal remains only deferred ISSUE-012 until separately authorized.
