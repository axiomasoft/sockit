# P2.7: awaiting independent source-bound review

Owning implementation/checks complete. Native status remains **in_progress**; result contains only native start/executor metadata.
Read REPORT.md, review-request.json, candidate.json, evidence-verified.json.
Writer: codex/gpt-6.1-sol/xhigh, session 01a18356-1ebf-7ef1-877f-01d40534fd40.
No agents/reviewer were launched. No V99 receipt or native finish exists.

Fresh reviewer must use codex/gpt-6.1-sol/high, a distinct session, read-only, round 1/2.
Review tx/privacy changed supporting invariants P2.2–P2.6 through the P2.7 candidate; do not
re-execute accepted P1 reviews, implement other items or modify source. Validate native
review-receipt-schema.json and actual final source identity before producing the receipt.

Run from repository root:

```
python3 artifacts/p2.7/verify-candidate.py
python3 -W ignore::DeprecationWarning artifacts/p2.7/verify-evidence.py
```

Source base/head: 03c852c9ef08bb0294a4facb17394d5453d3fabd.
Digest: sha256:1d2b93821807b52daf9cb42340219e996dd6d7d9540110ad1c76e5cef8842040.
144 paths plus exact source archive and diff. Artifact source scopes exclude artifact trees;
their evidence hashes are in artifact-sha256.json. Historical empty P2.4 path is explicitly
recorded; its actual storage implementation is included. Sources are uncommitted by design.

All actual final checks V1–V8/V90/V91 exited 0. V99 pending. Native start/route already accepted.
After real receipt, owning executor validates reviewer != writer, subject/base/head/digest/paths,
rounds<=2, unresolved Major/Blocker=0; responds to findings and finishes with native receipt/evidence.
Any source correction needs a fresh candidate and affected checks; do not reuse a stale receipt.
No dev/prod DB: all PG work must use unchanged private pg-run.py/PgTarget fail-closed harness.
Preserve the original 410 foreign files and do not start P2.8. No push/tag/publish.
