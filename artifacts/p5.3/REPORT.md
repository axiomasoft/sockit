# PLAN1 P5.3

Session `01a18701-578c-77c1-8e9b-4dbc00f1e7f5`; actual Codex `gpt-6.1-sol/xhigh`, `danger-full-access`, no delegation. Runtime provenance: `runtime-proof.json`. Base HEAD `21390900fb7ca2599af8d56ce8dde4b645c3584c`.

Implemented typed join/leave/approve/invite commands and the complete four-policy state machine. Self actions derive the member from the trusted handle; approve/invite recipients remain payload intent. Pending/invited are durable Confirmed operations, confer no active membership, and obsolete requests require self cancellation. New-key no-change rejects; same-key replay uses the existing current guards.

Activation requires explicit host `MembershipConsent`: subject assessment outside transactions, bounded opaque generation, local recheck after group/member waits and kernel authority recheck. Deny/unknown/error/missing/stale consent cannot activate. Leave does not consult consent. Group then member locks, member SQL CAS, aggregate revision/membership generation, receipt and versioned bounded-reference outbox events share the existing owner envelope. Kernel signatures, P5.1 schema and P5.2 invariants are preserved.

| Check | Exit | Evidence |
|---|---:|---|
| V1 | 0 | V1.json / V1.log; 3 tests, 94 assertions; full 4 × 5 × 4 matrix |
| V2 | 0 | V2.json / V2.log; 7 PG tests, 136 assertions |
| V3 | 0 | V3.json / V3.log; 17 PG tests, 209 assertions |
| V89 | 0 | V89.json / V89.log; 56 tests, 864 assertions |
| V90 | 0 | V90.json / V90.log; no PHPStan errors or suppressions |
| V91 | 0 | V91.json / V91.log; Pint passed |

All six declared commands ran literally; evidence follows `check-evidence/v1`. `candidate.dirty_digest` binds the scoped source map in `source-fingerprint.json` (337 files; artifacts and foreign changes excluded), unchanged after validation. No skipped/incomplete/warning/risky PHPUnit tests. V90 retains the environment's PHP startup warning about unavailable turbo-extension dynamic loading; analysis itself reports no errors.

PostgreSQL ran only through `scripts/testing/pg-run.py`: invocation-owned container/private volume/loopback endpoint, `_test` DB, manifest, label and database-marker validation precede test DDL. V3 proves two actual PostgreSQL group-lock waits before consent generation change/revocation; both reject without activation. Injected outbox failure rolls back member/revision/generation and cannot leave a successful receipt. Supplemental P5.1/P5.2 schema/group API regression: exit 0, 8 tests / 1657 assertions (`V92.log`).

Initial V3 failed on a test expectation counting the existing surface event; corrected to group events. Initial analysis found test-only type errors, corrected without baseline changes. Final checks above supersede those attempts; their logs are retained. Evidence-schema validation used the installed jsonschema resolver after the newer optional `referencing` package was found absent.

Scope ends at P5.3. Host owns identity, consent decisions, revocation fence and current read admission; a remote unprovable authority cannot activate. Installed consumer/current group audience/content integration remain with their later owners. Independent review is requested and will be launched by the coordinator in a fresh read-only session; no review receipt is claimed. Commit and exact finish result are recorded after committing in untracked `finalization.json`.
