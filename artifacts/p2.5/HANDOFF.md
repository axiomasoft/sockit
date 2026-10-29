# PLAN1.P2.5

Receipt ledger реализован; обязательные V1/V2/V3/V90/V91 прошли. Детали и ограничения: REPORT.md. Native start/finish и будущий scoped commit относятся только к P2.5; другие items не запускались.

P2.7: использовать owner transaction с receipt claim перед surface/local fences, текущие guards обязательны и для replay/lookup. `ReceiptLedger`/`ReceiptSnapshot` не являются публичным outcome API или разрешением доступа. Ledger сейчас владеет полной tx с local write callback; guarded envelope должен добавить локальную проверку replay внутри этой же транзакции, сохранив rollback незавершённого claim. Не разрешать сеть/remote resolvers под lock. Minimal stable ledger не хранит content/problemDetail/Pending/Unknown. Production schema — P3; fixture SQL не публиковать как migration. Полный atomic outbox proof — P2.7.

Исходные 410 dirty/untracked файлов чужой работы сохранены; не включать их в commit.
