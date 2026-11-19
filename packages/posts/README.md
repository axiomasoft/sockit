# axiomasoft/sockit-posts

Install this module alongside axiomasoft/sockit. Register `SocKit\Posts\PostsServiceProvider` in your Laravel application to offer this module through Packman. Enable sockit.posts in sockit.modules.enabled and select it in each panel that uses posts. Generate the panel’s additive migrations before serving requests.

Panel settings `max_body_bytes` and `allowed_formats` govern writes. Pass `format: 'markdown'` on a PostCommand to store the format with the body and each revision. `default_page_limit` applies to a FeedQuery with no limit, and `max_page_limit` bounds explicit page sizes. Existing v1 PostgreSQL content receives the plain format in the additive v2 migration.
