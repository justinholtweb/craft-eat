# Eat — Craft CMS 5 Plugin

## Project Overview

Eat generates product feeds from Craft Commerce for merchant channels — Google Shopping, Meta,
Pinterest, Microsoft, TikTok and eleven more — and delivers them wherever the channel wants them.
Distributed as `justinholtweb/craft-eat`. **Lite (free) + Pro ($79).**

## Why it exists

WooCommerce merchants have WebAppick's *Product Feed Manager / CTX Feed*. Craft Commerce has
nothing equivalent: the state of the art is a hand-written Twig template per channel, and hoping
you remembered `g:availability`, `item_group_id` and the required-attribute list. Eat is the
template-driven version of that job — channel templates, an attribute map, catalogue filters,
scheduled regeneration, real delivery, and a run log that says what happened.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step: no asset bundles, no JS beyond inline `{% js %}` blocks
- No runtime dependencies. Merchant Center push signs its own service-account JWT with
  `openssl_sign` and talks to the REST API through Craft's Guzzle; `phpseclib` is *suggested*, only
  for SFTP delivery.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\eat`
- Package: `justinholtweb/craft-eat`
- Handle: `eat`

### The two invariants

1. **`services\Generator::rows()` is the only place a product becomes feed rows.** The CP preview,
   the live route, the scheduled write, the queue job, the console command and the Merchant API push
   all iterate that one generator. A preview that is not the feed is worse than no preview.
2. **`services\Delivery::deliver()` is the only place a generated file leaves the plugin.** File,
   volume, FTP, SFTP and Merchant Center all land there, so every destination produces the same
   file, the same run row and the same failure handling — and the local file is always written
   first, so a dead FTP server cannot take the public URL down with it.

### Data model

- `{{%eat_feeds}}` — feed definitions. Database, not project config: a feed is content-shaped
  configuration that merchants edit on production. `eat/feeds/export|import` moves them.
- `{{%eat_runs}}` — one row per generation attempt, pruned to `runsToKeep` per feed.
- `{{%eat_taxonomy}}` — unique on `(channel, sourceType, sourceKey)`. Shared by every feed on a
  channel, because Google's taxonomy does not change because you made a second Google feed.

### A channel is a definition, never code

`channels\Registry` returns plain arrays: formats, RSS-or-not, root/item element names, namespaces,
availability vocabulary, taxonomy attribute, and the attribute list with each attribute's default
source. Adding a channel is an entry in that file. `models\Channel::defaultMappings()` is what makes
a new feed useful the moment it is created.

`ns` on an attribute is load-bearing: RSS's own `title`, `link` and `description` must **not** be
written as `<g:title>`, and everything else must be.

### Twig template mappings are admin-written (5.0.1)

A template mapping renders unsandboxed (`View::renderObjectTemplate`) and its output is published,
so `FeedsController::_guardTemplates()` refuses a non-admin adding or changing one — on **save and
preview** (the preview renders posted mappings). Craft's sandbox was rejected: its default policy
blocks `Variant::sku`/`price`/`product`, i.e. everything a feed template is for. `_mappings()`
restores a stored template for a row posted with no `source` — a browser doesn't submit a disabled
`<option>`, and the select disables "Twig template" for non-admins and on Lite.

The live route rebuilds under the `eat:live:build:<id>` mutex; concurrent requests get
`eat:live:last:<id>` (the last good body) or a 503 + `Retry-After`.

### Editions

`Feeds::LITE_FEED_LIMIT` (2) and `Feeds::LITE_CHANNELS` (google, meta) gate creation;
`Generator`/`Resolver` skip modifiers, Twig-template sources and the product condition on Lite;
`Delivery` falls back to file delivery. Lite behaviour is exercised in its own section of the check
suite, in both directions.

### Merchant Center push is the Merchant API (5.1.0)

The Content API for Shopping v2.1 was sunset 2026-08-18 (410s from 2026-09-01). `services\Merchant`
now talks to `merchantapi.googleapis.com`: `productInputs:insert` one product per request through a
Guzzle `Pool` (no batch endpoint), into an **API data source** (`dataSource` setting, or found/created
by display name `Eat: <handle>`), after a one-time `developerRegistration:registerGcp` that needs the
service account to be an account admin. Registration failure is returned as a note, not thrown — the
project may already be registered by someone else. Prices are `amountMicros` computed on the decimal
string; enums are upper-case (`IN_STOCK`); `feedLabel` defaults to the target country so 5.0 offers
keep their identity. The harness has no outbound network, so `checks.php` tests the request handling
with Guzzle's `MockHandler`; nothing here has been run against a real Merchant Center account.

## Traps found while building this

- **`craft\helpers\FileHelper::isAbsolutePath()` does not exist** — neither Craft's nor Yii's
  FileHelper has an absolute-path test. `models\Settings::isAbsolute()` is ours.
- **`ElementInterface` has no `getTitle()`.** `title` is a property; `getUiLabel()` is the method
  that always answers. Calling `getTitle()` throws `UnknownMethodException` at runtime only.
- **A curly quote straight after an interpolated variable eats it**: `"handle “$handle”."`
  interpolates `$handle”`. Five of them shipped into this repo before the first console run caught
  one. Always brace: `{$handle}`. (Family-wide trap; see `[[craft-plugin-gotchas]]`.)
- **Craft console controllers write to `STDOUT` directly**, so `ob_start()` captures nothing. Test
  what a command *did* (exit code, run rows, files), not what it printed.
- **Counting rows in `{{%queue}}` proves nothing about your own jobs** — Craft pushes search-index
  and propagation jobs on every element save, and a two-site install doubles them. Filter on the
  job description.
- **`Product::getVariants(?bool $includeDisabled = null)` defaults by *controller*** — passing
  `null` means "enabled only, unless a nested-elements controller is running". Always pass a bool.
- **`Commerce::getInstance()->getSettings()->weightUnits`** is where weight/dimension units live in
  Commerce 5 — not on the store.
- **`fputcsv()`'s `$eol` parameter only exists from PHP 8.1**, which is why the writers can emit
  `\n` line endings without a post-pass.
- **A condition builder needs `mainTag = 'div'` and `name` set on every render**, or it nests a
  `<form>` inside the CP's page form and posts under the wrong key.

See `[[craft-plugin-gotchas]]` for family-wide traps, and `[[project_craft_freeride]]` /
`[[project_craft_shipper]]` for the sibling Commerce plugins whose conventions this follows.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-eat/tests/integration/checks.php                     # 112 checks
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-eat/tests/integration/security.php   # 8: template guard over HTTP, live-route lock
docker exec -w /sites/craft-eat ddev-phpstan-runner-web bash -c 'vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/ecs check'
bash ~/Sites/craft-eat/tests/smoke.sh   # 16 CP checks — from the HOST (the container can't reach the router's hostname)
ddev exec bash -c 'find /var/www/craft-eat/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

`checks.php` is idempotent and self-cleaning: fixture products, feeds, taxonomy rows and generated
files all go in a `finally`, and the plugin edition is restored. It switches to Pro for the bulk of
the run and exercises Lite in its own section. It includes live HTTP fetches of a generated feed
file and of the live route, so a green run means the URL a merchant pastes into Merchant Center
actually serves the feed.

`tests/smoke.sh` logs into the CP with a real session and walks every screen and action. It runs
from the **host** against `BASE` (the router's hostname); when the DDEV router is broken, point a
copy at the project's direct port from `ddev describe` (e.g. `:33001`).

**Harness note:** `craft-penny` registers an `Elements::EVENT_BEFORE_SAVE_ELEMENT` handler typed
`ModelEvent` while Craft passes an `ElementEvent`, so every element save in the harness fatals while
it is enabled. `checks.php` detaches that handler in-process (never persisted). That is a bug in
Penny, not in Eat.

## Coding conventions

- `Craft::t('eat', '…')` for user-facing strings; `src/translations/en/eat.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Feed values are merchant content: the CP preview writes them with `textContent`, never as markup
- Generation is always batched and streamed — a 200,000-product catalogue must cost the same memory
  as a 20-product one
