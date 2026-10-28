# P2.7 targeted re-review, round 2/2

Read REPORT.md, FINDINGS.md, review-request.json and targeted.diff in this directory.
Only the two confirmed Major findings of the actual round-1 RED review and their new
affected invariants are in scope. No new full-phase/P1 audit or additional agent launch.

Current native status: in_progress; result contains start/executor metadata only.
Writer: codex/gpt-6.1-sol/xhigh, session 01a18356-1ebf-7ef1-877f-01d40534fd40.
Reviewer must be actual codex/gpt-6.1-sol/high in an independent read-only session,
distinct from writer. One targeted re-review remains. Round 1 produced an agent_message,
not a receipt file; owning writer did not fabricate it. V99 remains pending.

Final base/head: 03c852c9ef08bb0294a4facb17394d5453d3fabd.
Final digest: sha256:3632920a06b541e0939e275f5d526a65f7da4ed39bc15bd0f54aa5ec0db62d74.
148 source paths, 14-source correction delta, full and targeted patches, exact archive.
Source check digest: f9488523f6b2e58eb4b47b9d76deb37dd91d770a4c060838519633621c4a8165.
All final V1–V8/V90/V91 exited 0 on these exact sources. Evidence schema/log hashes
are verified. V2 contains real INSERT UNIQUE wait + revoke + holder rollback for
module and target-less archive, plus stale generation / explicit same-key retry.
V3 contains distinct commandType/action existing/absent lookup and missing/stale fence.

Run verify-candidate.py, verify-evidence.py and verify-handoff.py from repository root
using the paths in REPORT.md. Do not run the old root candidate verifier as if its
source were current: that historical candidate remains immutable and superseded.
No dev/prod DB; any PG work uses unchanged owned P2.1 pg-run.py/PgTarget manifest guard.

After actual source-bound round-2 receipt, owning executor validates identity/route,
findings/open counts and exact candidate, then executes V99/finish with native receipt.
Do not close before receipt, do not start P2.8, do not reuse/rename the RED candidate.
Preserve 410 original foreign files, 535 protected current workspace files and all
104 historical review artifacts. No commit/push/tag/publish was performed.
