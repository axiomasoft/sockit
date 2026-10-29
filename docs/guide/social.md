# Социальные модули

SocKit предоставляет standalone reactions и posts/feed. Это pre-release API; интеграция обсуждений принадлежит Chatom/Forum, а production erasure и release qualification остаются в P9.

## Установка и границы

Явно зарегистрируйте Laravel providers `SocKit\Reactions\Internal\ReactionsServiceProvider` и `SocKit\Posts\Internal\PostsServiceProvider` для используемых модулей. В `sockit.modules.enabled` включите `sockit.reactions` и/или `sockit.posts`, затем выберите нужное подмножество в определении каждой панели и соответствующие features surface. Регистрация provider, глобальное enable и выбор на панели — разные шаги. Для reactions-only posts не требуется. Настройте доверенные host adapters для actor, installation, surface и target; payload не выбирает автора. Target alias и полная identity/incarnation должны разрешаться через host `TargetResolver`. Для записи необходим доказуемый `TargetWriteFence` на owner connection: remote-only или недоказуемая граница отклоняется. Удалённые решения чтения являются sampled, а не атомарным cross-DB revoke.

В consumer с настроенными provider и connection, например:

```sh
php artisan sockit:make-panel Article --id=acme.article --host=article --modules=sockit.reactions --prefix=article_ --connection=owner_a --dry-run
php artisan sockit:make-panel Article --id=acme.article --host=article --modules=sockit.reactions --prefix=article_ --connection=owner_a
php artisan sockit:panel:migrations acme.article
```

Зарегистрируйте выданный panel provider в конфигурации и примените конкретный migration path из manifest на проверенной owner connection. Для additive posts enable выберите `sockit.posts` и используйте `sockit:panel:migrations acme.article --upgrade`; проверьте manifest и примените выданный path. Не перезаписывайте consumer files или старые миграции. Подробности recovery и rollback: [runbook](../guides/panel-install-upgrade.md). Production reactions consumer и две схемы изоляции проверены в [P4.8](../../artifacts/p4.8/REPORT.md).

## Команды и чтение

Получите текущий `OperationHandle` через kernel API. `SocKit\Reactions\Reactions` принимает `ReactionCommand(surface, target, kind)`; `digest(handle, command, action, expectedRevision)` формирует digest для `CommandEnvelope`, затем `add` или `remove` возвращает `OperationResult`. Первый add требует null revision; последующие изменения требуют текущую revision. Новый key для уже активного add или уже неактивного remove даёт revision conflict без effects. После потери ответа повторите исходные key и digest либо вызовите `lookup` с исходным namespace/key; другой payload с тем же key конфликтует. Не повторяйте CAS автоматически.

`SocKit\Posts\Posts` принимает `PostCommand` через `create`/`edit`, digest через `digest`; create требует null resource и revision, edit — resource и expected revision. `publish=false` создаёт pending, null audience задаёт actor-only default. `PostTransition` с reason и expected revision выполняет publish, hold, reject, hide, restore, delete или audience через `transitionDigest`/`transition`. `PostResult` содержит operation outcome и отдельно состояние публикации: успешное выполнение не означает published. Hide сохраняет предыдущее publication state; restore повторно проверяет текущие полномочия и не превращает pending/held/rejected в published. `published_at` сохраняет время первой публикации.

`read`, `revision`, `lookup` и `feed` применяют текущие guards, включая host consent, admission, audience и lifecycle; автор не обходит их. Parent с read-only может читать при разрешении, но не писать. Host остаётся владельцем consent. При отсутствии `ContentSafety` binding применяется no-prescreen; настроенный недоступный или неизвестный adapter запрещает действие. Media refs требуют `MediaResolver`; байты не хранятся SocKit.

`FeedQuery` поддерживает limit 1–100 и optional author filter. Cursor аутентифицирован и связан с контекстом и фильтрами; порядок по первой публикации и ID. Это текущее sampled чтение, не snapshot: обновите первую страницу для новых публикаций. Ограниченный scan budget может вернуть короткую страницу с continuation по последнему проверенному кандидату. `Reactions::summary`/`summaryMany` проверяют текущий доступ для каждого target; counts не раскрывают недоступный контент. `rebuild` требует `reactions.rebuild` и локальных fences, пересчитывает только counters и не воспроизводит исторические события.
