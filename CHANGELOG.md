# Release Notes for Eat

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
