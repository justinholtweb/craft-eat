# Plugin Store promo images

Marketing images for the Eat listing on the Craft Plugin Store, rendered in the same theme as the
plugin's marketing page at
[justinholt.com/plugins/craft-eat](https://justinholt.com/plugins/craft-eat).

## Building

```bash
./build.sh          # all slides
./build.sh "2 5"    # just slides 2 and 5
```

Output lands in `out/` as `eat-promo-N.png`, 1920×1080 (rendered at 2× in headless Chrome, then
downsampled so the type stays crisp).

## Slides

| # | Slide | Shows |
|---|-------|-------|
| 1 | Cover — name, tagline, app icon, price badge | — |
| 2 | `templates/feeds/google.twig` → `/feeds/google.xml` | the whole pitch, in one line |
| 3 | Fifteen channel templates, plus the custom builder | how far it reaches |
| 4 | Five attribute rows, five different sources | what the map actually is |
| 5 | "In stock" in six channels' own words | the detail hand-written feeds get wrong |
| 6 | File, volume, FTP/SFTP, Merchant Center | delivery, and that the local file comes first |
| 7 | `eat/feeds/generate --due` in a terminal | the schedule, and what a run reports |
| 8 | The feeds index | every feed and the URL to paste into the channel |
| 9 | The attribute map | the screen the plugin is really about |
| 10 | A run detail with its skip reasons | the answer to "why isn't this product in the feed" |

Slides 8–10 are real screenshots from a Craft 5 install running Commerce, captured with
`plugin-shots/capture.mjs` and kept in `shots/`. Slide 9 and 10 use the `cpc-clip` fade, because
both screens are taller than the frame and the interesting rows are at the top.

## Notes

The **price on the cover** is the one figure here that a pricing change strands. It has to agree
with the page seed at `justinholt/scripts/seed/plugin-pages/craft-eat.json`, the README editions
table, `docs/installation.md`, `docs/faq.md`, and — the one that silently disagrees with all of
them — the edition prices at id.craftcms.com.

The **watermark is the icon with its tile stripped and every straight edge removed**. The tile goes
because at 1180px its edge is a hard rectangle laid across the artwork. The rim of the bowl and the
foot go for the same reason: at 5% opacity a 4-unit horizontal stroke 1000px wide reads as a grey
band across the slide, which is exactly what the first build produced. Only the curves survive, and
the bowl runs past the viewBox so nothing closes it back into a shape.

Slide 3 is the one to re-check after adding a channel: it names all fifteen, and the headline
counts them.
