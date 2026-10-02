# E4. Timing, errors, and recovery

**Target version:** 3.0.0 — planned; see [ROADMAP](../../ROADMAP.md#v300).
**Root model:** `gpt-6-astra` — recommendation accepted by OWNER on 2026-10-02.
**Selection rationale:** integrate lease/deadline, uncertain ownership and commit, cleanup, and recovery guarantees; see [OpenAI model guidance](https://developers.openai.com/api/docs/models).
**Issues:** [ISSUE-002](../issues/README.md#issue-002), [ISSUE-004](../issues/README.md#issue-004), [ISSUE-005](../issues/README.md#issue-005), [ISSUE-006](../issues/README.md#issue-006), [ISSUE-008](../issues/README.md#issue-008).

**Outcome:** acquisition and inspection have defined lease, deadline, error, and recovery behavior.
**Scope:** lease timing, bounded waiting, DB error/uncertain-state handling, TTL=0 manual recovery, cleanup, and directed regressions.
**Out of Scope:** stopping arbitrary PHP after expiry, application-write fencing, automatic distributed liveness, and renewal.
**Success Criteria:** known-expired ownership is not reported as a valid grant; no deadline/error path hides confirmed ownership; uncertain state is preserved or explicitly recovered; successors survive cleanup/release; E4-QA passes.
**Dependencies:** E3-QA and accepted E1-01 time/error/recovery contract.
**Risks/Open Questions:** arbitrary pauses after the last check remain possible; synchronous driver I/O limits a hard wall-clock bound.
**Tasking Guidance:** use [common contracts and `$decompose-work`](README.md#common-task-contracts).

## E4-01. Lease time and lost ownership

- **Status:** completed. **Owner:** DEV. **Priority:** P1. **Batch:** B3.
- **Goal:** grant a usable lease under the accepted time source and report observed loss.
- **Scope:** authoritative time per ADR, start after required serialization, remaining-TTL check before success, expiry/takeover, PHP held state, and directed slow-SQL/clock tests.
- **Out of Scope:** renewal or protecting arbitrary application writes after expiry.
- **DoR:** E3-QA passes; ADR defines clock, return boundary, and loss result.
- **DoD:** DoD-C; directed delay/expiry/skew/successor tests and documentation pass; reviewed/merged change.
- **AC:** SQL delay greater than TTL cannot produce unconditional known-expired success; PHP host clocks cannot independently decide DB expiry; account for statement-start time in a wait; old release preserves successor. Describe the guarantee at return and its limit after arbitrary pause.
- **Dependencies:** E3-QA, E1-01.
- **Notes/Risks:** a heartbeat cannot promise safety across arbitrary process pauses; ISSUE-012 is deferred.

## E4-02. Finite deadline, retry, and late outcomes

- **Status:** completed. **Owner:** DEV. **Priority:** P1. **Batch:** B3.
- **Goal:** make every retry path honor the defined waiting budget and ownership result.
- **Scope:** monotonic elapsed budget, checks around SQL and retries, finite inputs, contention/error distinction, late success and cleanup, and directed faults.
- **Out of Scope:** a hard wall-clock SLA unsupported by the DB driver.
- **DoR:** E3-QA passes; ADR defines late-result and uncertain cleanup outcomes.
- **DoD:** DoD-C; slow SQL, zero budget, alternating errors/contention, late commit, and cleanup tests; reviewed/merged change.
- **AC:** no error branch bypasses the shared budget; known late success is released or reported according to ADR; refusal never conceals confirmed ownership; failed cleanup and uncertain commit are explicit; synchronous I/O limitations are documented.
- **Dependencies:** E3-QA, E1-01.
- **Notes/Risks:** coordinate acquisition-file edits sequentially with E4-01.

## E4-03. Conservative cleanup and verified manual recovery

- **Status:** review. **Owner:** DEV + DO. **Priority:** P1. **Batch:** B3.
- **Goal:** preserve uncertain ownership and give operators a verified way to resolve TTL=0 owners.
- **Scope:** consume E3-03's public `exists()` error result and retryable failed `release()` state; handle ghost/liveness failures and cleanup DELETE uncertainty conservatively, preserve serialized successor safety, and verify the TTL=0 recovery runbook with directed fault/procedure tests.
- **Out of Scope:** distributed PID/CID liveness system, automatic reclamation on unknown identity, or production record deletion.
- **DoR:** E3-QA passes; ADR defines D3 and D6 error/recovery results.
- **DoD:** DoD-C plus DoD-D for runbook; fault and manual-recovery rehearsal on isolated databases; reviewed/merged artifacts.
- **AC:** cleanup and recovery treat E3-03's SELECT-failure result as uncertainty, not absence; failed/incomplete processlist or local PID visibility cannot prove death; PID reuse is not owner identity; cleanup and recovery preserve E3-03's retryable owner identity and wrapper state after failed release; cleanup DELETE failure or uncertainty is explicit, and cleanup/release cannot delete a successor. Manual recovery requires stopping acquisitions, confirming owner termination, resolving ownership, and verifying outcome. Finite expired leases follow their defined contract.
- **Dependencies:** E3-QA, E1-01; D3/D6.
- **Notes/Risks:** a TTL=0 owner may remain until an operator acts; runbook publication does not authorize production deletion.

## E4-QA. Independent timing and failure review

- **Status:** waiting_dependency. **Owner:** QA. **Priority:** P0. **Batch:** B3.
- **Goal:** verify lease, deadline, error, and recovery promises.
- **Scope:** directed E4 scenarios, real DB/fault evidence, documentation, and exact revision.
- **Out of Scope:** application balance invariants and renewal.
- **DoR:** E4-01–03 delivered; required scenarios and time boundaries documented.
- **DoD:** DoD-Q; deliver `docs/planning/qa/E4.md` through review/merge.
- **AC:** independently verify slow SQL/expiry/takeover, bounded retry semantics, error versus absence, explicit uncertainty, TTL=0 manual recovery, and successor safety. Missing required fault evidence is fail, not `pass_with_notes`.
- **Dependencies:** E4-01, E4-02, E4-03.
- **Notes/Risks:** E4 cannot promise prevention of stale application writes without application cooperation.
