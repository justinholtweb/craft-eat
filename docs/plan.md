# Eat — build plan

Product feeds for Craft Commerce 5. `justinholtweb/craft-eat`, handle `eat`, namespace
`justinholtweb\eat`, version 5.0.0. **Lite (free) + Pro ($79).**

## Why it exists

WooCommerce merchants reach for WebAppick's *Product Feed Manager / CTX Feed*: pick a merchant,
get a template with that merchant's required attributes already mapped, filter the catalogue,
massage the values, and hand Google/Meta/Pinterest a URL that stays fresh. Craft Commerce has
nothing equivalent — the state of the art is hand-writing a Twig template per channel and hoping
you remembered `g:availability`, `item_group_id` and the tax/shipping blocks.

Eat is that plugin for Commerce:

| | Hand-rolled Twig | Eat |
|---|---|---|
| Channel attributes | you read the spec | 15 built-in channel templates, required attributes pre-mapped |
| Format | whatever you write | RSS 2.0, XML, CSV, TSV, TXT, JSON, optional gzip |
| Value cleanup | filters, inline | 14 output modifiers per attribute, chained |
| Category taxonomy | hard-coded strings | mapping screen, product type / category / field → channel taxonomy |
| Freshness | template renders per hit | scheduled regeneration, regenerate-on-save, queue jobs |
| Delivery | a public template route | file + public URL, live route, asset volume, FTP, SFTP, Google Content API |
| Large catalogues | one big render, OOM | batched query, streamed writer, flat memory |
| Did it work? | look at the file | run log with counts, bytes, duration, per-delivery result |

## Editions

- **Lite (free)** — 2 feeds, the Google and Meta channels, every format, attribute mapping from
  product/variant attributes, custom fields, static values and the taxonomy map, catalogue filters,
  scheduled regeneration, file delivery with a public URL, the Twig API, last-run info.
- **Pro ($79)** — unlimited feeds, all 15 channels **and** the custom template builder, output
  modifiers and conditional rules, Twig-template sources, the run log and its history, live feed
  route, asset-volume / FTP / SFTP delivery, Google Merchant Center Content API push, console
  commands, regenerate-on-save.

## The two invariants

1. **`services\Generator::rows()` is the only place a product becomes feed rows.** The CP preview,
   the live route, the scheduled file write and `eat/feeds/generate` all iterate the same generator,
   so a preview is the feed — not a second implementation that drifts.
2. **`services\Delivery::deliver()` is the only place a generated file leaves the plugin.** File,
   volume, FTP, SFTP and Content API all land there, so every destination gets the same file, the
   same run record and the same failure handling.

## Shape

```
Feed (row in eat_feeds)
  ├─ channel      → channels\Registry definition (attributes, formats, taxonomy, vocabulary)
  ├─ filters      → ProductQuery + ProductCondition + plugin-level gates
  ├─ mappings     → [ {attribute, source, value, modifiers[], condition?} ]
  ├─ options      → currency, variant strategy, limits, UTM, CSV dialect, XML node names
  ├─ delivery     → mode + destination config
  └─ schedule     → interval seconds, nextGenerateAt, regenerateOnSave
```

Generation: `Generator::rows()` yields ordered `attribute => string` maps →
`formats\WriterInterface` streams them to a temp file → `Delivery::deliver()` puts the file where
it belongs → `Runs::record()` writes what happened.

### Data model

- `{{%eat_feeds}}` — feed definitions. Database, not project config (a feed is content-shaped
  configuration and merchants edit it on production); moved between environments with
  `eat/feeds/export|import`.
- `{{%eat_runs}}` — one row per generation attempt: status, item/skip counts, bytes, duration,
  output URL, delivery results, error.
- `{{%eat_taxonomy}}` — source key → channel taxonomy value, unique on `(channel, sourceType,
  sourceKey)`. Shared by every feed on that channel, because Google's taxonomy doesn't change per
  feed.

### Sources a mapping can pull from

`attribute` (32 product/variant attributes), `field` (any custom field handle, with a dotted path
for sub-values), `static`, `taxonomy` (the mapping table), `template` (Twig object template — Pro,
the escape hatch), `none`.

### Modifiers (Pro), chained in order

`strip_tags`, `truncate`, `prefix`, `suffix`, `replace`, `regex_replace`, `upper`, `lower`,
`ucfirst`, `number`, `multiply`, `default`, `utm`, `map`.

### Variant strategy

- `variant` — one row per variant, `item_group_id` set to the product (what Google wants).
- `default` — one row per product using its default variant.
- `product` — one row per product, prices from the default variant, no `item_group_id`.

## Channels

`google`, `google_local`, `meta` (Facebook/Instagram), `pinterest`, `bing` (Microsoft Shopping),
`tiktok`, `snapchat`, `criteo`, `awin`, `idealo`, `kelkoo`, `pricerunner`, `shopzilla`, `rakuten`,
`custom`.

Each declares its formats, root/item node names, XML namespaces, attribute list (key, label,
required, sensible default source), availability vocabulary and taxonomy attribute. Adding a
channel is a definition, never code.

## Delivery modes

- `file` — writes under a configurable directory in the web root, returns a stable public URL.
- `volume` — writes into a Craft asset volume filesystem (S3 and friends).
- `ftp` / `sftp` — uploads on every run (Pro).
- `merchant` — Google Content API v2.1 `products.custombatch` via a service-account JWT signed with
  `openssl_sign`; no Google SDK, no new dependency (Pro).

The live route `eat/feed/<handle>` serves the last generated file, or generates on demand when the
feed says so, cached for the feed's interval.

## Scheduling

`nextGenerateAt` is the ledger. Three ways it advances, all through `Feeds::queueDue()`:

1. `eat/feeds/generate --due` from real cron — the recommended setup.
2. An after-request hook (like Craft's own garbage collection), throttled by a cache key and
   guarded by a mutex, for sites without cron.
3. `regenerateOnSave` — a product save marks its feeds due, debounced so a bulk resave queues one
   job per feed, not one per product.

## Testing

`tests/integration/checks.php`, run in the plugin-testing harness. Fixture products across two
product types, a feed per format, real generation of every channel template, modifier chains,
filters, taxonomy mapping, delivery to disk, the run log, edition gating in both directions,
console commands and the Twig API. Self-cleaning in a `finally`.
