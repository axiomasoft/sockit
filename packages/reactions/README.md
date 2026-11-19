# axiomasoft/sockit-reactions

Install this module alongside axiomasoft/sockit. Register `SocKit\Reactions\ReactionsServiceProvider` in your Laravel application to offer this module through Packman. Enable sockit.reactions in sockit.modules.enabled and select it in each panel that uses reactions. Generate the panel’s additive migrations before serving requests.

Configure `kinds` (the preview called it `allowed_types`) and `max_per_target` to cap how many distinct kinds a single actor can add to one target. The cap is enforced inside the owner transaction. Run `sockit:reactions:rebuild --panel=<id>` to repair existing targets through the host maintenance handles.
