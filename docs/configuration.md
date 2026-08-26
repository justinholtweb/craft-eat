---
title: Configuration
slug: configuration
order: 20
summary: Plugin settings, feed options, filters, delivery destinations and scheduling.
---

## Plugin settings

**Settings → Plugins → Eat.**

| Setting | What it does |
| --- | --- |
| Feed directory | Where files are written, relative to the web root. An absolute path or a Craft alias works too. Environment variables are parsed. |
| Default brand | Fills `brand` when nothing else does. Google and Meta both require it. Defaults to the system name. |
| Image transform | A named transform applied to every feed image, unless a feed overrides it. |
| Generate in the queue | Leave on. A catalogue of any size takes longer than a control panel request should. |
| Schedule from web requests | For sites without cron: after a front-end request, queue anything due. Throttled to once a minute and guarded by a mutex. |
| Batch size | Products read from the database at a time. 100 is a sensible default; lower it on very wide catalogues. |
| Enable live feed routes | Whether `/eat/feed/<handle>` responds at all. |
| Live feed cache duration | How long a generated-on-request feed is cached when the feed has no schedule. |
| Runs to keep | Per feed. 0 keeps everything. |

## Feed setup

**Channel** decides the attribute vocabulary, which attributes are required, the file shape and
the wording for things like availability. **Format** decides how those attributes are written:
RSS 2.0, plain XML, CSV, TSV, tab-separated TXT or JSON. A channel only offers the formats it
actually accepts.

Changing a saved feed's channel replaces its attribute map with the new channel's defaults, because
`g:image_link` means nothing on Awin. Mappings you had customised for the old channel do not
survive that switch — export the feed first if you want them back.

**Site** decides which products, URLs and prices are used. **Store** defaults to the site's store.

**Rows** decides what one row *is*:

- **One row per variant** — what Google wants. Variants of the same product are tied together with
  `item_group_id`.
- **One row per product, default variant** — for catalogues where variants are sizes nobody
  advertises separately.
- **One row per product, cheapest variant's price** — "from $19.99" feeds.

**Currency** overrides the store's currency code in the output; it does not convert. **Price
multiplier** multiplies every price — `1.2` to advertise a channel-specific markup.

**Link tagging** appends UTM parameters to every product URL, without clobbering parameters the URL
already has.

## Filters

Filters run before the attribute map, and every product they exclude is counted with a reason in
the run log.

| Filter | Notes |
| --- | --- |
| Product types | Leave everything unchecked to include every type. |
| Statuses | Live by default. |
| In stock only | Skips tracked variants with no stock. Consider leaving it off — most channels would rather have the product marked out of stock than missing. |
| Require an image | Skips products with no image anywhere. |
| Require a price | Skips anything priced at zero, which every channel rejects anyway. |
| Include disabled variants | Off by default. |
| Minimum / maximum price | Inclusive bounds. |
| Row limit | Useful while testing. |
| Exclude SKUs | One per line, `*` allowed: `SAMPLE-*`. |
| Exclude product IDs | One per line. |

Pro adds Craft's own **product condition builder** on top, so anything Craft can match — a field
value, a related element, a date — can decide what is in the feed.

## Delivery

| Mode | Notes |
| --- | --- |
| File | Written under the feed directory and served from a stable public URL. |
| Asset volume | Written into a Craft volume's filesystem, so S3 and friends work. |
| FTP / SFTP | Uploaded on every run. Use environment variables for credentials. |
| Google Merchant Center | Pushed with the Content API v2.1 in batches of 100. |

The local file is written first in every mode, so the URL keeps working even when a remote
destination is down, and you can look at exactly what was sent.

**Gzip the file** appends `.gz` and compresses it. Google, Meta and Microsoft all accept it, and a
40 MB feed becomes about 3 MB.

**Serve a live URL** makes `/eat/feed/<handle>` generate on request, cached for the feed's
interval. Good for small catalogues and for channels that fetch rarely; a poor idea for 50,000
products.

### Google Merchant Center credentials

1. In Google Cloud, create a service account and download its JSON key.
2. Give that service account access to your Merchant Center account (Settings → Users → Add user,
   using the service account's email).
3. Store the key **outside the web root** and point **Service account credentials** at the path, or
   put the JSON itself in an environment variable and use `$MY_VAR`.
4. Enter the Merchant Center account ID and press **Test the connection**.

## Scheduling

Pick an interval on the Schedule tab, then run this from cron:

```sh
*/15 * * * * cd /path/to/site && php craft eat/feeds/generate --due
```

Without cron, leave **Schedule from web requests** on in the plugin settings. Pro feeds can also
regenerate when a product is saved — queued and debounced, so resaving a whole section costs one
job per feed rather than one per product.

## Output options

| Option | Applies to |
| --- | --- |
| Write a header row | CSV, TSV, TXT |
| CSV delimiter and enclosure | CSV |
| Skip incomplete products | Every format. Off means the channel rejects the row instead of Eat omitting it. |
| Batch size | Every format |
| Root element / item element | XML |
| JSON wrapper | JSON |
| Feed title / link / description | RSS |
