# Changelog

## Unreleased

- Load each reaction summary with one count query and one membership query.
- Treat SQLite boolean values `0`, `1`, `'0'` and `'1'` as inactive or active.
- Extract the reactions module from the development kernel as an independently installable Laravel package.
