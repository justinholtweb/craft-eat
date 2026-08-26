---
title: FAQ
slug: faq
order: 50
summary: Cost, what it replaces, big catalogues, other channels, and what it does not do.
---

## Is Eat free?

Lite is, and it is not a trial: two feeds on the Google and Meta channels, every output format,
scheduled regeneration and a public feed URL, for nothing. Pro is a one-off **$79** with a
**$59/year** renewal, and adds the other thirteen channels and the custom builder, output
modifiers, condition-builder matching, the run log, live URLs, asset volume / FTP / SFTP delivery,
Merchant Center push and the console commands.

## Why not just write a Twig template?

Plenty of people do, and for one channel and a simple catalogue it works. What it stops being is
cheap the moment there are three channels, because each has its own attribute names, its own
required set, its own word for "in stock", and its own opinion about namespaces. Add a scheduled
file, gzip, a category taxonomy, "why did Merchant Center reject 400 items", and a template that
renders 50,000 products without running out of memory, and you have written this plugin.

## Does it change anything about my products?

No. Eat only reads. It writes files, and — if you ask it to — sends them to a channel.

## How large a catalogue can it handle?

Products are read in batches and streamed straight to the file, so memory is flat regardless of
size. The practical limits are how long your queue worker is allowed to run and how patient the
channel is. Turn gzip on above a few megabytes.

## Can it feed a channel that isn't in the list?

Yes — the **Custom** channel lets you name every column or element yourself, in any of the six
formats. That covers most affiliate networks and in-house systems. If a channel is common enough to
deserve its own template, ask: a channel is a definition in one file, not code.

## Does it convert currencies?

No. The currency field changes the code written into the file, not the amount. Use Commerce's own
store and currency setup for genuine multi-currency, and give each store its own feed.

## Does it support multiple sites and stores?

Yes. A feed belongs to a site, and takes its products, URLs and prices from that site's store —
so a feed per market is a feed per site.

## Do I need a Google Cloud project?

Only for Content API push. The ordinary way to use Merchant Center — giving it a URL and letting it
fetch on a schedule — needs nothing but the feed URL.

## What happens if a channel fetches the file mid-run?

It gets the previous feed. The new one is written to a staging file and renamed into place, which
is atomic on every filesystem Craft runs on.

## What if the FTP server is down?

The run is recorded as **partial**: the local file was written and the URL still works, and the run
log names the destination that failed and why. Eat never lets a remote failure take the public feed
down with it.

## Does it work without Commerce?

It installs, but it has nothing to feed on. Every screen says so rather than erroring.

## Will it slow down my site?

Generation happens in the queue. The only thing Eat does in a front-end request is, optionally,
notice that a feed is due and push a job — one cache read, throttled to once a minute, and only if
you have no cron.

## Which versions are supported?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.
