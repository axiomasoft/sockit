# P3.8 — standalone consumer

Consumer устанавливает source copy без symlink и dev dependencies, проверяет source fingerprint и обнаружение пакета. Два generated provider и их concrete models используют отдельные связи owner_a/owner_b и decoy default. Config cache и новый bootstrap не выполняют SQL.

На PostgreSQL после проверки PgTarget применены generated snapshots: CRUD и разные host keys, восстановление модели в новом процессе и A/B/A с ошибкой B, P2 admission/mutation/replay и атомарный rollback с outbox. Additive schema component v2 создаёт модели и проверяет связи; старые migration/model/provider hashes сохраняются. Disable сохраняет таблицы и pending events с явным quarantine. После изменения config и версии компонента изолированный rollback Article сохраняет host и Product, Product продолжает read/write.

V1, V2, V90 и V91: exit 0. Команды, cwd, вывод, hash и source fingerprint — V*.json и V*.log; evidence schema проверена. Все PostgreSQL контейнеры и их volumes удалены pg-run wrapper. Тестовые модули не являются production social features; P4 social e2e остаётся открытым.
