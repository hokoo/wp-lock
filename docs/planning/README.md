# Roadmap execution: epics, tasks, and decisions

The root [ROADMAP.md](../../ROADMAP.md) owns versions and release scope. This directory contains execution contracts, not another release schedule. The [issue register](../issues/README.md) records findings; the [audit](../review-2026-09-29/assessment.md) provides research evidence.

There are **four mandatory epics and 15 tasks** for one planned 3.0.0 release, including four independent QA tasks. B0 and E1-QA are complete; B1 / E3-02 is in review after local verification; B2–B5 have not started. OWNER authorized B1 execution on 2026-10-01. Renewal is deferred as ISSUE-012 without a version or task breakdown.

## Navigation

| Epic | Tasks | Batches |
| --- | --- | --- |
| [E1. Contract and verification](E1-contract-and-tests.md) | E1-01–02, E1-QA | B0 |
| [E3. Correct ownership](E3-acquisition.md) | E3-02–03, E3-QA | B1–B2 |
| [E4. Timing and recovery](E4-leases-and-recovery.md) | E4-01–03, E4-QA | B3 |
| [E5. Migration and release](E5-migration-and-release.md) | E5-01–04, E5-QA | B4–B5 |

The former E3-01 architecture task is folded into the single E1-01 ADR. [Batches](batches.md) define order and gates.

## Decisions

E1-01 prepares **one** [ADR](adr/001-contract.md) covering supported contracts, ownership protocol, time, errors, recovery, and migration. OWNER accepted D1's evidence-led research approach and D2/D3/D5/D6 designs on 2026-09-29, then the [six-row D1 validation target and future CI plan](evidence/D1-matrix.md) on 2026-10-01. The final release support matrix, implementation feasibility, and required rehearsals remain open; decision acceptance is not target-protocol verification.

| ID | State | Decision and required evidence |
| --- | --- | --- |
| D1 | research and six validation targets accepted; release support pending | [Six exact configurations](evidence/D1-matrix.md) are mandatory E3/E4 validation targets and E5 candidate-gate targets at RR and RC. Future regular PR CI uses four LTS rows; the two newest tracks run periodically and at the candidate gate. Current PHP 7.4–8.3 CI and PHP >=7.4 constraint remain. WordPress 6.2 is a backward anchor, not a universal floor. E3–E5 evidence and a release decision govern final support claims. |
| D2 | architecture accepted; B1 feasibility locally verified, merge pending | Serialize ownership changes on a permanent unique resource row using an independent primary connection, retain the auto-increment owner ID, capture the namespace, and use a per-attempt token for uncertain INSERT/COMMIT recovery. [B1 evidence](evidence/E3-02.md) records six passing RR/RC foundation rows with the accepted controlled MariaDB snapshot setting; public ownership and fault lifecycle remain E3-03. |
| D3 | design accepted; rehearsal pending | TTL=0 has no automatic expiry; a crash may leave an owner requiring the ADR's manual recovery after stopping participants. Prefer a finite TTL longer than the bounded protected operation with generous delay margin; release explicitly. Do not infer death from unreliable PID/CID. Rehearse the accepted procedure before release. |
| D4 | deferred | ISSUE-012 renewal has no release target or active task. Revisit only on a confirmed consumer requirement and separate authorization. |
| D5 | design accepted; rehearsal pending | One 3.0.0 release. Use the ADR's stop, drain, verify, switch, and restart barrier in both directions; resolve TTL=0 and uncertain owners before switching. Rehearse installation, failures, and rollback before release. |
| D6 | design accepted; regressions pending | Preserve full string resource identity. Store optional `original_key` only when representable and within its 50-character column; otherwise store `NULL` without truncation. Preserve `false` for ordinary contention/deadline and proven absence; distinguish DB errors, uncertainty, and confirmed loss with exceptions. Reject NaN/INF blocking timeouts while preserving finite zero/positive behavior. |

