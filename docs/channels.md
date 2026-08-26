---
title: Channels & formats
slug: channels
order: 35
summary: The fifteen built-in channel templates, what each expects, and how to build one that isn't here.
---

## The channels

| Channel | Default format | Identifier | Notes |
| --- | --- | --- | --- |
| Google Shopping | RSS 2.0 | `id` | Merchant Center primary feed, `g:` namespace |
| Google Local Inventory | CSV | `id` | Per-store availability and price; needs `store_code` |
| Meta (Facebook & Instagram) | CSV | `id` | Commerce Manager catalog |
| Pinterest | RSS 2.0 | `id` | Catalogs data source |
| Microsoft (Bing) Shopping | TSV | `id` | Microsoft Merchant Center |
| TikTok | CSV | `sku_id` | TikTok Shopping catalog |
| Snapchat | CSV | `id` | Snapchat Catalogs |
| Criteo | XML | `id` | `<products><product>`, `instock` as `true`/`false` |
| Awin | CSV | `merchant_product_id` | Affiliate datafeed columns |
| idealo | CSV | `id` | German vocabulary out of the box |
| Kelkoo | XML | `offer-id` | Hyphenated element names |
| PriceRunner | TSV | `SKU` | Title-case column names |
| Shopzilla / Connexity | TSV | `Unique ID` | |
| Rakuten Advertising | TSV | `SKU` | |
| Custom | CSV | yours | Name every column or element yourself |

Every channel accepts every format Eat can write, except where the channel genuinely does not
support it — Criteo and the comparison engines have no RSS shape, so RSS is not offered for them.

## What a channel template gives you

- **Its attribute names**, in its own spelling — `merchant_product_id`, not `id`.
- **Its required set**, so a product missing one can be skipped and counted rather than rejected
  later by the channel.
- **Its default mapping**, so a new feed is useful the moment it is created.
- **Its file shape** — RSS 2.0 with a `<channel>` wrapper, or a bare document with its own root and
  item element names.
- **Its namespace rules.** In an RSS feed, `title`, `link` and `description` are RSS's own elements
  and are written bare; everything else is written in the channel's namespace. Google rejects
  `<g:title>`, and it is exactly the kind of mistake a hand-written template makes.
- **Its vocabulary.** `in_stock` for Google, `in stock` for Meta and Pinterest, `true` for Criteo,
  `1` for Awin, `In Stock` for PriceRunner, `auf Lager` for idealo.

## Formats

| Format | Notes |
| --- | --- |
| RSS 2.0 | `<rss><channel><item>` with the channel's namespace bound. Feed title, link and description are configurable. |
| XML | A bare document. Root and item element names default to the channel's and can be overridden. |
| CSV | Configurable delimiter and enclosure, optional header row. |
| TSV / TXT | Tab separated. Tabs and newlines inside values are flattened to spaces rather than quoted, because that is what breaks line-by-line parsers. |
| JSON | Streamed, optionally wrapped in a named key. |

Any of them can be gzipped, which appends `.gz` and sets the right content type on the live route.

Values containing markup are written as CDATA in XML, and a value containing `]]>` is split across
two sections so it cannot end the block early and corrupt the document. Attribute names that are
not legal XML element names — PriceRunner's `Product name`, a custom feed's `9lives` — are
sanitised deterministically.

## Building a channel that isn't here

Choose the **Custom** channel. It starts with four rows and no required attributes; add one row per
column or element you need, name it exactly as the channel's specification does, and pick a source.
For XML output, set the root and item element names on the Schedule tab.

That covers most "our affiliate network has its own spreadsheet" cases without waiting for a
plugin update. If a channel is common enough to deserve a template of its own, ask — a channel is
a definition in one file, not code.

## Large catalogues

Generation reads products in batches and streams them straight to the file, so memory is flat
whether the catalogue has 200 products or 200,000. Two knobs matter at the top end:

- **Batch size** — how many products are read at a time.
- **Generate in the queue** — leave it on, so the work happens in a queue worker rather than in a
  control panel request.

Gzip is worth turning on once a feed passes a few megabytes; every major channel accepts it.
