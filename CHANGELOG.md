# Release Notes for Eat

## 5.0.1 - 2026-10-05

### Security

- **Anybody with "Manage feeds" could publish the site's secrets.** A Twig template mapping runs as
  a full object template — `craft.app` and all — and its output goes into the preview, the
  generated file and the public live route, so `{{ craft.app.config.general.securityKey }}` in a
  mapping published the security key. Template mappings are now written by admins only: a user
  with "Manage feeds" can keep or remove one an admin wrote, but not add one or change its Twig —
  checked on the preview as well as the save, since the preview renders unsaved mappings. (Craft's
  Twig sandbox was the other option, but its default policy blocks the Commerce data feed templates
  exist for.)

### Fixed

- **A burst of fetches during a cache miss rebuilt the whole catalogue in every request at once**,
  pinning PHP workers. The live route now rebuilds under a lock; requests that arrive meanwhile get
  the last good feed, or a `503` with `Retry-After` the first time.
- **Saving a feed could quietly drop its Twig template mappings.** The source select shows "Twig
  template" disabled to anyone who may not write one — a non-admin, or every user on Lite — and a
  browser doesn't submit a disabled option, so the row came back with no source. A row posted
  without a source now keeps its stored template.

### Changed

- PHPStan and ECS configuration, and the findings they raised tidied.

## 5.0.0 - 2026-08-19

Initial release.

### Added

- Fifteen channel templates — Google Shopping, Google Local Inventory, Meta, Pinterest, Microsoft
  (Bing), TikTok, Snapchat, Criteo, Awin, idealo, Kelkoo, PriceRunner, Shopzilla/Connexity, Rakuten
  and a fully custom builder — each with its attributes pre-mapped to Commerce data.
- RSS 2.0, XML, CSV, TSV, TXT and JSON output, with optional gzip.
- Attribute mapping from product attributes, custom fields (with dotted paths), static values, the
  taxonomy map, or a Twig template.
- Fourteen chainable output modifiers.
- Catalogue filters: product types, statuses, stock, image and price requirements, SKU wildcards,
  ID exclusions, row limits, and Craft's product condition builder.
- Per-variant, default-variant and per-product row strategies, with `item_group_id`.
- Store category → channel taxonomy mapping, shared across every feed on a channel.
- Delivery to a public file, a Craft asset volume, FTP, SFTP, or Google Merchant Center via the
  Content API v2.1.
- Live feed routes at `/eat/feed/<handle>`.
- Scheduled regeneration from cron, from web requests, or on product save.
- A run log with item and skip counts, byte size, duration and per-destination results.
- Console commands for listing, generating, previewing, exporting and importing feeds, and for
  reading and pruning the run log.
- `craft.eat.*` Twig API.

### Fixed

- The Feeds screen rendered an empty table. It builds its index with `Craft.VueAdminTable` without registering Craft's `AdminTableAsset` bundle, so the constructor was undefined, the JavaScript threw, and the table area was left blank with no rows and no empty-state message.

