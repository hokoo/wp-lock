# Manual recovery of a TTL=0 owner

TTL=0 has no automatic expiry. Use this procedure only for an owner whose operation has ended and whose ordinary `release()` or unresolved-attempt reconciliation cannot complete. It is a maintenance procedure, not permission to delete production rows by publishing this document. Prefer a finite TTL sized above the bounded protected operation and release explicitly in normal work.

1. Engage an externally enforced admissions barrier for **every** old and new acquisition participant in the affected WordPress site/blog namespace. Stop workers, queues, cron, and request paths that can acquire this lock; prevent restart. Confirm no participant can still enter or write under the old lock. Keep the barrier engaged through the fresh postcondition read. A database marker alone cannot stop code that ignores it.
2. Capture the physical primary database, exact WordPress table prefix, full resource ID, `md5()` key of that full ID, and owner table/resource table names. Identify the specific owner `id`, 32-character `attempt_token`, lock level, and expiry with a successful **primary** read. Check all rows for this resource; shared READ may have multiple owners. The `original_key` column is diagnostic and may be `NULL`; a prefix switch can make the current WordPress namespace differ from the owner's captured namespace. Retain the original `WP_Lock` object and its error details when available.
3. Confirm the owning operation has ended through application/job records and positively confirmed process or service termination for **all** relevant participants. A PID may be reused or belong to another host/container. CID/processlist visibility may be incomplete. A missing PID, missing processlist entry, failed SELECT, or unknown job outcome is insufficient; keep the barrier and investigate. An uncertain INSERT/COMMIT also requires confirming the old transaction has ended before treating a fresh token lookup as final.
4. On a fresh writable primary connection, start a transaction and take the same permanent resource-row lock as the library. Re-read every owner for the captured key with `FOR UPDATE`. Match the target by **both** numeric `id` and token and confirm it is the owner proven stale. If the row is absent, changed, or any other owner is unresolved, stop and reconcile under the barrier. Delete only that exact row and require exactly one affected row. On any failed read, zero-row DELETE, SQL error, or uncertain transaction outcome, leave the barrier engaged. Do not infer deletion from an error or blindly repeat a possibly committed operation.
5. After a confirmed COMMIT, use a **fresh primary read** to verify the target identity is absent and account for every remaining owner for that key. Record namespace, database, resource, owner identity, reason, termination evidence, operator, time, SQL result, and postcondition. Lift the barrier only when all unresolved owners are reconciled and every participant is ready to resume on the intended protocol.

The following is a **parameterized outline**, not a command to run on production. Bind `:key` to the captured 32-character full-ID MD5, `:owner_id` and `:token` to the verified row, and substitute table names only after verifying the captured physical namespace. Use one connection for the transaction; `SELECT ... FOR UPDATE` alone is insufficient without the resource-row lock. A DELETE result of zero is an error for the selected target.

```sql
START TRANSACTION;
INSERT INTO `<prefix>lock_resource` (lock_key) VALUES (:key)
  ON DUPLICATE KEY UPDATE lock_key = lock_key;
SELECT lock_key FROM `<prefix>lock_resource` WHERE lock_key = :key FOR UPDATE;
SELECT id, lock_key, level, expire, attempt_token
  FROM `<prefix>lock` WHERE lock_key = :key FOR UPDATE;
DELETE FROM `<prefix>lock`
  WHERE lock_key = :key AND id = :owner_id AND attempt_token = :token;
-- Require exactly one affected row, then COMMIT.
COMMIT;
-- On a fresh primary connection, inspect every owner for :key again.
SELECT id, lock_key, level, expire, attempt_token
  FROM `<prefix>lock` WHERE lock_key = :key;
```

For a legacy row with `attempt_token IS NULL`, only use its numeric `id` and `attempt_token IS NULL` predicate after **all** old participants are stopped and the exact row is rechecked under the resource lock. Do not use PID/CID as replacement identity. Finite expired rows follow primary database time and the library's serialized exact-owner cleanup; PID/CID does not determine their expiry. If a DELETE or COMMIT result is uncertain, retain the barrier, resolve the transaction on a fresh primary connection, and repeat the full identity/postcondition inspection before any resumption.

The disposable E4-03 diagnostic starts a TTL=0 owner in an independent child, engages a fixture admissions barrier after acquisition, and positively stops and joins that child. It injects SELECT/DELETE/COMMIT failures while the barrier stays engaged. After a confirmed deletion, it also fails the fresh primary postcondition read, retains the barrier, and reconciles with another fresh read without repeating DELETE. A separate stopped-participant fixture refuses recovery while two shared legacy owners remain, then accounts for each exact owner ID and NULL token under the resource lock and fresh primary reads. Diagnostic JSONL records the fixture namespace/resource/owner, reason, operator, time, SQL outcome, and postcondition for each completed attempt. This is a partial protocol rehearsal on disposable tables: stopping real workers, queues, cron, request paths, and all old/new participants remains site-specific operational work. E5 installation and protocol-switch rehearsals are separate. Matrix evidence belongs in [E4-03](planning/evidence/E4-03.md); no production record is touched.
