# E1. Contract and reproducible verification

**Target version:** 3.0.0 — planned; see [ROADMAP](../../ROADMAP.md#v300).
**Issues:** [ISSUE-009](../issues/README.md#issue-009), [ISSUE-010](../issues/README.md#issue-010); focused characterization of ISSUE-001–011.

**Outcome:** an accepted single contract/protocol ADR and reliable evidence for subsequent fixes.
**Scope:** D1/D2/D3/D5/D6, existing contract/code/audit, focused diagnostic scenarios, and actual test environments.
**Out of Scope:** backend fixes, full duplication of later directed regressions, and ISSUE-012 renewal.
**Success Criteria:** ADR decisions are accepted; focused RED findings and GREEN controls are reproducible; independent E1-QA accepts the evidence and its limits.
**Dependencies:** [audit](../review-2026-09-29/assessment.md), current source and tests; no unfinished upstream epic.
**Risks/Open Questions:** this public library's consumer environments and drop-ins are unknown; the final support matrix remains pending evidence. An entry barrier alone does not control SQL scheduling.
**Tasking Guidance:** use [common contracts and `$decompose-work`](README.md#common-task-contracts).

## E1-01. One contract and protocol ADR

- **Status:** completed. **Owner:** DO + OWNER. **Priority:** P0. **Batch:** B0.
- **Goal:** settle the 3.0.0 contract and the minimum protocol needed to satisfy it.
- **Scope:** `docs/planning/adr/001-contract.md`; D1/D2/D3/D5/D6; supported matrix, READ/WRITE, resource/owner identity, namespace, caller transactions, serialization, commit uncertainty, TTL/deadlines, DB errors, cleanup, lost ownership, migration, and compatibility.
- **Out of Scope:** implementing a candidate design, renewal, automatic external-write fencing, or selecting an architecture before evidence and OWNER acceptance.
- **DoR:** README contract, current code/tests, and audit evidence are available.
- **DoD:** DoD-D; ADR compares viable alternatives, records decisions and tests/feasibility evidence, and is reviewed and merged before dependent implementation.
- **AC:** require real READ/WRITE acquisition on supported RR/RC in the target protocol; explain empty-resource and conflict serialization, stale RR snapshots, caller rollback/deadlock/reconnect, known and uncertain commits, release/cleanup, namespace changes, time/error/recovery results, safe switching and rollback. Retain existing `insert_id` identity, wrapper held lifecycle, and tests where compatible; justify any new token, connection, table, or abstraction by chosen protocol or uncertainty. D3 uses conservative TTL=0 and verified manual recovery without distributed PID/CID liveness. D6 first tests bounded/nullable diagnostic `original_key` against strict mode, Unicode, and shared prefixes while existing full-ID key derivation remains; require TEXT/DDL only on demonstrated need. No blanket RC refusal or speculative renewal machinery.
- **Dependencies:** none known; OWNER acceptance is part of delivery.
- **Notes/Risks:** OWNER accepted D1's six empirical validation targets on 2026-10-01; D2 feasibility and later D3/D5/D6 verification are open. D4 remains deferred ISSUE-012.

## E1-02. Focused trustworthy diagnostic baseline

- **Status:** completed. **Owner:** TEST. **Priority:** P0. **Batch:** B0.
- **Goal:** expose the current high-risk failure modes with reusable, bounded observations.
- **Scope:** existing test helpers and saved audit probe; independent connections/processes, controlled interleavings, READ/READ control, outer rollback, one fault/TTL control, and environment/SQL-mode evidence.
- **Out of Scope:** implementing every E3/E4 regression in advance, backend fixes, or changing audit artifacts.
- **DoR:** existing tests and probe are available; isolated test database setup belongs to execution.
- **DoD:** DoD-C; document command, revision, engine/isolation, expected RED/GREEN results, and limits; commit/merge focused tests and evidence.
- **AC:** hold ownership until both results are observed; exercise supported RR/RC with successful acquisition controls; bound and reap children; missing PCNTL/POSIX fails required checks; distinguish source inspection, fault injection, and real DB reproduction. Each later fixing task adds its own directed regression.
- **Dependencies:** none known; coordinate shared helper ownership in one writer sequence.
- **Notes/Risks:** saved audit probe mutates its dedicated table and injects faults; only run it on a disposable database. A sampled RR pass does not prove all schedules.

**Evidence — 2026-09-29:** the [B0 diagnostic record](evidence/E1-02.md) contains passing MySQL/MariaDB RR/RC baseline observations, controls, retained JSONL, and a successful existing-suite run (51 tests / 140 assertions / zero skips) on an identical temporary source copy with compatible dependencies. OWNER accepted the [ADR](adr/001-contract.md)'s D1 research approach and D2/D3/D5/D6 designs. B0's empirical D1 target validation matrix and OWNER acceptance, the final release support matrix, D2 feasibility, and later recovery/migration/API verification remain open. E1-01 and E1-02 remain in `review` until the B0 PR merges; E1-QA remains `waiting_dependency`.

**D1 evidence — 2026-10-01:** the [six-row proposal and result table](evidence/D1-matrix.md) links the exact [JSONL and provenance manifest](evidence/d1/manifest.md) from one serial disposable-database run at HEAD `6086416` plus identified uncommitted harness/doc changes. All six diagnostics exited 0 with expected legacy RC conflicts, sampled RR and READ/READ controls, and no recorded failures. This partial WordPress `wpdb` bootstrap is separate from the earlier existing-suite result and supplies no target-protocol or release support evidence. OWNER acceptance of the six-row validation targets and future CI shape, independent E1-QA, and review/merge remain open; task statuses above are unchanged.

**D1 acceptance — 2026-10-01:** OWNER accepted the [six exact RR/RC validation targets and future CI split](evidence/D1-matrix.md). The serial legacy evidence is retained in the [manifest](evidence/d1/manifest.md). At frozen revision `17c610a`, the existing CI had 12 green checks in runs `36873689653` and `36873685502`; this is prior CI evidence, not a new runtime check or target-protocol result. E1-01 and E1-02 remain `review` until B0 review/merge; E1-QA is ready for independent premerge review. Final release support and later feasibility/rehearsal gates remain open.

## E1-QA. Independent contract and evidence review

- **Status:** completed. **Owner:** QA. **Priority:** P0. **Batch:** B0.
- **Goal:** accept a usable contract and diagnostic baseline.
- **Scope:** ADR, tests, source mapping, environment and isolation evidence, exact revision, and evidence limits.
- **Out of Scope:** treating current backend as fixed or running production migration.
- **DoR:** E1-01 and E1-02 have stable, reviewed and tested premerge revisions, including the accepted B0 D1 target validation matrix; reviewer did not implement them.
- **DoD:** DoD-Q; include premerge readiness evidence in `docs/planning/qa/E1.md` in the B0 PR, then verify the required delivery after merge before the final epic gate closes. Missing merge remains a failed final delivery criterion.
- **AC:** decisions needed by E3–E5 are explicit; findings and controls are independently checked; legacy failures are labeled; no universal RR safety or target RC support is inferred from blanket refusal.
- **Dependencies:** E1-01, E1-02.
- **Notes/Risks:** missing mandatory decision or runtime evidence fails this gate.

**Final delivery — 2026-10-01:** [B0 PR #7](https://github.com/hokoo/wp-lock/pull/7) merged into `release/3.0` as `164390fee0e48d89d83845e52c967aba9b5fb62e`. The independent [E1-QA final gate](qa/E1.md#final-delivery--2026-10-01) is `pass` for E1-01, E1-02, and E1-QA after merge/tree and final-head CI verification. Earlier dated checkpoints above retain their then-current statuses. Backend fixes and later E3–E5 gates remain open.
