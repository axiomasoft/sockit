PLAN1.P4.7 — исправление F1 Major после RED, переданного владельцем: reviewer `01a1867f-7c35-7453-bec0-22cd74585e2e`, codex/gpt-6.1-sol/high, read-only. Owning session прежняя: `01a1866a-7654-7791-a5d9-a1cd15bdd1ee`, codex/gpt-6.1-sol/high, danger-full-access. Base: `4422fe0a9eb6ed0c44e917685da400d8d4505b07`; повторного planner start нет.

После всех policy/media callbacks PostFeed одним owner SQL statement сверяет всю выдачу и surface instance в общем statement snapshot. FeedCandidate сохраняет состояние, по которому разрешена гидратация. Любое несовпадение post row либо surface lifecycle/revision/source/privacy generation исключает кандидата. Затем bounded refill продолжает сканирование ниже последнего проверенного кандидата. Существующие batch/scalar/current host/media guards сохранены; после итоговой сверки callbacks перед выходом нет. Новых транзакций, locks, kernel/routes или sibling imports нет. Remote authority остаётся sampled-read boundary.

Регрессия использует действительный cursor следующей страницы: первый media callback B проходит, второй — в финальной hydrate B — скрывает уже проверенный A и повышает privacy_revision. Scalar read A после feed запрещён. Дополнительно проверяется изменение surface privacy generation; scan budgets 2/3 доказывают короткую страницу с continuation либо refill без пропусков следующей страницы. До исправления V3 дал exit 1: четыре новых failures, прежние 31 tests passed. RED evidence сохранено как V3-attempt-20261029T072247Z.*.

| Проверка исправления | Exit | Результат |
|---|---:|---|
| V1 | 0 | 17 tests / 25 assertions |
| V2 | 0 | 14 / 145 |
| V3 | 0 | 35 / 324 |
| V89 | 0 | 53 / 596 |
| V90 | 0 | PHPStan max, без новых suppressions/baseline |
| V91 | 0 | Pint |

Все шесть команд выполнены буквально. PostgreSQL — только pg-run.py; owned container/endpoint/exact *_test/run marker/PIDs доказаны до мутаций; B/decoy sentinels сохранены. Missing/skip/incomplete/warning/risky/timeout не засчитываются pass. V90 сохраняет прежнее startup warning optional turbo extension, анализ без ошибок.

Актуальные V1/V2/V3/V89/V90/V91.json и logs прошли schema/log SHA-256/literal command/source verification: evidence-verification.json. Source digest: `30537b06eed643791e15b9f44dc65dccc22200ea53697dcdfe4c7e976d9aa632`. V92/V93, старые attempts и finalization/finish logs — история прежнего кандидата, не evidence исправления F1.

После scoped dry-run создаётся executor commit; после него исходники и отчёт неизменны. Для исправленного кандидата нужен новый independent review координатора. Reviewer/receipt не создавался; finish и blocked при исправлении не вызываются.
