# Структура и конфигурация

## Каталоги

```text
packages/core/                 # axiomasoft/sockit, SocKit\
  src/{Attributes,Support,Actors,Audience,Panels,Surfaces,Operations,Errors,Modules,Configuration,Testing,Internal}/
  modules/{reactions,posts,groups}/  # отдельные владельцы; module-owned schema/generator contributions
  config/sockit.php
  resources/contracts/{fixtures,schemas}/
  stubs/                       # panel provider/model/migration; consumer owns generated output
packages/testkit/              # P6
packages/forum/                # P7 условный adapter с зависимостью Forum, без второго форума
packages/chatom-bridge/        # P8 link-only announcements
tests/{Arch,Unit,Feature,Integration}/
docs/{architecture,adr}/
```
Core owner manifest владеет namespaces/config; schema-компоненты модулей владеют своими таблицами. Consumer host владеет сгенерированными providers/models/migrations. Функциональные модули не требуют отдельных Composer-пакетов без реальной границы зависимости/поставки. Нет packages/comments.

## Именование

Vendor axiomasoft; namespace SocKit\, модули SocKit\{Reactions,Posts,Groups}\. Панели: SocKit\Panels\{PanelProvider,Panel}, PanelId, PanelDefinition/PanelStorage. Surfaces не переименовывается в panel: это разные оси.
Config: sockit.modules.enabled — глобально доступные/включённые module IDs, sockit.modules.settings — их defaults; sockit.panels — FQCN PanelProvider. Панель выбирает подмножество enabled modules и свои settings. sockit.surfaces — FQCN SurfaceProvider; surface должна явно назвать panel. Отдельного legacy ключа sockit.modules=list нет, поскольку пакет ещё не выпущен.
Panel ID — стабильный публичный identifier; prefix/connection — отдельные физические свойства, не выводятся из host ID. Логические tables (surface_instances, receipts, outbox, tombstones, reactions, reaction_counts, posts, post_revisions, groups, group_members) резолвятся по frozen descriptor. Нет общего обязательного sockit_ набора, кроме явной default панели. Имена таблиц/моделей пользовательские, schema component принадлежит модулю.

## Конфигурация

```php
return [
  'ids' => ['strategy' => 'uuid7'],
  'panels' => [], // list<class-string<PanelProvider>>
  'surfaces' => [], // list<class-string<SurfaceProvider>>, each selects panel
  'modules' => ['enabled' => [], 'settings' => []],
  'resolvers' => ['tenant'=>null,'actor'=>null,'contour'=>null,'audience'=>null,
                  'authorizer'=>null,'groups'=>null,'surface_policy'=>null,'targets'=>null],
  'privacy' => ['floor' => [
    'allowed_scopes'=>['direct'], 'default_scope'=>'direct', 'max_scope'=>'direct',
    'minor_default_scope'=>'direct', 'minor_max_scope'=>'direct', 'contour_levels'=>[],
  ]],
];
```
Default config валиден без school/class/actor и успешно boot/config:cache при пустом реестре панелей. Его floors не разрешают anonymous/public по умолчанию. Host явно задаёт свою floor до объявления contour surfaces. Пустой реестр не создаёт SQL; попытка операции без panel/нужных resolvers — fail-closed.
Скаляры/массивы/class-string; никаких Closure/объектов. При override вложенных секций выполняется явная нормализация defaults (не доверять shallow mergeConfigFrom). Полный frozen panel config: connection, table/model map, target aliases/host types, enabled modules/settings, privacy ceiling. Никаких runtime global config mutations.

## Хранение

Embedded profile на КАЖДОЙ панели: mutation/revision/receipt/outbox/tombstone на одном resolved connection в одной транзакции. Между панелями/соединениями нет обещания общей транзакции. D15 уточняет и заменяет глобальную storage-топологию D13.
Собственные сущности UUIDv7; host refs lossless строки со stable type alias, тип ключа actor/target независим от внутренних IDs. Таблицы создаются для конфигурируемой панели, не per host row. Нет tenant_id: host восстанавливает isolation context и доказывает его до доступа; panel не заменяет tenant.
Panel/model table map immutable; hydration/relations/queue restore должны сохранять panel identity. DDL только в установке/миграциях P3, не на первом запросе. PostgreSQL ≥16 primary concurrency profile; SQLite unit/arch, MySQL не обещается до qualification. Schema snapshots/upgrade/rollback — knowledge/panel-design#schema. Чужие таблицы не читаются и не изменяются; cross-family target — через public resolver, без FK к произвольной внешней morph-table.