Preserve existing `insert_id` owner identity, wrapper held lifecycle, and tests where compatible. Authorized E3 work must verify D2 feasibility before downstream acceptance. [B0's final E1-QA gate passed](qa/E1.md#final-delivery--2026-10-01); the final release support matrix awaits E3–E5 evidence. OWNER separately authorized B1 execution on 2026-10-01.

## Common task contracts

**Statuses:** `todo` means DoR is satisfied; `needs_design` means a material decision is missing; `waiting_dependency` means scope is known but a named upstream artifact or gate is pending; `review` means work exists but verification/delivery remains; `completed` means AC/DoD and delivery are met. Actual task states are recorded in each epic.

**Roles:** DO/root owns delivery, sequencing, decisions, and integration; DEV implements backend; TEST implements tests/CI; QA independently reviews each epic; OWNER accepts material decisions. A fresh bounded `worker` handles each implementation or substantial document assignment. `test_monitor` runs long, database-backed, coverage, and broad checks after the writer stops. Human assignees are not selected.

**DoD-C (code):** AC and applicable directed checks pass; affected documentation and diff are reviewed; the batch PR is reviewed and merged into `release/3.0`. E1 diagnostic RED cases are recorded as expected failures until their fixing tasks land; the existing suite and GREEN controls must pass. Until required merge, status remains `review`. No implementation authorization implies publication.

**DoD-D (document/ADR):** the named artifact records sources, decision and rationale, or verified procedure; required OWNER decisions are recorded; review and batch PR merge into `release/3.0` deliver it. A decision accepted in the ADR does not substitute for empirical verification.

**DoD-Q (epic QA):** an independent reviewer receives the epic scope, criteria, task contracts, exact revision, and actual results; inspects evidence and reports `pass`, `pass_with_notes`, or `fail`. A premerge review may establish technical readiness on a frozen batch branch, with its report included in that batch PR. The final epic gate checks delivery after merge; missing mandatory evidence or required delivery is `fail`. Root records the unchanged final verdict, provenance, and merge evidence in `docs/planning/qa/<EPIC>.md` as concise delivery bookkeeping. If independent QA is unavailable, disclose that and record explicit DO fallback; material exceptions require OWNER risk acceptance.

**Tasking Guidance for every epic:** refine with `$decompose-work`; keep Status, Goal, Scope, Out of Scope, DoR, DoD, AC, Dependencies, and Notes/Risks. Set actual readiness. Export each epic with its target version, issues, dependencies, and this guidance.

**Evidence:** record command, revision, PHP/WP/database/engine/isolation, exit code, and limits. The existing 51 tests / 140 assertions are a baseline, not fix evidence. Reuse test helpers and the saved audit probe for its dedicated diagnostic scenario. `composer test`, `composer test:coverage`, >=90% line coverage, and independently connected concurrency checks remain. Missing PCNTL/POSIX or a database is not a passing concurrency check. E1 supplies a focused baseline; each fixing task owns its directed regression.

**Issue closure:** satisfy every closure criterion in the [issue register](../issues/README.md) on the candidate. A sampled RR pass is bounded evidence, not universal safety. Refusing every RC acquisition does not establish supported RC.

## Integration and scope control

At each batch boundary DO records actual statuses, changed artifacts, checks, risks, and next ready work. Each epic has independent QA. Global Gitflow uses `release/3.0` as the integration target, one `batch/bN` branch per batch, and one MR/PR per batch. Create each next batch branch from the updated `release/3.0`. Freeze and review/test the branch before merge; tasks stay in `review` until the batch PR merges. A premerge QA readiness review does not close the final epic gate, which verifies delivery using the merge URL/SHA after merge; root records that evidence as concise bookkeeping without requiring another implementation PR. One writer is active in the shared checkout; verification and review use a stable boundary. Composer, PHPUnit, coverage, Docker, and database checks run serially. Read-only investigation may proceed independently.

Agent execution follows [AGENTS.md](../../AGENTS.md) and [Codex configuration](../../.codex/config.toml). The configuration permits three open subagent threads, excluding root, but does not require them. Named roles pin their models and effort; root model choice stays with the user. Configuring roles and planning tasks do not authorize roadmap execution. Re-estimate after the ADR; no task count or extra worker overhead is itself an outcome.

**Planning checkpoint — 2026-09-29:** Ponytail full revision and independent read-only recheck completed; verdict: ready for contract work. The recheck's batch-label mismatch and overlapping public `exists()`/`release()` ownership were corrected. Local document checks passed for links/anchors, task contracts, all 15 batch assignments, acyclic dependencies, issue mappings, and agent TOML. Delivery is a verified local planning patch; ADR decisions remain open as listed above, and implementation, runtime verification, commit, and merge are not complete.
