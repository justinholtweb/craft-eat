---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Empty feeds, rejected products, delivery failures, schedules that never fire, and how to read a run.
---

## Read the run first

**Eat → Runs** records every generation attempt: how many products were written, how many were
skipped and why, the byte size, how long it took, and what happened at each destination. Almost
every question below is answered on that screen before it is answered anywhere else.

## The feed is empty, or much smaller than the catalogue

Open the run and look at the skip reasons.

| Reason | Means |
| --- | --- |
| `missing:<attribute>` | The channel requires that attribute and the row had no value for it. |
| `no-price` | Priced at zero, and *Require a price* is on. |
| `no-image` | *Require an image* is on and no Assets field on the variant or product held one. |
| `out-of-stock` | *In stock only* is on and the variant is tracked with no stock. |
| `excluded-sku` | Matched an exclude pattern. |
| `below-min-price` / `above-max-price` | Outside the price bounds. |
| `no-variant` | The product has no variant that the row strategy could use — usually every variant is disabled. |

`missing:image_link` and `missing:brand` are the two that catch most people. Images have to live in
an Assets field on the variant or the product; `brand` falls back to the **Default brand** setting,
so set it once and the whole family of Google-shaped channels stops complaining.

If nothing is skipped and there are still no rows, check the **Products** tab: a status of *Live*
with no matching product types produces an empty query, and so does a Pro condition that no product
satisfies.

## Merchant Center rejects products the feed contains

- **"Missing value: link"** — the product type has no URL format, so `url` resolves to nothing.
  Give the product type a URI in Craft, or map `link` to a Twig template that builds one.
- **"Invalid value: price"** — the channel wants a currency code. Map `price` to
  `priceWithCurrency`, not `price`.
- **"Invalid value: availability"** — the mapping is pointing at `inStock` or `stock` rather than
  `availability`, which is the one that writes the channel's own wording.
- **"Duplicate offer id"** — two variants share a SKU, or the row strategy is per variant while
  `id` is mapped to something product-level.
- **"Missing item_group_id"** — Google wants it on every variant of a multi-variant product. It is
  mapped by default; check the row is switched on.

## The file is there but the channel can't fetch it

- Fetch it yourself first: `curl -I https://yoursite.com/feeds/<handle>.xml`.
- If the site is behind basic auth or an IP allowlist in staging, the channel is being refused, not
  the file.
- If the feed directory is outside the web root, the plugin settings screen says so — the resolved
  URL is printed under the field.

## FTP or SFTP delivery fails

The run status is **partial**, not error: the local file was still written, and the message names
the destination that failed. Common causes:

- SFTP without `phpseclib/phpseclib` installed — the message says exactly that.
- FTP without PHP's `ftp` extension.
- A remote path that is a directory but does not end in `/`. Eat treats a path with no extension in
  its last segment as a directory, but an explicit trailing slash removes the ambiguity.
- Credentials stored literally rather than as environment variables. They work either way, but the
  literal ones are in the database and in every project-config export.

## Merchant Center push fails

- **"Google refused the credentials"** — the service account exists but has not been added as a
  user on the Merchant Center account.
- **"The service account file could not be read"** — the path is relative to nothing useful. Use an
  absolute path, a Craft alias, or an environment variable containing the JSON itself.
- **Products rejected** — the run details list the first ten messages Google returned, verbatim.

## The schedule never fires

Feeds are due, not scheduled: a feed with an interval has a *next generate at*, and something has
to come along and notice.

- With cron, that something is `php craft eat/feeds/generate --due`. Check the cron entry runs at
  all, and that it runs as a user who can write the feed directory.
- Without cron, it is the after-request hook, which needs **Schedule from web requests** on in the
  plugin settings and a front-end request to happen. It is throttled to once a minute, and it does
  nothing on control-panel or action requests.
- Either way the work is queued, so the queue has to run. `php craft queue/info` will tell you if
  jobs are piling up.

The schedule advances whether a run succeeded or failed, deliberately: a feed that fails every time
must not turn into a queue job every second.

## Regenerate-on-save doesn't seem to fire

It is debounced — the first product save queues a job, and further saves within a few minutes do
not queue a second one. That is what stops a bulk resave from creating thousands of jobs. It is
also Pro-only, and it ignores drafts and revisions.

## Preview shows a value the file doesn't have

They are the same generator, so this is nearly always the row limit: preview generates five rows,
and the product you are looking at is not one of them. `php craft eat/feeds/preview <handle>
--limit=50` widens it.

## Prices look wrong

- A **price multiplier** is set on the Setup tab.
- The **currency** field overrides the code written into the file; it does not convert money.
- Commerce's catalog pricing rules apply to `price`. `basePrice` is the value before them.

## Feeds vanished after a deploy

Feeds live in the database, not project config, so they do not travel with a deploy. Move them
deliberately:

```sh
php craft eat/feeds/export --file=feeds.json     # on the source environment
php craft eat/feeds/import feeds.json            # on the target
```

Importing over a matching handle updates that feed rather than creating a second one.
