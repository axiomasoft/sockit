# PLAN1.P2.6 — Durable outbox and consumer boundary

Исполнен только P2.6 owning executor-ом. Native start принят; actual runtime — `codex/gpt-6.1-sol/high`, session `01a1833a-c3e8-7733-9d1b-6919d1612579` (runtime.json). Другие агенты не запускались. Independent review не требуется: `review.mode=none`; P2.7 этим исполнением не запускался.

## Реализация

- Public EventEnvelope/DeliveryResult/ReplayMode/OutboxConsumer и scoped OutboxRelay. Event wire v1.0 несёт полную installation/panel/source generation/event identity, surface/incarnation, source/privacy revisions, audience, optional target, UTC time и только typed resource refs. Digest охватывает все immutable fields; неизвестная версия, content payload и digest conflict не дают success.
- Source generation берётся из frozen typed `SocKitConfig::events`, development default `'1'`, nonempty <=64 bytes; она не является actor generation. Append и dispatch отвергают другой server source epoch. Config содержит bounded lease/retry/attempt settings, проходит реальный Artisan config:cache и fresh bootstrap без SQL.
- Узкий additive `ModuleRegistrar::outboxConsumer(id, FQCN)` проверяет concrete contract/unique IDs; consumer declarations заморожены с graph и effective только selected-on-panel. Singleton definitions содержат FQCN, не actor/consumer state. Невключённые consumers не разрешаются. Старое поведение P1 draft-setters сохранено.
- Internal OutboxStore адресует только immutable owner connection/table map. Append требует caller-owned active transaction; INSERT UNIQUE complete identity не использует absent-row lock, identical append сохраняет delivery state, другой digest/immutable envelope вызывает conflict и rollback. Outbox — одна kernel logical entity с durable per-consumer metadata в строке; kernel inbox не добавлена.
- Claim — короткая owner transaction с FOR UPDATE SKIP LOCKED, PostgreSQL clock, random token/expiry/attempt; commit до consume. ACK/retry/quarantine — отдельная короткая tx: полная identity, current token и неистёкшая lease. Старый worker не перезаписывает новый ACK. Retry owner — relay; exponential bounded backoff и durable quarantine при исчерпании попыток, включая crash leases. Missing/disabled consumer сохраняет событие с явной quarantine reason. Missed wakeup восстанавливается poller-ом.
- Перед каждым consume: current trusted handle, ActorContexts worker re-resolution, action authorizer, admission/audience, текущая owner surface lifecycle/revisions и optional public target visibility/privacy. Host/consumer не вызываются под owner lock. Ссылка на иной domain resource без публичного current owner lookup отвергается. ProjectionOnly проходит current guards, обновляет только идемпотентную локальную projection потребителя, не меняет live delivery state и не подавляет будущую live delivery inbox.
- Consumer-owned acme_consumer SQL inbox/projection/effect fixture использует отдельный реальный PDO и свою transaction. UNIQUE `(consumer,installation,panel,source_generation,event_id)` + digest; inbox и effect commit/rollback вместе. Это fixture, а не обязательная kernel schema или remote adapter.

## Изоляция до мутаций БД

Неизменённый P2.1 `scripts/testing/pg-run.py` создал отдельный owned `postgres:16-alpine`: unique run label, случайный private loopback port, owner/decoy `*_test`, mode-0600 manifest. Перед fixture DDL PgTarget проверил container label/endpoint, current_database, PostgreSQL `160014`, database run marker и реальные PDO backend PIDs; RuntimeDatabase сверил Laravel owner/decoy identities. Ambient DB env очищается bootstrap, `.env` не является источником тестового подключения. Proof и actual run identities находятся в PG-preflight.log, V1-final.log и V2-final.log; cleanup удалил только контейнеры соответствующих runs. Dev/prod не использовались.

PG-missing.log: намеренный запуск без manifest реально отклонён bootstrap с exit 2 до DDL. Это ожидаемый negative guard result, не product pass и не пропущенный acceptance. Supplemental command records отделены от native check-evidence.

## Проверки final candidate

