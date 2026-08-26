---
title: Installation
slug: installation
order: 10
summary: Requirements, install, editions, and your first feed in five minutes.
---

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later

Optional, and only for the delivery modes that need them:

- PHP's `ftp` extension — FTP delivery
- `phpseclib/phpseclib` `^3.0` — SFTP delivery
- `ext-zlib` — gzipped feeds (present in almost every PHP build)

Nothing else. Eat has no runtime dependencies: the Google Merchant Center integration signs its own
service-account JWT with `openssl_sign` and talks to the REST API through Craft's own HTTP client.

## Install

```sh
composer require justinholtweb/craft-eat
php craft plugin/install eat
```

Or find **Eat** in the Craft Plugin Store and install it from there.

## Editions

| | Lite | Pro |
| --- | --- | --- |
| Price | Free | $79, $59/year renewal |
| Feeds | 2 | Unlimited |
| Channels | Google, Meta | All fifteen, plus the custom builder |
| Formats | RSS, XML, CSV, TSV, TXT, JSON, gzip | Same |
| Attribute sources | Product attributes, custom fields, static values, taxonomy map | Adds Twig templates |
| Output modifiers | — | Fourteen, chainable |
| Product matching | Filters | Filters **and** Craft's condition builder |
| Delivery | File, with a public URL | Adds asset volumes, FTP, SFTP, Google Merchant Center |
| Live feed routes | — | Yes |
| Run log | Last run | Full history, per destination |
| Console commands | — | Yes |
| Regenerate on product save | — | Yes |

Lite is not a trial. Two feeds on the two channels most merchants start with, generated on a
schedule and served from a real URL, is a complete workflow — it just stops before the thirteenth
channel and the automation.

Switch editions in **Settings → Plugins → Eat**.

## Your first feed

1. **Eat → Feeds → New feed.** Name it "Google", pick the **Google Shopping** channel and the
   **RSS 2.0** format.
2. **Products.** Tick the product types you sell. Leave *Require a price* on.
3. **Attributes.** Google's attributes are already listed and mapped. The ones Google insists on
   are named at the top of the Setup tab; anything still empty needs a source — usually `brand`
   and `description`.
4. **Save**, then **Generate now**.
5. Copy the **Feed URL** from the sidebar into Merchant Center as a scheduled fetch.

Hit **Preview** at any point. It runs the same generator the real feed does, over the same
filters, and shows you both the resolved rows and the bytes that would be written — so what you
see is what the channel gets.

## Where the file goes

By default, `web/feeds/<handle>.xml`, served from `https://yoursite.com/feeds/<handle>.xml`.
Change the directory in **Settings → Plugins → Eat**; the settings screen shows the resolved path
and URL so there is no guessing.

The file is written to a staging name and renamed into place, so a channel fetching the URL while
a run is in progress gets the previous feed rather than half of the new one.

## Upgrading from a hand-written Twig feed

Point Eat at the same products, map the attributes your template filled, and compare the two files
before you change the URL in Merchant Center. `php craft eat/feeds/preview <handle>` prints the
resolved values one row at a time, which is the fastest way to find the attribute you had quietly
hard-coded.
