# E7. Static analysis and coding standards for 3.0.0

**Target version:** 3.0.0 — planned; see [ROADMAP](../../ROADMAP.md#v300).
**Root model:** gpt-6.1-sol.
**Issues:** none; this is an added release quality gate, not closure of an audit finding.

**Outcome:** first-party library PHP is checked for WordPress coding style and statically detectable defects on every pull request and push before the 3.0.0 release.
**Scope:** PHP_CodeSniffer with WordPress Coding Standards, PHPStan with only the WordPress definitions needed by this library, focused cleanup, Composer commands, one fast CI job, contributor guidance, and refreshed candidate evidence.
**Out of Scope:** new lock behavior or public API, mass formatting of tests and diagnostics, a third compatibility tool, a new database matrix, publication, and production deployment.
**Success Criteria:** `lib/` and `plugin.php` pass both mandatory analyzers; CI fails on a new finding; the existing PHP 7.4–8.3, database, and coverage gates still pass on the final candidate; independent E7-QA accepts the exact revised candidate.
**Dependencies:** completed E5 delivery on `release/3.0`; recorded root model and separate execution authorization before implementation.
**Risks/Open Questions:** existing style findings may require focused cleanup; WordPress's dynamic APIs may need type definitions or narrow, explained exceptions; a source edit after the E5 freeze requires renewed verification and candidate evidence.
**Tasking Guidance:** use [common contracts and `$decompose-work`](README.md#common-task-contracts); the task statuses below reflect current readiness, not implementation authorization.

## E7-01. Establish the analyzer baseline and checks

- **Status:** review. **Owner:** TEST. **Priority:** P1. **Batch:** B6.
- **Goal:** make style and static defects in the library visible without hiding new findings.
- **Scope:** probe PHP_CodeSniffer/WPCS and PHPStan against `lib/` and `plugin.php`; choose the lowest useful PHPStan level from measured output; add minimal project rules and Composer `lint`/`analyse` commands; fix actionable findings in scope. Add WordPress definitions only where analysis requires them.
- **Out of Scope:** changing lock contracts, broad test formatting, speculative analyzer plugins, and blanket exclusions.
- **DoR:** E5 is delivered; the epic's root model is selected; execution is authorized.
- **DoD:** DoD-C; save the initial findings and reasons for the selected rules/level; run both commands successfully on the scoped files. Any remaining exception is narrow and explained; a baseline is used only for individually reviewed legacy findings that cannot reasonably be resolved here.
- **AC:** both tools cover all PHP files in `lib/` and `plugin.php`; PHPStan checks the PHP 7.4 minimum; neither command reports errors on the delivered revision; changing a checked file to introduce a representative violation makes the relevant command fail.
- **Dependencies:** E5-QA, selected root model, execution authorization.
- **Notes/Risks:** analyzer success is not evidence of lock concurrency correctness; preserve existing runtime checks. Composer dev dependencies must remain compatible with the supported PHP matrix.

## E7-02. Enforce CI and refresh the 3.0.0 candidate

- **Status:** review. **Owner:** TEST + DO. **Priority:** P1. **Batch:** B6.
- **Goal:** enforce the new checks and identify the exact candidate to which all release evidence applies.
- **Scope:** one fast CI job for `composer lint` and `composer analyse` on push and pull request; concise README guidance; final SHA/package manifest and affected-gate evidence after the E7-01 changes are reviewed.
- **Out of Scope:** replacing the existing PHPUnit, coverage, native database, or PHP-version jobs; publishing or deploying the package.
- **DoR:** the E7-01 writer has stopped, its diff is reviewed, and the analyzer configuration is stable; E7-01 remains in review until the B6 merge.
- **DoD:** DoD-C plus DoD-D; record the exact final revision, CI run links/results, package contents, and whether prior E5 evidence remains applicable. Refresh required runtime and coverage evidence for any changed source, including the agreed six candidate rows if library source changes; keep the batch in review until its PR merges into `release/3.0`.
- **AC:** both analyzer commands are required CI steps with no `continue-on-error`; a failing analyzer makes the job fail; existing required CI gates pass on the revised candidate without skips; the candidate manifest distinguishes prior E5 evidence from checks run on the revised SHA.
- **Dependencies:** E7-01 and existing E5 candidate artifacts.
- **Notes/Risks:** a documentation or analyzer-only change still changes the candidate SHA; do not describe E5-QA's earlier reviewed SHA as the revised candidate.

## E7-QA. Independently accept the revised candidate

- **Status:** waiting_dependency. **Owner:** QA. **Priority:** P0. **Batch:** B6.
- **Goal:** verify that the new gate works and the revised 3.0.0 candidate retains required release evidence.
- **Scope:** E7 task criteria, scoped findings/exceptions, required CI results, source-change impact, package manifest, and exact merged revision.
- **Out of Scope:** publication, a new compatibility matrix, or repeating unaffected E5 rehearsals without a concrete risk.
- **DoR:** E7-01–02 are delivered; the candidate and independent reviewer are available.
- **DoD:** DoD-Q; persist the unchanged verdict and provenance in `docs/planning/qa/E7.md` and update the release handoff to name the revised candidate.
- **AC:** the reviewer confirms both analyzers are required and effective; the existing runtime and coverage evidence applies to the revised candidate or was rerun where source changes require it; no unmet 3.0.0 release criterion is represented as passed.
- **Dependencies:** E7-01, E7-02, E5-QA.
- **Notes/Risks:** E5-QA remains valid for its historical SHA; E7-QA is the final gate for the revised candidate.