| Check | Actual result | Evidence |
|---|---|---|
| V1 | exit 0; 28 tests / 339 assertions | V1-final.json, V1-final.log |
| V2 | exit 0; 7 tests / 103 assertions | V2-final.json, V2-final.log |
| V3 | exit 0; 74 tests / 334 assertions | V3-final.json, V3-final.log |
| V90 | exit 0; PHPStan max: No errors | V90-final.json, V90-final.log |
| V91 | exit 0; Pint passed | V91-final.json, V91-final.log |
| V4 affected regression | exit 0; 92 tests / 855 assertions | V4-final.json, V4-final.log |

Фактические commands/cwd/exit/stdout/stderr/timestamps, environment fingerprint и candidate fingerprint записаны runner-ом. Все final evidence проверены native check-evidence/defs schemas и SHA-256 логов (evidence-verified.json); у них один source candidate digest. source-manifest.json фиксирует actual owned source files. Никаких suppressions/baseline/ослабления arch gates не добавлено. Принятые P1 independent reviews не повторялись; V4 проверяет изменившиеся module/config inputs, fresh caches и документацию.

Два реальных relay processes подтверждают SKIP LOCKED и отсутствие второго claim, пока первый держит row lock; после commit lease остаётся durable. Stale ACK проверен после реального expiry и повторного claim с другим token. Отдельные PHP processes завершаются до consume, внутри consumer transaction и после consumer commit до ACK: следующая попытка сохраняет event/digest, effect=1 и inbox=1. Повторные expired crash leases заканчиваются quarantine без нового consume.

Две inbox races подтверждены pg_stat_activity и pg_blocking_pids на разных actual PDO backend PIDs: UNIQUE ждёт uncommitted winner, same digest даёт confirmed/no-op, другой digest — quarantine, effect остаётся один. Равный event UUID в разных panels/source generations/installations/consumers независим. Каждый consume фиксирует owner transactionLevel=0. Per-consumer delivered/quarantine не смешиваются; concrete swap использует тот же public consumer port.

Current action/admission/audience/actor revoke, hidden/deleted/unknown target, stale privacy/source generation, deleted/recreated incarnation, unsupported wire и malformed/digest-corrupt events отвергаются до consume. Старое событие не меняет новую incarnation или tombstone. ProjectionOnly: delivery effects=0, live inbox=0, projection dedup=1; следующая live delivery создаёт один effect. Committed-only visibility и rollback append+fixture state проверены независимым PDO; wakeup не требуется.

Исходные failures и исправления сохранены: ACK builder после first() сохранял limit и давал некорректный schema+driver-prefix ctid update — заменён отдельным identity-bound update под существующим lock. Исправлены test call/typing/cache-key ошибки. Дополнительные guards старых draft-setters, обнаруженные V4 как регрессия принятого P1 поведения, удалены; исходный Unit/Modules тест не менялся. Финальные checks выполнены после этих исправлений. Существующий optional phpstan_turbo startup warning сохранён; PHPStan exit 0 / No errors, глобальный environment не перестраивался.

Native plan check: 0 errors, 5 существующих execution_prompt_heavy warnings (plan-check.log). 410 чужих исходных dirty/untracked files сохранены byte-for-byte (foreign-preserved.json). Продуктовые изменения и artifacts ограничены task_files P2.6; дополнительные точные пути для Modules declaration PHPDoc и targeted tests внесены native API.

## Границы и завершение

Это durable event/relay primitives и реальная SQL consumer conformance, не полный atomic operation envelope, не social или remote delivery e2e, не clone/restore qualification. Полная state/revision/receipt/outbox orchestration и guarded execute/lookup — P2.7; CLI/worker dispatch — последующие owning items, production snapshots — P3, domain resource lookup — будущие domain owners. Cross-database revocation и remote exactly-once не обещаются. Quarantine redrive/retention/purge не автоматизируются. Production migrations/generators/social modules/external adapters не создавались.

Native finish с обязательными evidence: finish.json. Scoped Conventional Commit создаётся project launcher по точному expanded owned path list, с PLAN1.P2.6 и Plan trailers; native commit journal/Git log являются источником SHA. Push/tag/publish не выполнялись.
