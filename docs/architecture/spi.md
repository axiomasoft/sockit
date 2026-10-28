# Контракты SPI

## Шаблон документации SPI

Для каждого SPI при реализации укажите назначение и реализатора; точную сигнатуру; результаты и ошибки; default или его отсутствие; consistency boundary; fake и swap-пример; правила версионирования. До реализации сигнатура не считается подтверждённой.

## Реестр P1

- `TenantResolver`: доверенная инсталляция и изоляция.
- `ActorContextResolver`: доверенный текущий актор.
- `ContourResolver`: текущие факты контура.
- `AudienceResolver`: вычисление аудитории; обязательные guards не обходятся.
- `SurfaceAudiencePolicy`: admission и containment.
- `ActionAuthorizer`: ограничение действия.
- `TargetResolver`: видимость и lifecycle сущности хоста.
- `PanelProvider`, `SurfaceProvider`, `Module`: декларация и композиция.

Точные сигнатуры, ошибки и swap-примеры заполняются по мере реализации соответствующих пунктов P1.

## Host targets — P2.3

`TargetResolver::resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution`
поставляет текущую видимость host Article/Product для будущего L0 reactions и target capability P4 posts.
`TargetRef` содержит `HostEntityRef` (стабильный alias и lossless строковый ключ) и `Incarnation`.
Panel identity находится в обязательном `SurfaceRef`. Числовые ключи никогда не преобразуются в int;
`00042`, `42`, UUID и ключ с двоеточиями сохраняются буквально. Новая incarnation означает другую сущность.

`TargetResolution` — immutable снимок: точные surface/target, trusted installation, отдельные read/write
`AudienceVerdict`, `TargetLifecycle` (`live`, `read_only`, `hidden`, `deleted`, `unknown`), `Revision privacyRevision`
и непустой generation (1–128 байт, без управляющих символов). Тела контента и ORM отсутствуют.
Wire v1.0: `{version,surface,target,installation,read,write,lifecycle,privacy_revision,generation}`;
verdict: `{decision,reason,policy_version,expires_at}`; UTC expiry имеет микросекунды.
`TargetRef` wire: `{host,incarnation}`. Некорректная форма даёт `sockit.payload_invalid`,
неподдерживаемая major/minor — `sockit.wire_version_unsupported`. Векторы: `fixtures/targets/`.
Ни wire, ни сериализованный снимок не предоставляют полномочий.

Хост явно настраивает FQCN в `sockit.resolvers.targets` для aliases из `PanelDefinition::hostTypes`.
Отсутствующий/недоступный binding даёт `sockit.provider_unavailable`; Allow fallback отсутствует.
Модуль вызывает `ModuleRegistrar::target(alias: 'acme.target', resolver: MyTargetResolver::class)`;
регистрация содержит только FQCN, а не instances. Будущий posts зарегистрирует собственный alias тем же SPI;
ядро и reactions не импортируют Posts. Только selected-on-panel модули участвуют в registry.
Совпадение alias у двух выбранных модулей одной панели — `sockit.configuration_invalid` до публикации графа;
на разных панелях допустимы разные adapters. Private draft фиксируется при compilation, поздний `target()`
и module registration отвергаются. Невключённый capability не получает host fallback, если его alias
отдельно не объявлен хостом в `hostTypes`. Boot/config:cache не создаёт resolver instances и не делает SQL.

Операционный вход: `$handles->resolveTarget(handle: $handle, target: $target)` через `OperationHandles`.
Он заново проверяет trusted ActorContexts/installation и immutable panel/surface, затем current admission
перед каждым resolve (включая read/replay/lookup). Ошибка адаптера или mismatch refs/installation →
`sockit.authorization_unknown`; admission deny → `sockit.authorization_denied`.
Hidden/deleted/unknown/expired visibility не допускает чтения/записи; read-only admission/target не допускает
записи. Verdict не расширяет admission и сохраняет его конечный expiry. Факты актора не принимаются из payload.
Instances host binding — scoped; module FQCN разрешается явно на каждый вызов через container, без хранения
актора/installation/resolver в singleton registry. Хост может зарегистрировать свой FQCN scoped.

Resolver вызывается вне owner transaction; активная транзакция выбранного connection (и любая открытая
транзакция известного Laravel manager) отвергается до вызова resolver. Адаптер сам задаёт ограниченные
network timeouts; автоматических retries/batch и кеша полномочий между входами нет. Remote reads являются
текущим sampled resolution: атомарный отзыв в другой БД не обещается.

Для записи выбранный adapter дополнительно реализует
`TargetWriteFence::recheck(OperationHandle $handle, TargetResolution $resolved): AuthorizationDecision`.
`$handles->recheckTarget(...)` допускает только активную owner transaction и соответствующие panel/refs,
installation/live/write snapshot. Нет optional fence/проверяемой local generation → Unknown
(`sockit.authorization_unknown` через `requireAllowed()`); Throwable также Unknown. Fence исполняется только
локально, без actor/tenant/admission/remote resolve и без сети. Adapter владеет своими host-запросами,
проверяет incarnation, lifecycle, visibility, privacy revision/generation и удерживает local lock/fence
до commit на **точном owner connection**. При stale/unknown требуется rollback, затем новый resolve вне tx.
Иной consistency profile требует отдельного явного контракта.

Порядок receipt → surface → target и остальные action/admission/containment/lifecycle guards обеспечит
операционный конверт P2.7; успешный target fence сам по себе не означает авторизацию всей операции.
Хост обязан обеспечить локально проверяемую boundary; сетевые callbacks под lock запрещены.
Добавление методов в stable SPI требует major; новые возможности — отдельным capability-интерфейсом.

`SocKitFake::install($app)->targets` даёт `FakeTargetResolver`: `returns(actor: ..., resolved: ...)`,
`failWith(...)`; всё ненастроенное deny. Ключ включает весь actor context, installation, panel/surface,
target и incarnation. Fake не реализует local fence. Swap/registry fixtures `acme.runtime` и `acme.other`
и локальный PostgreSQL host adapter проверяют seams, **не являются social e2e**.
