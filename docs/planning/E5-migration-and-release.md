# E5. Verified migration and the 3.0.0 candidate

**Target version:** 3.0.0 — planned; see [ROADMAP](../../ROADMAP.md#v300).
**Root model:** `gpt-6.1-sol` — recommendation accepted by OWNER on 2026-10-02.
**Selection rationale:** coordinate migration rehearsals, CI, documentation, and candidate acceptance against agreed upstream contracts; see [OpenAI model guidance](https://developers.openai.com/api/docs/models).
**Issues:** [ISSUE-009](../issues/README.md#issue-009), [ISSUE-010](../issues/README.md#issue-010); final acceptance gate for ISSUE-001–011.

**Outcome:** the next release has verified installation/migration, required CI, documentation, and a concrete package ready for a publication decision.
**Scope:** schema/protocol migration, rollback rehearsal, CI matrix, documentation, candidate freeze, and independent QA.
**Out of Scope:** publication, production rollout, consumer balance migration, and unapproved environment support.
**Success Criteria:** incompatible ownership protocols cannot coexist during switching; install/upgrade/rollback is verified; required CI passes without skips; E5-QA names the exact candidate.
**Dependencies:** accepted E1, E3, and E4; the 3.0.0 roadmap and migration details in the single E1-01 ADR.
**Risks/Open Questions:** TTL=0 owners do not drain after a fixed 30 seconds; rollback may require the same coordinated stop as upgrade; dbDelta is not a rollout protocol.
**Tasking Guidance:** use [common contracts and `$decompose-work`](README.md#common-task-contracts).

## E5-01. Implement and rehearse upgrade/rollback

- **Status:** review. **Owner:** DEV + DO. **Priority:** P1. **Batch:** B4.
- **Goal:** switch protocols without allowing independent incompatible owners.
- **Scope:** accepted migration/install hooks, complete schema verification, stopping new acquisitions, owner drain and TTL=0 resolution, clean install/upgrade/partial failure/rollback rehearsals on temporary databases.
- **Out of Scope:** production switching or deleting unknown active owners.
- **DoR:** E3/E4 are accepted; the E1-01 ADR defines D5 switching and reversibility.
- **DoD:** DoD-C plus DoD-D; save pre/post schema, versions, commands, failure/recovery results, and rollback evidence from isolated databases.
- **AC:** write schema version only after complete validation; failed DDL does not enable the target backend; old workers cannot continue an independent protocol after new acquisition is enabled; TTL=0 requires explicit resolution, not sleep(30); rollback is tested or its limits are explicitly accepted in D5.
- **Dependencies:** E3-QA, E4-QA, E1-01, E3-02; D5.
- **Notes/Risks:** disallowing rolling upgrade is acceptable if D5 chooses it and the procedure is enforceable; an unknown old owner blocks switching.

## E5-02. Required CI matrix and coverage

- **Status:** review. **Owner:** TEST. **Priority:** P1. **Batch:** B4.
- **Goal:** ownership regressions block delivery on supported configurations.
- **Scope:** workflow and test helpers/configuration; retain D1 PHP coverage, add MySQL/MariaDB × RR/RC checks, required process-control preflight, deadlines/evidence capture, and the current >=90% coverage gate.
- **Out of Scope:** an unjustified full PHP/WP/DB Cartesian product and independent load benchmarking.
- **DoR:** E1 defines the matrix and focused baseline, E3/E4 are accepted, and target-backend expectations are known.
- **DoD:** DoD-C; `composer test` passes on the accepted matrix; `composer test:coverage` and the existing >=90% check pass; preserve job links/logs and SHAs.
- **AC:** supported RC tests require real successful acquisition; no blanket continue-on-error or skip for required findings; missing PCNTL/POSIX fails; child/database waits are bounded and cleaned up; record actual runtime/engine/isolation; unsupported configurations test explicit refusal.
- **Dependencies:** E1-QA, E1-02, E3-QA, E4-QA.
- **Notes/Risks:** local equivalents are recorded separately when remote CI is unavailable; they do not satisfy a required remote gate silently.

## E5-03. User and operator documentation

- **Status:** todo. **Owner:** DO. **Priority:** P1. **Batch:** B4.
- **Goal:** give consumers accurate usage boundaries and migration instructions.
- **Scope:** README/CHANGELOG, examples, error/lost-ownership behavior, TTL=0, metadata cache, resource keys, environment matrix, upgrade/rollback, and custom-backend compatibility; explain transactional/idempotent accounting boundaries without implementing an application.
- **Out of Scope:** exactly-once external effects or designing a consumer payment system.
- **DoR:** E3/E4 are accepted; final API and D5 are known; E5-01 supplies the verified runbook before finalization.
- **DoD:** DoD-D; examples match code/tests; every promise maps to verification or an explicit limitation.
- **AC:** distinguish timeout and TTL; lock_exists does not authorize entry; leases do not imply fencing; explain stale get_user_meta cache and limits of float/user_meta examples; separate contention, DB failure, and lost ownership; state migration/rollback constraints before switching steps; resolve both source documentation TODOs.
- **Dependencies:** E3-QA, E4-QA, E5-01 for final instructions; D5.
- **Notes/Risks:** drafts can proceed with E5-01, but final delivery waits for its runbook. Renewal remains deferred as ISSUE-012.

## E5-04. Freeze the final release candidate

- **Status:** waiting_dependency. **Owner:** DO. **Priority:** P1. **Batch:** B5.
- **Goal:** give QA one concrete code/schema/package combination.
- **Scope:** consistent version metadata/changelog/schema, package-install smoke in an isolated consumer fixture, manifest of changes/artifacts/checks, exact SHA, required merges, and the accepted E1-01 ADR decisions.
- **Out of Scope:** publishing a release/tag, registry push, or deployment.
- **DoR:** E1, E3, and E4 are accepted and E5-01–03 delivered; renewal remains outside 3.0.0.
- **DoD:** DoD-C for metadata and DoD-D for the manifest; freeze the candidate for E5-QA; distinguish implementation and later QA-report commits.
- **AC:** the package installs the stated backend; metadata matches migration behavior; exact SHA/contents are known; required PRs are merged; residual risks and accepted exceptions are listed; changes after freeze trigger targeted re-verification.
- **Dependencies:** E1-QA, E3-QA, E4-QA, E5-01, E5-02, E5-03.
- **Notes/Risks:** a verified candidate is not a published version; publication requires separately granted scope.

## E5-QA. Independent final acceptance

- **Status:** waiting_dependency. **Owner:** QA. **Priority:** P0. **Batch:** B5.
- **Goal:** accept the actual deliverable, including migration and documentation.
- **Scope:** E5 AC, upstream epic state, exact candidate, package smoke, CI evidence, and directed repetition of high-risk migration/failure/rollback paths.
- **Out of Scope:** deployment or repeating sufficient checks without a concrete reason.
- **DoR:** E5-01–04 are delivered; package, isolated fixtures, and independent reviewer are available.
- **DoD:** DoD-Q; `docs/planning/qa/E5.md` and an OWNER handoff distinguishing commit, merge, candidate, and publication state.
- **AC:** no required criteria are failed or unverified; CI refers to the candidate or demonstrably unchanged verified parts; switching prevents incompatible owners; downgrade limits match the runbook; disclose review independence and every accepted exception.
- **Dependencies:** E5-01, E5-02, E5-03, E5-04.
- **Notes/Risks:** failures return to the owning epic; pass_with_notes requires all mandatory criteria to pass. Publication remains outside this execution boundary.
