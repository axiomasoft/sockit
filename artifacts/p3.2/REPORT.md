# PLAN1 P3.2 — результат исполнения

Session: `01a18497-9a83-7a11-9caa-75ad0ab41d27`. Actual runtime из `turn_context`: `codex/gpt-6.1-sol/high`, sandbox `danger-full-access`, approval `never` (`runtime.json`). Base HEAD: `28c923c5f45d8b3ba1b475923698c2d889193859`. `planner start` вызван один раз и завершился exit 0. Один owning executor; `review.mode=none`.

Реализован общий physical storage preflight в `Schema/Internal`. Identity включает driver, доверенный stable cluster ID, нормализованный canonical TCP/Unix endpoint, database, явную resolved schema и итоговое имя таблицы. Connection name не участвует в доказательстве физической независимости. Одобренные host aliases объединяются; противоречивые cluster/endpoint assignments, неизвестные proxy/socket endpoints, multi-host/read/write/DSN routing и неразрешённая schema отклоняются. Никаких DNS guesses или credentials в descriptor/digest/diagnostics. Исключения PDO не прокидываются как previous.

Pure `declared()` и `compile()` не открывают PDO. Только явно вызванный `discover()`/`inspect()` выполняет read-only SQL: сверяет фактическую database и server endpoint с deployment descriptor, разрешает `current_schema()` и проверяет существование явной schema. Snapshot сохраняет schema и финальные physical maps; дальнейшая смена `search_path` его не перенаправляет. `assertDisjoint()` проверяет весь предлагаемый ownership set; `assertVacant()` читает только каталоги и блокирует уже занятые relation names до DDL. Это initial-install guard; обновления applied snapshots и production DDL не реализуются этим пунктом.

Финальные tables/columns/schema подчиняются ASCII policy `^[a-z_][a-z0-9_]*$`, ≤63 bytes. Driver prefix применяется один раз к полному declared table (panel prefix + module suffix либо custom override). Никакой sanitization/truncation. PK/index/FK/check names строятся из полной physical identity, panel и purpose через SHA-256; проверяются коллизии constraints и общей namespace PostgreSQL relations. FK snapshot содержит frozen target schema/table.

Добавлен config-cache-safe `sockit.storage.deployments`: map connection → `{cluster, endpoint:{host,port}, aliases:[{host,port}]}`. Validation выполняется без SQL. Tooling SPI `SocKit\Schema\StoragePreflight` связывает Configuration и существующий `sockit:doctor` с владельцем Schema, без foreign Internal imports и без ORM/Internal в публичных сигнатурах. Doctor использует тот же resolver и проверку final identifiers; старый coarse `deployment_identity` заменён structured descriptor. Автоматического discovery при boot/request нет.

Task files P3.2 адресно расширены на tooling SPI, существующий Doctor и его owned PG console fixture. Архитектурные gates, routes, execution-policy и другие items не менялись. Production modules, генераторы и миграции последующих пунктов не исполнялись.

## Проверки

Обязательные V1/V2/V90/V91 выполнены буквально из входного context pack. PHP: установленный `/usr/bin/php8.4` 8.4.27 через временный PATH shim. PostgreSQL: исключительно `python3 scripts/testing/pg-run.py`; каждый запуск создаёт и удаляет собственный PostgreSQL 16 container. До fixture SQL mutation PgTarget доказывает container label, exact loopback endpoint, run marker и точный owned `*_test` target; ambient dev/prod DB не используется.

| Check | Фактический результат | Exit |
|---|---|---:|
| V1 | Unit physical identity/identifiers: 41 passed, 138 assertions | 0 |
| V2 | PostgreSQL: 4 passed, 27 assertions; два connection names с разными PDO backends; alias collision до DDL; catalog identity; frozen schema; exact table/index/FK names; 63 accepted / 64 rejected; neighbor и decoy unchanged | 0 |
| V90 | `composer analyse -- --memory-limit=512M`: No errors, без baseline/suppressions | 0 |
| V91 | `composer lint`: passed | 0 |
| V92, дополнительный | PG doctor/relay regression: 1 passed, 20 assertions | 0 |
| V93, дополнительный | Полный default suite через PG wrapper: 373 passed, 4039 assertions; arch negative controls, config-cache/fresh boot и standalone installed consumer | 0 |

Skipped/incomplete/warning/risky/timeout не засчитывались как pass. Отдельные proven-server и Unix socket equivalence cases — explicit descriptor unit fixtures, не заявление о multi-server/socket e2e. Реальный PG e2e использует один owned TCP server, две DB и независимые PDO backends. DDL в V2 — минимальная test-only fixture, не production schema P3.3 и не social e2e.

Финальные evidence JSON V1/V2/V90/V91/V92/V93 соответствуют native `check-evidence` + `defs`: actual command, repository-relative cwd, exit/timestamps, actor/session, base HEAD, source fingerprint, stdout/stderr и SHA-256 log. `source-manifest.json` фиксирует 605 исходных/config/toolchain файлов; fingerprint всех финальных проверок: `3e2a273c169bb9e118483d19d9e39aa4552cc2c748d1e44d42875055ab545a81`. `verify-evidence.py` проверил schema/log/source/command/exit, SQL assertions и сохранность 427 outside-файлов (exit 0).

Начальные failures сохранены: red test на отсутствующий resolver; ошибки типов новых методов/fixtures; неверные spelling/CIDR fixtures; redundant instanceof в Doctor; arch A3 на прямые foreign Internal imports. Все исправлены, затронутые проверки повторены. `attempt-1/` и `attempt-2/` сохраняют прежние результаты; финальные evidence находятся в корне `artifacts/p3.2/`. При проверке формата evidence исправлен cwd на разрешённое schema значение `.`; actual absolute cwd остаётся в логах. Установленный jsonschema использует RefResolver, а не отсутствующий referencing package.

Чужие `.gitignore`, `.serena/project.yml`, `CLAUDE.md` и прежние untracked artifacts не изменены. Final commit/finish receipts записываются после этой проверки native planner-командами; итоговый commit указан в finalization artifact и ответе исполнителя.

Commit dry-run завершился exit 0. Проверены все выбранные source paths: только task files P3.2 и собственные artifacts. Native workspace selection содержит только log и P3.2 item. Outside inventory огромного workspace сохранён count/digest в `commit-preview.json`; selected paths сохранены полностью.
