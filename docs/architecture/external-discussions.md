# Внешние обсуждения

## Владение и доступность

D14: Chatom Comments/Forum владеют телом, деревом, lifecycle и модерацией обсуждений. SocKit владеет reactions, posts/feed и optional groups; собственного comments package, дерева, counters, seats или comments operations нет. Standalone social не зависит от Chatom, Forum, AzGuard или Vaulter.

Наблюдение 2026-10-17, уточнённое в плане 2026-10-28, подтвердить в P3.1/P7.1: Comments readiness ADR описывает план и fixture, а не подтверждённый production package/API. Forum находится в packages/forum; его актуальный публичный контракт и multi-panel ограничения требуют проверки владельцем. Исторические примеры не являются гарантией доступности API.

## Условные адаптеры

P7 возможен при названном потребителе и подтверждённом public Forum API, включая target, policy, ACL, idempotency и outcome. P8 также условен. Адаптер не пишет и не читает чужие таблицы напрямую, не копирует обсуждение и не заменяет owner; выключенный adapter не выполняет чужой SQL или resolution. Неподтверждённый Comments API блокирует только соответствующую интеграцию, не standalone функции. На защищённом выходе обязательны trusted installation/panel/target, current admission и containment; unknown означает deny, архив read-only.
