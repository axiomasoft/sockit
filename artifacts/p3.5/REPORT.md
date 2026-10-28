# PLAN1 P3.5 — make-panel

Выполнен только P3.5. Исходный HEAD: `7969813f5879fd3d20903ce7c573a5568301768f`.
Session: `01a184d9-22bf-7eb2-8509-18ad592e8cc7`. Actual runtime из turn_context: `codex/gpt-6.1-sol/xhigh`, `danger-full-access`; подтверждение в `runtime.json`. `planner start` завершился с exit 0, без strict-route/take-over/scout. Review mode none, другие агенты не запускались.

Реализована зарегистрированная Artisan-команда:

```sh
php artisan sockit:make-panel Article --id=acme.article --host=article --modules=acme.demo-targets --prefix=article_ --connection=owner_a --schema=public
```

`--dry-run` выполняет полный preflight и выводит JSON manifest без файлов, директорий, config/cache writes или SQL. `--id`, `--host`, `--prefix`, `--connection` задаются явно; `name` — PascalCase. Default schema — `public`; пустой/отсутствующий `--modules` выбирает только kernel. Неизвестный/не включённый модуль запрещён. `acme.demo-targets` предоставляется только consumer fixture; production social modules не добавлялись.

Namespace/path берутся из consumer Composer PSR-4. Поддерживается одна однозначная namespace→directory mapping со строковым относительным путём; несколько mappings, массив директорий, traversal и symlink paths завершаются ошибкой до записи. В проверенном consumer `Acme\\ => app/` получены `app/Panels/ArticlePanelProvider.php` и `app/Models/SocKit/Article/*.php`. Provider наследует SocKit PanelProvider; модели повторно используют принятый P3.4 ModelEmitter и несут frozen panel/table/connection/casts/key policy. Manifest содержит пути/FQCN, model map, physical table map, components и точную инструкцию:

```php
// Добавить класс к существующему массиву panels в config/sockit.php:
'panels' => [\Acme\Panels\ArticlePanelProvider::class],
```

Команда не переписывает host config/bootstrap и не создаёт миграции. Она не устанавливает DDL. Storage preflight использует trusted deployment descriptors, explicit schema, driver prefix и полную physical identity; проверяет заявленное владение таблицами относительно всех зарегистрированных панелей без подключения к БД. Проверка наличия applied schema/DDL остаётся отдельным explicit install/migration действием.

До записи проверяются panel ID, host, selected modules/dependencies, SQL/model identifiers, таблицы/длины/ownership, все output paths и case-insensitive FQCN. Учитываются существующие файлы, директории, dangling symlinks, PHP declarations в произвольных app filenames и Composer classmap. Отсутствует `--force`. Повтор — ненулевой conflict без изменений.

Запись: lock существующей root directory, private random staging внутри consumer root, синтаксическая проверка всех staged PHP, atomic exclusive hard links без replace. Destination directory закрепляется cwd на время link/unlink, cwd восстанавливается. Ошибка файловой системы не переключается на overwrite. Cleanup проверяет device/inode/content digest и удаляет только созданное этим вызовом; изменённые пользователем файлы сохраняются, manifest ошибки содержит recovery paths. Реальные два конкурирующих процесса дают ровно один success и один conflict. Не заявляется общая filesystem transaction или поддержка небезопасного fallback на файловой системе без hard links.

Через planner API расширены только task_files P3.5: `Internal/Generation/**` для сохранения принятого namespace allowlist и `Schema/StoragePreflight.php` для чистого persistence SPI. Архитектурные gates, routes и глобальная execution-policy не менялись.

| Check | Буквальная команда | Exit | Наблюдение |
|---|---|---:|---|
| V1 | `composer test -- tests/Feature/Generation/MakePanelTest.php tests/Feature/Generation/WriteSafetyTest.php --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky` | 0 | 37 tests, 120 assertions; dry-run, invalid inputs, paths/FQCN/classmap, alias collision, staged syntax, partial failures/recovery, concurrent install |
| V2 | `python3 scripts/testing/pg-run.py composer test -- --configuration phpunit.pg.xml tests/Feature/Consumer/PanelGeneratorInstallTest.php --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky` | 0 | 1 test, 373 assertions; fresh Composer mirror install, discovered CLI, generated provider/models, fresh boot/config-cache, unchanged SQL catalogs |
| V3 | `composer test -- tests/Arch --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky` | 0 | 48 tests, 451 assertions; unchanged architecture gates |
| V90 | `composer analyse -- --memory-limit=512M` | 0 | No errors; no new baseline/suppressions |
| V91 | `composer lint` | 0 | Pint passed; rules unchanged |

Финальные команды выполнены через `run-check.py` с `/usr/bin/php8.4` (8.4.27), существующим Composer и `XDEBUG_MODE=off`. Команды не дополнены фильтрами/исключениями. Изначальная Herd static PHP 8.4.1 давала startup warning при попытке PHPStan загрузить Turbo; поддерживающая dynamic extensions системная сборка устранила предупреждение без ослабления анализа. Ранние failures типов/ожиданий тестов исправлены, Pint применён адресно. Исходные логи и history сохранены; они не используются как финальные pass receipts.

PostgreSQL запускался исключительно `pg-run.py`: собственный контейнер, loopback endpoint, random run marker, exact owner/decoy names с `_test`. PgTarget доказал container label/endpoint, current_database, PostgreSQL 16.14 и database marker до тестовых мутаций. Consumer filesystem размещён в отдельном random temp root. Wrapper удалил свой контейнер, fixture — свой temp root. Owner/decoy catalogs до/после равны; boot/config-cache — 0 connections и 0 SQL queries; database.default остаётся decoy. Ожидаемый повтор CLI с exit 1 проверен как negative assertion внутри V2.

Installed package source fingerprint V2: `18d9aa27b2c89eab82db2d6f5a46d0d06cb3d2dd6667625ab427b4b23f9d3d60` (каждый source file сравнен с установленной копией).

Все пять финальных checks относятся к одному task source/test/fixture fingerprint: `4ce784ced05f1019e7928f5955962174a848b869a5184536318dfd9468872418`. Метод вычисления и файлы указаны в `V*-source-fingerprints.json`; foreign dirty/untracked и evidence исключены из task fingerprint. Фактические cwd/время/exit/stdout/stderr сохранены в `V*.log`, `V*.stdout.log`, `V*.stderr.log`. `V*.json` соответствуют native `check-evidence` schema; `validate-evidence.py` проверяет schema, log SHA-256 и неизменность каждого исходника (`evidence-validation.json`, exit 0).

Проверка является standalone generator spike P3.5. Full migrations/CRUD/rollback smoke P3.8 и production reactions e2e P4 этим результатом не объявляются выполненными. Чужие `.gitignore`, `.serena/project.yml`, `CLAUDE.md` и foreign artifacts оставлены вне item. Push/tag/publish и внешних сообщений не было. После фактических проверок используется только предписанный planner commit, сначала dry-run; затем native finish с пятью evidence и commit.
