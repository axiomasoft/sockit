# PLAN1.P2.4 handoff

P2.4 реализован и проверен owning session `01a182f8-ea14-73d1-b32e-5bb6d4f39f54`.
Отчёт, ограничения и actual evidence: `REPORT.md`, V1/V2/V3/V90/V91.
Другие items не запускались. Перед будущими DB mutations использовать P2.1 private harness.

P2.7 должен вызывать internal `Operations/Internal/Storage/SurfaceInstances` после receipt claim,
до own target locks, внутри единственного OwnerConnection transaction. Authorize/admission/containment
проверяются отдельно снаружи, local policy generations повторно под lock; repo этого не подменяет.
`lock()` даёт snapshot для protected read/replay (проверить lifecycle.canRead); `writable(expected)`
запрещает archived/deleted запись. `transition` поддерживает lifecycle CAS, `revise` — local policy edit.
`create` только incarnation=1; `recreate` explicit next incarnation + deleted CAS + higher source generation.
Surface source generations — monotonic Revision, не opaque TargetResolver token.
Tombstones не удаляются. Public mutation bypass не предоставлен. Production DDL — P3.

Размещение repository в Operations сохраняет A3: cross-module Internal импорт запрещён.
410 чужих dirty files сохранены. Scoped commit ограничен source-paths.json и artifacts/p2.4.
