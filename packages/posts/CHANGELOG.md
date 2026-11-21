# Changelog

## Unreleased

- Fail a feed read when hydration hits a missing media resolver or a corrupt stored payload, instead of omitting that post.
- Compute the surface digest and UTC zone once per feed page.
- Extract the posts module from the development kernel as an independently installable Laravel package.
