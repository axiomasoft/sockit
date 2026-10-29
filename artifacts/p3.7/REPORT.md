P3.7 — doctor schema readiness

Executor: codex/gpt-6.1-sol/high. Session: 01a1851f-068a-7441-9069-f676d44347d6.
Base HEAD: 07e9ee44a8c1feff4f2cbd6ae2cd4cead7a8363b.

Doctor проверяет выбранную панель через shared compiler, frozen history/physical identity и ModelBindings. Отчёт содержит generated/applied ancestry, installed/generated/desired digests, версии компонентов, preserved_owned для отключённых модулей, actionable problems и outbox delivery reasons. Missing/pending/drift/ambiguity возвращают nonzero; неизвестная/отсутствующая панель — exit 2.

Каталог проверяется по обязательным типам, nullability/default/collation, identity/key policy, PK/index/FK/check definitions и ownership receipt. Совместимое nullable host-поле допускается. Неизвестные дополнительные ограничения, unique indexes, RLS/triggers и несовместимые изменения не признаются ready. Строгий rollback fingerprint не ослаблен.

Диагностика, включая resolver-конструкторы и model checks, выполняется в READ ONLY REPEATABLE READ; транзакция и локальный search_path откатываются. Tests наблюдают SQL и сравнивают digests файлов, каталога и данных. Fresh boot и настоящий config:cache не открывают соединений даже при отсутствующей/неполной схеме.

Проверки выполнены буквально на PHP 8.4.27 через временный PATH:

| Check | Exit | Result |
| --- | --- | --- |
| V1 | 0 | 36 real PostgreSQL tests, 406 assertions |
| V2 | 0 | 4 tests, 23 assertions |
| V90 | 0 | PHPStan: No errors; без suppressions/baseline |
| V91 | 0 | Pint passed |

Дополнительно: console/arch 50 tests, 475 assertions; legacy PostgreSQL console/relay regression — exit 0. Его прежняя зелёная assertion для P2 fixture без installed history заменена fail-closed expectation; relay assertions сохранены.

V*.json соответствуют check-evidence schema; отдельные stdout/stderr, команды/cwd/exit и SHA-256 logs/source fingerprints сохранены. Общий source digest: da2322d3237e354af02c2dc5fdccadfd43668c8204d3c371c36361fe891eb968. Ранние failures сохранены отдельно и не выданы за pass. Первичный PHPStan startup warning Herd устранён использованием установленного system PHP; окончательный V90 без предупреждений.

До каждой тестовой мутации PgTarget доказал container label, loopback endpoint, точное *_test имя и run marker. Собственные контейнеры/volumes удалены; proof — resource-cleanup.json и owned-volume-cleanup.json. Wrapper исправлен на удаление anonymous volume вместе с exact owned container. Чужие ресурсы и artifacts не удалялись. Resolver availability использует явные test doubles; schema/migrations/catalog/identity проверены на реальном PostgreSQL, без social e2e claims. Review mode none.
