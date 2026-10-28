# Приватность и аудитория

- **Tenant = инсталляция хоста** (`InstallationRef`), не школа. Edufy: один tenant = платформа; школа и класс — уровни контура внутри.
- **Актор = человек** хоста (`ActorRef`, Edufy `users.id`), один на все школы; роли/отношения в школах — факты ContourResolver, не новая identity. Сервис-актор школы (официальные новости) легален: `ActorKind::Service`, `onBehalfOf` = школа/человек, ageBand=Adult (GC-4).
- **Scope контента** — одна из форм AudienceSpec: `direct` (адресаты), `group` (группа SocKit/хоста), `contour(level, ref)` (контур уровня: класс 42, школа 7), `platform` (все акторы инсталляции, кроме анонимов), `public` (все, включая анонимов).
- **Floors**: платформенный floor (config `sockit.privacy.floor`) ∩ floor surface (только сужение) = effective floor surface. Floor ограничивает **публикацию** (какой scope можно выбрать), не чтение. Для несовершеннолетних и неизвестного возраста — `minor_max_scope`/`minor_default_scope` (private-by-default).
- **contour_levels** — упорядоченный реестр хоста от узкого к широкому (Edufy `[class, school]`). Сужение `contour_levels` на surface — канонический потолок ширины контура (GC-2). Ministry не входит в реестр Edufy — чиновник не актор SocKit.
- **Чтение** решается включением множеств (T-14) по текущим фактам хоста; ранг не используется.
- **Архив**: surface instance может быть archived (P2). Участник со статусом `Archived` (выпускник) читает только при `archiveReaders=true` surface kind; новые посты/комментарии в архивную surface запрещены (SN-05).
- **Наблюдатель** (родитель): `ContourMembership::ReadOnly` → `AllowReadOnly` — читать можно, писать нельзя (SN-03, v2-26).
- **Direct grants** после смены parent link и lifetime grants — D11 (по умолчанию грант живёт, пока жив факт хоста; SocKit снимков аудитории не хранит, кроме явного `direct`-списка).
- **Коммерческий entitlement никогда не расширяет приватность**; отключение аддона не удаляет контент и не расширяет аудиторию.

## Алгоритм

```text
read(viewer, subject):
  0. resolve trusted host isolation + known panel/surface + correct incarnation.
     subject.installation != viewer.installation -> deny other_installation.
     A panel ID or equal ActorRef never proves ownership. Unknown -> deny.
  1. surfacePolicy.admit(viewer, subject.surface) -> current verdict.
     Deny/unknown/error -> deny, including author. No author shortcut.
  2. evaluate content AudienceSpec using current membership/addressed actor:
     public -> allow; platform -> authenticated; direct -> actor addressed;
     group -> current member; contour -> Live allow, ReadOnly read_only,
       Archived -> archiveReaders ? read_only : deny; None deny.
  3. intersect (1) with (2): Deny wins, read_only cannot become allow.
     if surfaceArchived -> every allowed result becomes read_only.
  4. content lifecycle/safety/tombstone visibility remains a mandatory owner guard;
     audience Allow does not reveal held/hidden/deleted content.
  5. errors/missing batch key/duplicate subject key -> explicit deny/problem.

publish(author, subject, floor):
  0. trusted context + same installation + known panel/target binding;
     surfacePolicy.admit must allow writing; archived/anonymous -> deny.
  1. scope in allowedScopes and coarse max/minor max; contour level allowed.
  2. surfacePolicy.contains(author, surface, proposedSpec) -> current set containment.
     Only explicit Allow passes; unknown/unprovable -> deny. Rank is insufficient.
  3. author must currently belong to group/contour/platform audience; no author shortcut.
  4. ActionAuthorizer and ContentSafety independently constrain operation.

readMany == scalar, grouping by installation+panel+surface+audience+actor,
never only contour level/ID. Gate enforces hard checks even for custom resolver.
```
SurfaceAudiencePolicy — host SPI (точные сигнатуры kernel-contracts#audience). Для L0 host resolves owner visibility, для Edufy — class/school relation. Пустой config может boot, но неизвестная boundary не даёт операции. Recheck generations under lock uses local facts/tokens; remote call is outside DB transaction. Revocation consistency boundary described in invariants#pipeline.

## Примеры

Edufy: panel edufy.community с собственными таблицами, surface kind edufy.class-feed и instance class:42. Host policy допускает текущего ученика/родителя класса 42; родитель получает read_only. Автор класса 42, выбывший из класса, не читает по авторству. Тот же actor ID другой инсталляции запрещён. Actor одновременно в классах 42 и 43 не может публиковать на surface 42 с аудиторией класса 43; unrelated group другого класса также запрещена, несмотря на меньший rank. Архив переводит любой разрешённый read в read_only.
CRM: panels crm.public и crm.team для Article:42 имеют разные table/model maps и настройки reactions; SurfaceRef обязательно несёт panel, одинаковые kind/host IDs независимы. TargetResolver проверяет task/article visibility хоста без школьных моделей. Comments UI при необходимости обращается к владельцу Chatom отдельно; он не часть данной acceptance.
