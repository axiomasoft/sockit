# axiomasoft/sockit-groups

Install this module alongside axiomasoft/sockit. Register `SocKit\Groups\GroupsServiceProvider` in your Laravel application to offer this module through Packman. Enable sockit.groups in sockit.modules.enabled and select it in each panel that uses groups. Generate the panel’s additive migrations before serving requests.

## Membership and visibility

Create a group with `new GroupCommand(JoinPolicy::Auto, 'Chess', visibility: GroupVisibility::Private)`. Public groups expose metadata after host admission and action authorization. Private and secret groups additionally require an active, consented group membership to read metadata. The same rule protects receipt replay. SocKit has no group discovery API. Treat secret groups as unlisted in host discovery.

A host grants membership administration by binding `MembershipAdministration`. Its capture and transaction-local recheck inspect the executor, group, recipient, operation and requested role. `MembershipRole::Owner` and `Moderator` describe group membership and convey no host authority. Bind `MembershipConsent` to assess the recipient and recheck the same role before activation.

`Groups::addMember` takes a `MembershipCommand($group, 'add_member', $recipient, MembershipRole::Moderator)` and a command envelope with the expected group revision. It activates the recipient under the automatic join policy. `Groups::removeMember` takes `MembershipCommand($group, 'remove_member', $recipient)`, transitions the subject to left, advances the membership generation and emits a removal event. Administrative commands require a different executor and recipient. Active members can inspect their role via `GroupView::$viewerRole`. An invite can preselect a role, and the recipient's acceptance retains it.

Run the standard panel migration generator with `--upgrade` for the two additive Groups v2 steps. Existing PostgreSQL rows receive the previous host-admitted visibility (public) and the unprivileged member role. Historical v1 receipt digests are preserved when callers omit the new optional fields.
