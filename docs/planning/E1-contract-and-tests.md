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

- **Status:** review. **Owner:** DO + OWNER. **Priority:** P0. **Batch:** B0.
- **Goal:** settle the 3.0.0 contract and the minimum protocol needed to satisfy it.
- **Scope:** `docs/planning/adr/001-contract.md`; D1/D2/D3/D5/D6; supported matrix, READ/WRITE, resource/owner identity, namespace, caller transactions, serialization, commit uncertainty, TTL/deadlines, DB errors, cleanup, lost ownership, migration, and compatibility.
- **Out of Scope:** implementing a candidate design, renewal, automatic external-write fencing, or selecting an architecture before evidence and OWNER acceptance.
- **DoR:** README contract, current code/tests, and audit evidence are available.
- **DoD:** DoD-D; ADR compares viable alternatives, records decisions and tests/feasibility evidence, and is reviewed and merged before dependent implementation.
- **AC:** require real READ/WRITE acquisition on supported RR/RC in the target protocol; explain empty-resource and conflict serialization, stale RR snapshots, caller rollback/deadlock/reconnect, known and uncertain commits, release/cleanup, namespace changes, time/error/recovery results, safe switching and rollback. Retain existing `insert_id` identity, wrapper held lifecycle, and tests where compatible; justify any new token, connection, table, or abstraction by chosen protocol or uncertainty. D3 uses conservative TTL=0 and verified manual recovery without distributed PID/CID liveness. D6 first tests bounded/nullable diagnostic `original_key` against strict mode, Unicode, and shared prefixes while existing full-ID key derivation remains; require TEXT/DDL only on demonstrated need. No blanket RC refusal or speculative renewal machinery.
- **Dependencies:** none known; OWNER acceptance is part of delivery.
- **Notes/Risks:** D1's target validation matrix remains an empirical B0/OWNER gate; D2 feasibility and later D3/D5/D6 verification are open. D4 remains deferred ISSUE-012.

## E1-02. Focused trustworthy diagnostic baseline

- **Status:** review. **Owner:** TEST. **Priority:** P0. **Batch:** B0.
- **Goal:** expose the current high-risk failure modes with reusable, bounded observations.
- **Scope:** existing test helpers and saved audit probe; independent connections/processes, controlled interleavings, READ/READ control, outer rollback, one fault/TTL control, and environment/SQL-mode evidence.
- **Out of Scope:** implementing every E3/E4 regression in advance, backend fixes, or changing audit artifacts.
- **DoR:** existing tests and probe are available; isolated test database setup belongs to execution.
- **DoD:** DoD-C; document command, revision, engine/isolation, expected RED/GREEN results, and limits; commit/merge focused tests and evidence.
- **AC:** hold ownership until both results are observed; exercise supported RR/RC with successful acquisition controls; bound and reap children; missing PCNTL/POSIX fails required checks; distinguish source inspection, fault injection, and real DB reproduction. Each later fixing task adds its own directed regression.
- **Dependencies:** none known; coordinate shared helper ownership in one writer sequence.
- **Notes/Risks:** saved audit probe mutates its dedicated table and injects faults; only run it on a disposable database. A sampled RR pass does not prove all schedules.

**Evidence — 2026-09-29:** the [B0 diagnostic record](evidence/E1-02.md) contains passing MySQL/MariaDB RR/RC baseline observations, controls, retained JSONL, and a successful existing-suite run (51 tests / 140 assertions / zero skips) on an identical temporary source copy with compatible dependencies. OWNER accepted the [ADR](adr/001-contract.md)'s D1 research approach and D2/D3/D5/D6 designs. B0's empirical D1 target validation matrix and OWNER acceptance, the final release support matrix, D2 feasibility, and later recovery/migration/API verification remain open. E1-01 and E1-02 remain in `review` until the B0 PR merges; E1-QA remains `waiting_dependency`.

## E1-QA. Independent contract and evidence review

- **Status:** waiting_dependency. **Owner:** QA. **Priority:** P0. **Batch:** B0.
- **Goal:** accept a usable contract and diagnostic baseline.
- **Scope:** ADR, tests, source mapping, environment and isolation evidence, exact revision, and evidence limits.
- **Out of Scope:** treating current backend as fixed or running production migration.
- **DoR:** E1-01 and E1-02 have stable, reviewed and tested premerge revisions, including the accepted B0 D1 target validation matrix; reviewer did not implement them.
- **DoD:** DoD-Q; include premerge readiness evidence in `docs/planning/qa/E1.md` in the B0 PR, then verify the required delivery after merge before the final epic gate closes. Missing merge remains a failed final delivery criterion.
- **AC:** decisions needed by E3–E5 are explicit; findings and controls are independently checked; legacy failures are labeled; no universal RR safety or target RC support is inferred from blanket refusal.
- **Dependencies:** E1-01, E1-02.
- **Notes/Risks:** missing mandatory decision or runtime evidence fails this gate.
