PLAN1.P4.7. Session `01a1866a-7654-7791-a5d9-a1cd15bdd1ee`; actual runtime `codex/gpt-6.1-sol/high`, `danger-full-access` подтверждён в runtime.json. Base: `34a47ab763a6a074197bece222fd55b7174693b3`.

Добавлены Posts::feed, FeedQuery/FeedPage и внутренний encrypted/MAC cursor. Порядок — immutable first published_at + id DESC, strict keyset без OFFSET. Контекст включает installation/panel/full surface incarnation/viewer generation/filters/order. Refill ограничен scan budget; cursor идёт по последнему проверенному кандидату, включая пустую страницу. Перед выходом повторно проверяются current audience/action/host, post/privacy/safety/consent и surface generations; media разрешается вне транзакций. Нестабильность закрывает выход. Финальная проверка всей страницы удаляет ранее собранный отозванный пост и заполняет место. Реакционные counts в DTO отсутствуют; P4.3 guards сохранены.

Scalar PostStore теперь передаёт current archive/archiveReaders в AudienceSubject, что согласует feed/scalar для архивных читателей. В дополнительном прогоне исправлено только устаревшее ожидание ModuleSelectionTest относительно уже принятого alias `sockit.post`; файл зарегистрирован в task_files через planner push. Registry/kernel/routes не менялись.

| Check | Exit | Результат |
|---|---:|---|
| V1 | 0 | 17 tests / 25 assertions: tamper, context, expiry, rotation, malformed positions |
| V2 | 0 | 14 / 145: ties, refill/budget, continuation, четыре independent-process PG barriers |
| V3 | 0 | 31 / 234: lifecycle, revoke/shrink/class replacement, hydration races, media, scalar=batch |
| V89 | 0 | 53 / 594: architecture |
| V90 | 0 | PHPStan max, без новых suppressions/baseline |
| V91 | 0 | Pint |
| V92 (доп.) | 0 | 401 / 8665: social и P2 guards/atomicity/receipts/concurrency/tombstones |
| V93 (доп.) | 0 | 417 / 5276: Unit/Feature и настоящие consumer checks |

V1/V2/V3/V89/V90/V91 выполнены буквально. Каждый PG-прогон — через pg-run.py: owned container label/private endpoint/exact *_test/run marker/PIDs проверяются до тестовых мутаций; B/decoy sentinels сохранены. Skipped/incomplete/warning/risky не засчитывались. V90 сохраняет прежнее PHP startup warning об optional turbo extension; анализ завершился без ошибок. Ранние неуспешные попытки и причины повторов сохранены, включая отказ source guard при параллельной корректировке теста.

Итоговые evidence по schema check-evidence, literal commands, SHA-256 logs и единый source digest проверяются verify-evidence.py; результат — evidence-verification.json. Digest: `030a42a565ff0ade9f89c7029134f61ac214ef6443e183c20e4f2831e4d53f23`.

Настройка через scalar module settings `sockit.posts`: feed_keys (base64 32-byte server secrets), feed_key (active ID), feed_ttl (1..86400), feed_chunk (1..100), feed_budget (1..10000). Без серверного ключа feed отклонён; секретов в fixtures/logs нет, тесты генерируют случайные ключи. Допустим author wire filter, limit 1..100. Cursor — позиция, не разрешение. Консистентность sampled current read; атомарный remote revoke и исторический snapshot не обещаются.

После scoped dry-run создаётся executor commit; independent reviewer/receipt не запускался и не создавался. Требуется отдельный coordinator review requested. После commit исходники и этот отчёт остаются неизменны; точный результат последующего planner finish будет в новом untracked finalization.json.
