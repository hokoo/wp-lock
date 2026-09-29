# Roadmap execution: epics, tasks, and decisions

The root [ROADMAP.md](../../ROADMAP.md) owns versions and release scope. This directory contains execution contracts, not another release schedule. The [issue register](../issues/README.md) records findings; the [audit](../review-2026-09-29/assessment.md) provides research evidence.

There are **four mandatory epics and 15 tasks** for one planned 3.0.0 release, including four independent QA tasks. B0 contract and diagnostic work is in progress; backend implementation has not started. Renewal is deferred as ISSUE-012 without a version or task breakdown.

## Navigation

| Epic | Tasks | Batches |
| --- | --- | --- |
| [E1. Contract and verification](E1-contract-and-tests.md) | E1-01–02, E1-QA | B0 |
| [E3. Correct ownership](E3-acquisition.md) | E3-02–03, E3-QA | B1–B2 |
| [E4. Timing and recovery](E4-leases-and-recovery.md) | E4-01–03, E4-QA | B3 |
| [E5. Migration and release](E5-migration-and-release.md) | E5-01–04, E5-QA | B4–B5 |

The former E3-01 architecture task is folded into the single E1-01 ADR. [Batches](batches.md) define order and gates.

## Decisions

E1-01 prepares **one** [ADR](adr/001-contract.md) covering supported contracts, ownership protocol, time, errors, recovery, and migration. OWNER accepted D1's evidence-led compatibility research approach and D2's proposed architecture on 2026-09-29; the final support matrix and D2 implementation feasibility remain unverified. D3's conservative TTL=0/manual recovery direction is agreed; its detailed protocol and procedure remain under discussion. D5 switching details and D6's public error contract remain pending.

| ID | State | Decision and required evidence |
| --- | --- | --- |
| D1 | research approach accepted; final matrix pending | Start with the newest released stable PHP, WordPress, and MySQL, retain MariaDB, compare maintained LTS where relevant, and expand backward by evidence. Keep PHP >=7.4 as the existing constraint. B0 selects candidates and reusable evidence; E3–E5 verify final-protocol support under RR and RC before release claims. |
| D2 | architecture accepted; feasibility pending | Serialize ownership changes on a permanent unique resource row using an independent primary connection, retain the auto-increment owner ID, capture the namespace, and use a per-attempt token for uncertain INSERT/COMMIT recovery. Directed MySQL/MariaDB and fault tests remain required. |
| D3 | direction agreed; details pending | Handle TTL=0 conservatively with verified manual recovery after stopping participants. Do not infer death from unreliable PID/CID or build a distributed liveness system. Define exact evidence and steps in the ADR. |
| D4 | deferred | ISSUE-012 renewal has no release target or active task. Revisit only on a confirmed consumer requirement and separate authorization. |
| D5 | release scope agreed; switching details pending | One 3.0.0 release. Define installation, safe incompatible-protocol switch, TTL=0 resolution, failure handling, and tested rollback in the ADR. |
| D6 | pending | Preserve the full string resource ID for existing key derivation. First evaluate a bounded/nullable diagnostic `original_key` with strict-mode, Unicode, and shared-prefix tests. Require TEXT or DDL only if full diagnostic retention proves necessary. Define contention, DB error, absence, and lost/uncertain ownership results; reject NaN/INF without changing valid timeout behavior. |

Preserve existing `insert_id` owner identity, wrapper held lifecycle, and tests where compatible. D2 accepts the resource row, independent connection, and attempt token in principle; authorized E3 work must verify their feasibility before downstream acceptance, while the remaining decisions and E1-QA gate precede implementation. This decision does not authorize B1 execution.

## Common task contracts

**Statuses:** `todo` means DoR is satisfied; `needs_design` means a material decision is missing; `waiting_dependency` means scope is known but a named upstream artifact or gate is pending; `review` means work exists but verification/delivery remains; `completed` means AC/DoD and delivery are met. Actual task states are recorded in each epic.

**Roles:** DO/root owns delivery, sequencing, decisions, and integration; DEV implements backend; TEST implements tests/CI; QA independently reviews each epic; OWNER accepts material decisions. A fresh bounded `worker` handles each implementation or substantial document assignment. `test_monitor` runs long, database-backed, coverage, and broad checks after the writer stops. Human assignees are not selected.

**DoD-C (code):** AC and applicable directed checks pass; affected documentation and diff are reviewed; a scoped commit/PR is reviewed and merged into the selected integration branch. E1 diagnostic RED cases are recorded as expected failures until their fixing tasks land; the existing suite and GREEN controls must pass. Until required merge, status remains `review`. No implementation authorization implies publication.

**DoD-D (document/ADR):** the named artifact records sources, decision and rationale, or verified procedure; required OWNER decisions are recorded; review and commit/merge deliver it. This local planning patch does not complete future tasks.

**DoD-Q (epic QA):** an independent reviewer receives the epic scope, criteria, task contracts, exact revision, and actual results; inspects evidence and reports `pass`, `pass_with_notes`, or `fail`. Missing mandatory evidence is `fail`. Root records the unchanged verdict and provenance in `docs/planning/qa/<EPIC>.md` and delivers the report by commit/merge. If independent QA is unavailable, disclose that and record explicit DO fallback; material exceptions require OWNER risk acceptance.

**Tasking Guidance for every epic:** refine with `$decompose-work`; keep Status, Goal, Scope, Out of Scope, DoR, DoD, AC, Dependencies, and Notes/Risks. Set actual readiness. Export each epic with its target version, issues, dependencies, and this guidance.

**Evidence:** record command, revision, PHP/WP/database/engine/isolation, exit code, and limits. The existing 51 tests / 140 assertions are a baseline, not fix evidence. Reuse test helpers and the saved audit probe for its dedicated diagnostic scenario. `composer test`, `composer test:coverage`, >=90% line coverage, and independently connected concurrency checks remain. Missing PCNTL/POSIX or a database is not a passing concurrency check. E1 supplies a focused baseline; each fixing task owns its directed regression.

**Issue closure:** satisfy every closure criterion in the [issue register](../issues/README.md) on the candidate. A sampled RR pass is bounded evidence, not universal safety. Refusing every RC acquisition does not establish supported RC.

## Integration and scope control

At each batch boundary DO records actual statuses, changed artifacts, checks, risks, and next ready work. Each epic has independent QA. One writer is active in the shared checkout; verification and review use a stable boundary. Composer, PHPUnit, coverage, Docker, and database checks run serially. Read-only investigation may proceed independently.

Agent execution follows [AGENTS.md](../../AGENTS.md) and [Codex configuration](../../.codex/config.toml). The configuration permits three open subagent threads, excluding root, but does not require them. Named roles pin their models and effort; root model choice stays with the user. Configuring roles and planning tasks do not authorize roadmap execution. Re-estimate after the ADR; no task count or extra worker overhead is itself an outcome.

**Planning checkpoint — 2026-09-29:** Ponytail full revision and independent read-only recheck completed; verdict: ready for contract work. The recheck's batch-label mismatch and overlapping public `exists()`/`release()` ownership were corrected. Local document checks passed for links/anchors, task contracts, all 15 batch assignments, acyclic dependencies, issue mappings, and agent TOML. Delivery is a verified local planning patch; ADR decisions remain open as listed above, and implementation, runtime verification, commit, and merge are not complete.
