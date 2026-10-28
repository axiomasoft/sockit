# PLAN1 P3.1 — выполнено

Session: `01a18487-dce7-7d00-b66e-d6f4ba280d2d`. Actual runtime проверен по turn_context: `codex/gpt-6.1-sol/high`, `danger-full-access`, approval `never` (runtime.json). Base HEAD: `6ea84c6796eeafcb2a6faf542b01de9cb1ea3d2f`. Один owning executor; independent review не назначен (`review.mode=none`).

Реализован отдельный SchemaContribution SPI с FQCN slot `ModuleRegistrar::schema()`. Enabled contributions материализуются однократно в immutable SchemaComponent; owner совпадает с module ID. Settings schema остаётся отдельным контрактом. Disabled offered modules не вызывают registration. До publish проверяются также неиспользуемые enabled fragments.

PanelSchemaCompiler компилирует kernel + явно выбранные компоненты. Он проверяет зависимости и минимальные версии, циклы, ownership, неизвестные/повторные entities/columns/keys, FK targets/types/unique references, model collisions и форму identifiers. Missing dependency не включается автоматически. Builder сохраняет kernel-only default при прямом materialize; registry допускает module keys только по schema declarations. Custom overrides проходят ownership validation и имеют приоритет над defaults.

Serializable immutable PanelSchema format v1 содержит panel, connection/schema/prefix, полные maps и column/key/index/FK/check/model specifications, dependency order, component versions, ordered steps и SHA-256 digests. Короткие constraint names детерминированы. Kernel включает обязательный receipts.guard_context и composite tombstone primary key, positive/lifecycle/outcome predicates. History допускает только append новых версий/steps; прежние IDs/content/maps не меняются. Snapshot отделяет PHP references и не содержит instances, случайных timestamps или credentials.

Descriptor явно `draft_generation`: будущие default model FQCN формируются без autoload. Concrete model binding/install readiness остаётся P3.4; physical identity остаётся P3.2; production DDL остаётся P3.3. P3.1 не запускает SQL при boot/config-cache. Fixture `acme.demo-targets` v1/v2 и dependent fragment исключительно тестовые, не production social modules.

Task files расширены только для необходимого schema-aware builder (`Panel.php`) и точного namespace `Schema` в arch gate с позитивной проверкой и сохранённым negative control. Routes и validation item не менялись.

## Реальные проверки

Все обязательные команды выполнены буквально из context pack. Для PHP использован установленный `/usr/bin/php8.4` (8.4.27) через временный PATH shim; конфигурации анализа/линтера не ослаблялись. Исходники 588 файлов зафиксированы в source-manifest.json и совпадают во всех проверках.

| Check | Результат | Exit |
|---|---|---:|
| V1 | schema unit/feature: 36 passed, 113 assertions; zero DB resolution/autoload, real config:cache + fresh process | 0 |
| V2 | Panels/Modules/Arch: 86 passed, 810 assertions | 0 |
| V90 | `composer analyse -- --memory-limit=512M`: No errors, без startup warning | 0 |
| V91 | `composer lint`: passed | 0 |
| V92 (дополнительный) | полный default suite через `python3 scripts/testing/pg-run.py`: 332 passed, 3880 assertions; standalone installed consumer, verified isolated PostgreSQL 16 | 0 |

JSON evidence V1/V2/V90/V91/V92 проверены по native `schema check-evidence` и `schema defs`; сохранены actual cmd/cwd/exit/timestamps/actor, раздельные stdout/stderr, общий log и SHA-256. `verify-evidence.py` проверяет schema, log/source digests, SQL isolation assertions дополнительного consumer smoke и сохранность 416 outside файлов. Этот consumer smoke использует принятые P2 snapshots и не заявляется как P3 generated DDL или social e2e.

Начальные failures сохранены: red test до compiler; ошибки типов новых тестов исправлены; Herd PHP 8.4.1 не мог загрузить PHPStan turbo extension, поэтому выбран уже установленный native PHP 8.4.27. Первый дополнительный полный прогон завершился exit 2 на missing PG manifest до SQL; повтор через обязательный wrapper завершился exit 0. Skipped/incomplete/risky/warning тестов в финальных прогонах нет.

Дополнительный planner check: exit 0, 0 errors, 9 advisory `execution_prompt_heavy` warnings по весу context P3. Это не runtime validation и не основание менять routes. Dry-run commit ограничен scope P3.1; `.gitignore`, `.serena/project.yml`, `CLAUDE.md` и прежние untracked artifacts остаются outside. Огромный workspace outside inventory в preview сохранён count/digest; выбранные paths сохранены полностью.
