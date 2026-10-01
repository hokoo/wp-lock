# E3. Correct READ/WRITE ownership

**Target version:** 3.0.0 — planned; see [ROADMAP](../../ROADMAP.md#v300).
**Issues:** [ISSUE-001](../issues/README.md#issue-001), [ISSUE-003](../issues/README.md#issue-003), [ISSUE-006](../issues/README.md#issue-006), [ISSUE-007](../issues/README.md#issue-007), [ISSUE-011](../issues/README.md#issue-011).

**Outcome:** shared READ/exclusive WRITE ownership succeeds safely under supported RR/RC independently of caller transactions.
**Scope:** only foundations required by E1-01 ADR, acquire/release, public `exists()` error results, resource and owner identity, numeric validation, and directed regressions.
**Out of Scope:** enabling incompatible protocols simultaneously, renewal, final E4 timing/recovery behavior, and application writes.
**Success Criteria:** conflicting valid owners cannot coexist; READ/READ succeeds; full resource ID and namespace remain stable; caller rollback cannot remove ownership; errors and uncertain commit are explicit; E3-QA passes.
**Dependencies:** E1-QA and accepted single ADR.
**Risks/Open Questions:** exact schema, connection and uncertainty protocol depend on ADR evidence; existing `insert_id` and wrapper state should survive where safe.
**Tasking Guidance:** use [common contracts and `$decompose-work`](README.md#common-task-contracts).

## E3-02. Protocol foundations and resource identity

- **Status:** completed. **Owner:** DEV. **Priority:** P0. **Batch:** B1.
- **Goal:** provide the minimum schema, connection, and identity support selected by the ADR.
- **Scope:** accepted protocol's schema/install verification, namespace capture, connection ownership if required, bounded/nullable diagnostic `original_key` evaluation, finite timeout validation, and directed fixtures.
- **Out of Scope:** speculative resource/owner tables, new token/adapter without demonstrated need, acquisition switching, or a diagnostic TEXT/DDL upgrade absent evidence.
- **DoR:** E1-QA passes; E1-01 defines D1/D2/D5/D6 and any required DDL/connection contract.
- **DoD:** DoD-C; targeted install/invalid-schema/DDL-failure, strict-mode/Unicode/shared-prefix, transaction and numeric-boundary checks; scoped review/merge.
- **AC:** full ID continues existing key derivation and cannot collide through diagnostic truncation; `original_key` limits do not silently reject a valid ID; NaN/INF are rejected while valid zero/positive timeout behavior remains. Any schema version changes only after complete verification. No backend operation commits/rolls back caller work; namespace survives blog/prefix changes; reconnect cannot silently imply retained ownership.
- **Dependencies:** E1-01, E1-QA; D1/D2/D5/D6.
- **Notes/Risks:** if full diagnostic text is demonstrated essential, return its DDL tradeoff to ADR review before implementing it. B1 local directed checks and the existing suite passed; [E3-02 evidence](evidence/E3-02.md) records the accepted controlled-session snapshot clarification, exact checks, source boundaries, and limits. [PR #8](https://github.com/hokoo/wp-lock/pull/8) delivered the reviewed implementation as `6bb9911`; final-head CI passed all 12 jobs. E3-03 is now in B2 local review.

## E3-03. Acquire and release with the accepted protocol

- **Status:** review. **Owner:** DEV. **Priority:** P0. **Batch:** B2.
- **Goal:** grant only compatible valid owners and release only the actual predecessor.
- **Scope:** serialization chosen by ADR, current-owner checks, commit confirmation, bounded error retries, uncertainty/loss handling, public `exists()` error-versus-absence result contract, release, and wrapper held lifecycle; directed regressions.
- **Out of Scope:** lease renewal, application fencing, E4's final deadline/recovery work, and live-site protocol switching.
- **DoR:** E1-QA passes; E3-02 is delivered; ADR specifies uncertainty/error outcomes.
- **DoD:** DoD-C; focused and real DB MySQL/MariaDB RR/RC acquisition/release matrix, caller-rollback checks, directed public `exists()` SELECT-failure regression, and failed public `release()` retry regression; reviewed/merged diff.
- **AC:** uncontended supported RR/RC acquisition succeeds; READ/READ coexists; WRITE/WRITE and READ/WRITE do not overlap valid ownership. Full resource/namespace identity is stable. Stale or foreign release preserves successor; caller rollback/deadlock cannot commit or remove backend ownership. Unknown commit/transport results do not create a second owner or return false success; public `exists()` distinguishes SELECT failure from absence, and DB errors do not masquerade as contention. Failed public `release()` retains retryable owner identity and wrapper held state. Preserve `insert_id` identity and wrapper held lifecycle where compatible.
- **Dependencies:** E3-02, E1-QA; D2/D6.
- **Notes/Risks:** [B2 local evidence](evidence/E3-03.md) records successful public RR/RC ownership and fault checks on product head `2f2ce3b`, then exact-source suite and >=90% local coverage on test head `b105904`. The six-row diagnostic has strict JSONL; final-head CI, independent E3-QA, and [PR #9](https://github.com/hokoo/wp-lock/pull/9) review/merge remain. E4 timing/recovery and E5 migration are separate gates.

## E3-QA. Independent ownership verification

- **Status:** waiting_dependency. **Owner:** QA. **Priority:** P0. **Batch:** B2.
- **Goal:** accept ownership and transaction isolation before E4 timing work.
- **Scope:** schema/identity, connection lifecycle, SQL serialization, RR/RC interleavings, rollback/reconnect/uncertain commit, public `exists()` errors, and failed release retry at exact revision.
- **Out of Scope:** E4 lifetime/recovery completion and release readiness.
- **DoR:** E3-02 and E3-03 delivered; supported matrix and chosen protocol are documented.
- **DoD:** DoD-Q; deliver `docs/planning/qa/E3.md` through review/merge.
- **AC:** independently verify successful supported acquisition and conflict exclusion with ownership retained until observation, shared readers, caller transaction isolation, public `exists()` error versus absence, failed public `release()` retryable owner/wrapper state, explicit DB uncertainty, stable identity, and predecessor-safe release. Refusing every RC request is a failure.
- **Dependencies:** E3-02, E3-03.
- **Notes/Risks:** E3 pass does not approve protocol switching or make an unexpired lease indefinite.
