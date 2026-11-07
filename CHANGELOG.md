# Changelog

## Unreleased (0.4)

- The Sources and Storage tabs carry no summary strip: the strip and the
  figures are the Overview's. Each tab's table sits under the house filter bar
  (a state or target chip with counts, and a search) and over the house foot
  (the count, 20 · 50 · 100 · All per page, the page), all of it plain links
  and one GET form.
- Sources says what a module tells the hub in words — "What a module tells
  this hub", four lines and how many are told today — in place of the list of
  declarations; a module that stores nothing reads "stores no files" with the
  chip "none".
- The Overview's chart note reads "a module that stores no files draws no bar".
- `TablePageService` and `TablePage` cut a table into the foot's pages;
  `SourcesBoard::listed()`/`told()` and `StorageBoard::listed()`/`roles()`
  answer the narrowed lists. `SourcesBoard::facts()` and
  `StorageBoard::facts()` are gone with the strip they fed.
