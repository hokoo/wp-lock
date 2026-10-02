# Identified issues

Source: the [2026-09-29 audit](../review-2026-09-29/assessment.md), commit `26d3a07`, WP Lock 2.0.0. This is the project's local issue register; no GitHub issues have been created. The single [ROADMAP](../../ROADMAP.md) assigns release targets.

`open` means an unresolved finding; `resolved (candidate)` means its closure criteria passed on the named unpublished candidate; `deferred` means a deliberately postponed capability. Execution readiness belongs to [task contracts](../planning/README.md). Evidence distinguishes real database reproductions, injected failures, source inspection, and feature proposals. ISSUE-001–011 are resolved on frozen candidate `84a528d`, delivered in merge `8653f46`, after fresh independent [E5-QA](../planning/qa/E5.md#final-repaired-delivery--2026-10-02) returned `pass_with_notes`; these findings still apply to the published 2.0 baseline.

| ID | Finding | Type / priority | State | Target |
| --- | --- | --- | --- | --- |
| [ISSUE-001](#issue-001) | Conflicting owners under READ COMMITTED | bug / P0 | resolved (candidate) | 3.0.0 |
| [ISSUE-002](#issue-002) | A failed liveness check can delete a live lock | bug / P0 | resolved (candidate) | 3.0.0 |
| [ISSUE-003](#issue-003) | An outer ROLLBACK removes the lock while PHP retains held state | bug / P0 | resolved (candidate) | 3.0.0 |
| [ISSUE-004](#issue-004) | Successful acquisition can return an expired lease | bug / P1 | resolved (candidate) | 3.0.0 |
| [ISSUE-005](#issue-005) | SQL latency and retries bypass the expected wait boundary | bug / P1 | resolved (candidate) | 3.0.0 |
| [ISSUE-006](#issue-006) | exists returns false on a database error | bug / P1 | resolved (candidate) | 3.0.0 |
| [ISSUE-007](#issue-007) | Resource IDs longer than 50 conflict with schema/README | bug / P1 | resolved (candidate) | 3.0.0 |
| [ISSUE-008](#issue-008) | PID/CID does not reliably identify TTL=0 ownership | bug / P1 | resolved (candidate) | 3.0.0 |
| [ISSUE-009](#issue-009) | CI misses the identified interleavings and isolation modes | testing / P1 | resolved (candidate) | 3.0.0 |
| [ISSUE-010](#issue-010) | Documentation does not fully describe guarantee boundaries | documentation / P1 | resolved (candidate) | 3.0.0 |
| [ISSUE-011](#issue-011) | Timeout validation accepts NaN/INF | bug / P2 | resolved (candidate) | 3.0.0 |
| [ISSUE-012](#issue-012) | Lease renewal for long-running operations | enhancement / P2 | deferred | none assigned |

Shared evidence: [probe](../review-2026-09-29/probe.php), [MySQL results](../review-2026-09-29/mysql-results.jsonl), [MariaDB results](../review-2026-09-29/maria-results.jsonl), and [51 existing tests / 140 assertions](../review-2026-09-29/phpunit.log). A passing existing suite does not close the findings.

Closure evidence: [E3 acquisition](../planning/evidence/E3-03.md) and [E3-QA](../planning/qa/E3.md) cover ISSUE-001/003/006/007/011; [E4-QA](../planning/qa/E4.md) and timing/recovery evidence cover ISSUE-002/004/005/008 and downstream ISSUE-006. [E5-01](../planning/evidence/E5-01.md) verifies switching and TTL=0 drain refusal; [E5-02](../planning/evidence/E5-02.md) and the [final candidate manifest](../planning/evidence/E5-04.md) verify ISSUE-009/010 and all final-candidate criteria with six actual RR/RC rows, no required skips, coverage >=90%, installed package parity and corrected consumer guidance. Final independent E5-QA checked these mappings. No technical criterion was waived; operational barriers and lease limits remain documented.

<a id="issue-001"></a>
## ISSUE-001. Simultaneous conflicting acquisition

- **Evidence:** the actual backend and independent connections allowed two WRITE owners under READ COMMITTED in 197/200 MySQL rounds and 162/200 MariaDB rounds. READ/WRITE also overlapped. The same runs did not observe violations under RR. Absence of UNIQUE alone is not proof of the cause.
- **Impact:** two handlers can enter the same resource's critical section.
- **Tasks:** E1-01–02, E3-02–03, E3-QA; final matrix E5-02.
- **Closure:** real successful acquisition under supported RR/RC, no conflicting valid owners, permitted shared READ, reproducible regressions, and an explained serialization protocol. Rejecting RC in the legacy backend does not close the target release's RC requirement.

<a id="issue-002"></a>
## ISSUE-002. A liveness failure deletes live ownership

- **Evidence:** injected SQL failure with an unavailable local PID check and a still-live CID; cleanup allowed a second acquire to return true.
- **Impact:** unknown state is treated as a missing owner.
- **Tasks:** E1-02, E3-03, E3-QA, E4-03, E4-QA.
- **Closure:** errors and incomplete visibility preserve ownership; exception behavior is explicit; cleanup/release preserves the successor; both databases are tested.

<a id="issue-003"></a>
## ISSUE-003. Ownership depends on the caller's transaction

- **Evidence:** START TRANSACTION → acquire=true → ROLLBACK → another acquire=true, while the first PHP object retains held state. The backend uses global wpdb.
- **Impact:** callers can believe an unprotected critical section remains locked; retries/deadlocks can affect business transactions.
- **Tasks:** E1-01–02, E3-02–03, E3-QA.
- **Closure:** backend work does not commit/roll back caller transactions; acquisition/loss follows the agreed contract through rollback, reconnect, and uncertain commit; unsupported modes are rejected explicitly.

<a id="issue-004"></a>
## ISSUE-004. Incorrect lease grant boundary

- **Evidence:** a BEFORE INSERT delay of 1.2 seconds with TTL=1 produced acquire=true followed immediately by exists=false. Takeover after expiry and the previous object's retained held state were also reproduced. PHP host clocks determine expiry in the code; multi-host skew was not separately exercised.
- **Impact:** successful return may not represent a usable lease; host time affects lifetime.
- **Tasks:** E1-02, E4-01, E4-QA, E5-03.
- **Closure:** slow SQL, expiry/takeover, and old-owner release are tested; the time contract does not promise safety across arbitrary pauses after return; lost ownership and lease limits are explicit. Renewal is not required to fix this issue.

<a id="issue-005"></a>
## ISSUE-005. Deadline does not cover all acquisition paths

- **Evidence:** blocking_timeout=0.01 with a 1.2-second SQL delay returned success after approximately 1.2 seconds. Source inspection shows the DB-error branch does not check the shared deadline.
- **Impact:** callers cannot rely on the stated waiting boundary; late success needs explicit ownership handling.
- **Tasks:** E4-02, E4-QA, E5-03.
- **Closure:** monotonic budgeting and late-result/retry behavior are tested; refusal does not conceal confirmed ownership; synchronous I/O and driver timeout limits are documented without an unsupported hard wall-clock SLA.

<a id="issue-006"></a>
## ISSUE-006. An exists failure looks like lock absence

- **Evidence:** making the lock table temporarily unavailable produced exists=false with a nonempty wpdb last_error.
- **Impact:** callers and diagnostics receive incorrect information about database state.
- **Tasks:** E1-01, E3-03, E3-QA, E4-03, E4-QA, E5-03.
- **Closure:** SELECT failures have an explicit D6 API result; absence/contention is distinguishable from DB failure; lock_exists is not presented as permission to acquire.

<a id="issue-007"></a>
## ISSUE-007. Resource ID length conflicts with its contract

- **Evidence:** a 51-character ID under STRICT_ALL_TABLES produces Data too long for original_key=varchar(50), while README describes an arbitrary string.
- **Impact:** a publicly valid argument depends on sql_mode and schema details.
- **Tasks:** E1-01, E3-02, E3-QA, E5-01.
- **Closure:** accepted and tested full-ID/diagnostic-storage contract under strict mode, Unicode, and shared prefixes. First evaluate a bounded/nullable diagnostic `original_key` while preserving full-ID key derivation; require TEXT or DDL only if full diagnostic retention proves necessary. If schema changes, verify upgrade and failure handling. Diagnostic truncation cannot change resource identity.

<a id="issue-008"></a>
## ISSUE-008. Unreliable identity for TTL=0 owners

- **Evidence:** a stale-identity model with a live local PID and missing CID excludes the row from ghosts. PID lacks host/namespace identity. Limited processlist visibility is assessed from source/documentation, not a complete multi-host experiment.
- **Impact:** an uncertain owner can retain a resource indefinitely or be considered missing without sufficient evidence.
- **Tasks:** E1-01, E4-03, E4-QA.
- **Closure:** explicit TTL=0 policy, reliable ownership handling, and tested recovery instructions. Deliberate manual recovery is acceptable under D3; unconditional automatic availability is not required.

<a id="issue-009"></a>
## ISSUE-009. Missing regression coverage

- **Evidence:** the existing 51 tests / 140 assertions pass alongside reproduced defects. The workflow uses MariaDB 10.11.10 without a separate MySQL/isolation matrix.
- **Impact:** green CI does not catch the identified ownership failures.
- **Tasks:** E1-02, E1-QA, E3-03, E4-01–03, E5-02, E5-QA.
- **Closure:** required concurrency/fault tests on the accepted matrix; absent PCNTL/POSIX is a failure rather than a skip; scheduling limitations are stated; coverage >=90% remains a separate gate.

<a id="issue-010"></a>
## ISSUE-010. Incomplete documentation of guarantees

- **Evidence:** the float/user_meta README example does not address stale cache or operation idempotency. TTL/timeout wording needs refinement based on experiments. Two source @todo notes concern backend atomicity and lock risks.
- **Impact:** consumers may mistake API examples for sufficient accounting protocols or treat held state as indefinite ownership.
- **Tasks:** E1-01, E4-03, E5-03–04, E5-QA.
- **Closure:** verified examples and explicit lock scope, lease/lost ownership, errors, cache behavior, and migration instructions; both documentation TODOs are resolved by actual text or removed after the text is moved. Application balance implementation is excluded.

<a id="issue-011"></a>
## ISSUE-011. Non-finite timeout

- **Evidence:** source inspection of the WP_Lock_Backend_DB constructor shows only a float comparison against zero; NaN/INF are not rejected. The audit did not run a separate runtime reproduction of this case.
- **Impact:** the configured wait budget need not be meaningful or finite.
- **Tasks:** E1-01, E3-02, E3-QA.
- **Closure:** reproduction and boundary tests, explicit finite-value validation, and preserved zero/positive timeout contracts.

<a id="issue-012"></a>
## ISSUE-012. Renewal of an active lease

- **Evidence:** the API has no renewal method; a concrete consumer requirement is not yet confirmed. This is a feature proposal, not a reproduced defect.
- **Impact:** a long-running consumer may need explicit renewal checked against current ownership.
- **Tasks:** none; deferred without version, epic, or task breakdown.
- **Closure:** revisit only after a confirmed consumer requirement and separate authorization. Any future scope must address compatibility, ownership loss, expiry/takeover/errors, and independent QA; the proposal may instead be declined with recorded rationale.
