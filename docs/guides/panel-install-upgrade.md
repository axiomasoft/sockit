# Установка и обновление панели

Это воспроизводимые наблюдения генерации и upgrade панели, а не инструкция по включению production reactions. `acme.demo-targets` — исключительно тестовый schema module fixture. Команды ниже выполнялись в корне отдельного Laravel consumer, с его конфигурацией и PSR-4 mapping `Acme\\ => app/`. Заменять значения следует только после собственной проверки окружения. Автоматические пределы доказательства: `tests/Feature/Generation`, `tests/Feature/Consumer` и `tests/Integration/Schema`.

## Подготовка и генерация

Выберите стабильные panel ID, host alias, connection и prefix; определите deployment identity: driver, server/cluster, database, schema, driver prefix и полные имена таблиц, индексов и FK. Разные connection aliases могут указывать на один сервер и конфликтовать. Неоднозначный proxy/failover требует явного descriptor. Итоговые PostgreSQL identifiers ограничены 63 байтами, без усечения. Генератор выполняет preflight имён, модулей, ownership, путей и классов до записи; это не проверка уже применённой схемы БД.

P3.8 установил source copy без symlink и dev dependencies, затем явно выполнил discovery:

```sh
composer install --no-dev --no-interaction --no-scripts
php artisan package:discover
```

P3.5 проверил полный preflight без записи и затем создание Article:

```sh
php artisan sockit:make-panel Article --id=acme.article --host=article --modules=acme.demo-targets --prefix=article_ --connection=owner_a --dry-run
php artisan sockit:make-panel Article --id=acme.article --host=article --modules=acme.demo-targets --prefix=article_ --connection=owner_a
```

Manifest содержит provider, model map, tables, components и инструкцию регистрации. В проверенном mapping provider — `app/Panels/ArticlePanelProvider.php`, модели — `app/Models/SocKit/Article/`, включая `DemoTarget.php`, `OutboxEvent.php`, `Receipt.php`, `SurfaceInstance.php`, `Tombstone.php`. Пути зависят от mapping приложения; сверяйте manifest, не предполагайте их универсальными. P3.8 также создал Product:

```sh
php artisan sockit:make-panel Product --id=acme.product --host=product --modules=acme.demo-targets --prefix=product_ --connection=owner_b
```

Добавьте выданный `Acme\Panels\ArticlePanelProvider` и аналогичный Product в массив `panels` конфигурации SocKit. Это app-owned SocKit PanelProvider, а не Laravel service provider в списке bootstrap providers. Предоставьте и включите fixture module, connection descriptors и host bindings до compilation. P3.8 затем выполнил `php artisan config:cache` и fresh bootstrap без SQL. Для doctor успешного consumer вызова в evidence P3.5/P3.6/P3.8 не найдено: этот шаг здесь не verified.

Генератор не перезаписывает существующие файлы; повтор make-panel в smoke дал ожидаемый conflict. При partial failure изучите manifest с recovery paths и состояние каждого файла: cleanup удаляет только созданное текущим вызовом с совпадающими identity и digest. Сохраните пользовательские изменения, устраните конфликт и повторите preflight; общей filesystem transaction не обещано.

## Миграции и upgrade

P3.8 после регистрации вызвал:

```sh
php artisan sockit:panel:migrations acme.article
php artisan sockit:panel:migrations acme.product
```

Manifest возвращает конкретные migration и ancestry paths, digest и counts applied/generated. В том запуске Article initial migration была `database/migrations/2026_10_29_040818_000001_sockit_deb76148b67581c0.php`; путь содержит timestamp и не переносим на другой запуск. Соответствующий запуск применил именно его:

```sh
php artisan migrate --database=owner_a --path=database/migrations/2026_10_29_040818_000001_sockit_deb76148b67581c0.php --force
```

Для своей панели используйте путь из собственного manifest и проверенный owner connection; не запускайте одновременно vendor и published copies. Отдельно подтвердите целевую БД и права до DDL. В тестах PostgreSQL допускается только изолированный owned target через `scripts/testing/pg-run.py`, с проверкой PgTarget container/endpoint, run marker и имени `*_test` до SQL. Production deployment требует собственной проверки identity и backup; тестовый wrapper не подтверждает production окружение.

Snapshot фиксирует physical map, component versions, digest и ancestry; generated ещё не означает applied. Не редактируйте применённую migration и не меняйте ID или table map существующей панели. Для additive changes измените декларацию модуля/панели, проверьте совместимость и выполните проверенный в P3.8 вызов:

```sh
php artisan sockit:panel:migrations acme.article --upgrade
```

Проверьте manifest и pending/applied ledger, затем примените именно выданный additive path на owner connection. P3.8 проверил прежние migration, custom model/provider hashes и данные до/после генерации, затем выполнил миграцию и повторный no-op. При `manual_changes_required` вручную приведите сохранённые model bindings/casts/relations к manifest и повторите в свежем Artisan процессе; генератор не заменяет custom files. P3.6 проверил эту процедуру и сохранение sentinel.

Disable сохраняет таблицы, историю и pending outbox; обеспечьте совместимого consumer для draining либо явный quarantine. Старый consumer-owned provider может требовать ручного исключения inactive model entries из runtime map; не переписывайте его автоматически. Rollback в P3.8 выполнялся по конкретной migration и batch на owner connection в обратном порядке, с проверкой сохранности Product. Номера batch и пути зависят от deployment. Не используйте общий rollback для выборочного удаления панели. Автоматического uninstall/drop по текущему config нет: отдельное явное решение должно ограничивать удаление принадлежащими snapshot объектами; не удаляйте всю schema через CASCADE.
