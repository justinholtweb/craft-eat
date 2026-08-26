---
title: Attributes & mapping
slug: usage
order: 30
summary: Where every value comes from — product attributes, custom fields, static values, the taxonomy map, Twig — and the modifiers that clean them up.
---

## The attribute map

Each row of the map fills one of the channel's attributes. The order of the rows is the order of
the CSV columns and of the XML elements, and the switch on the right decides whether the attribute
is written at all.

| Column | Holds |
| --- | --- |
| Attribute | The channel's name for it — `image_link`, `merchant_product_id`, `Product URL` |
| Source | Where the value comes from |
| Value | What the source needs |
| Modifiers | An optional cleanup chain (Pro) |
| On | Whether to write it |

A new feed arrives with the channel's attributes already mapped to the Commerce data they almost
certainly mean, so the work is filling the handful your catalogue models differently.

## Sources

| Source | Value column holds |
| --- | --- |
| Product attribute | One of the names below, e.g. `salePriceWithCurrency` |
| Custom field | A field handle, optionally with a path: `gallery.0.url` |
| Static value | The literal text, written in every row |
| Taxonomy map | `productType`, or a field handle to map from |
| Twig template (Pro) | `{{ object.product.myField }}` — `object` is the variant |

Variant fields win over product fields, so a variant-level override does what you would expect.
A custom field that holds elements resolves to their titles; an Assets field resolves to URLs; a
Money field to its amount.

## Product attributes

**Identity** — `sku` `id` `productId` `variantId` `uid` `itemGroupId` `slug`

**Text** — `title` `variantTitle` `fullTitle` `description` `plainDescription` `productType`
`productTypeName` `brand` `condition` `status` `siteName` `storeName`

**Links and images** — `url` `imageUrl` `additionalImageUrls` `allImageUrls` `imageCount`

**Money** — `price` `basePrice` `promotionalPrice` `salePrice` `priceWithCurrency`
`promotionalPriceWithCurrency` `salePriceWithCurrency` `currency`

**Stock** — `availability` `stock` `inStock` `minQty` `maxQty` `variantCount`

**Shipping** — `weight` `weightWithUnit` `length` `width` `height` `dimensions` `freeShipping`
`shippingCategory` `taxCategory`

**Dates** — `dateCreated` `dateUpdated` `postDate` `expiryDate`

A few of these are worth knowing in detail:

- **`price` is the catalogue price** after Commerce's catalog pricing rules; `basePrice` is before
  them. `promotionalPrice` is empty unless the variant is actually on promotion, which is exactly
  what `sale_price` wants — a channel treats a `sale_price` equal to `price` as a mistake.
- **`availability` is written in the channel's own wording.** Google says `in_stock`, Meta says
  `in stock`, Criteo says `true`, idealo says `auf Lager`. The same mapping produces all of them.
- **`description` is looked for** in the first non-empty field called `description`,
  `productDescription`, `shortDescription`, `summary`, `excerpt`, `body` or `bodyContent`, and
  falls back to the title. `plainDescription` is the same value with HTML stripped and whitespace
  collapsed. If your field is called something else, map it explicitly.
- **`imageUrl` is the first asset** in the first Assets field on the variant, then on the product;
  `additionalImageUrls` is everything after it. Both honour the feed's image transform.
- **`itemGroupId` is the product ID**, which is what makes variants of one product group together
  in Google Shopping.

## Modifiers (Pro)

Modifiers run in order, after the value is resolved and before it is written. Chain them with `|`,
and pass arguments after `:`

```
strip_tags | collapse_whitespace | truncate:150:…
replace:Ltd:Limited
regex_replace:/\s*\(refurb\)$/:
map:red=Rot|blue=Blau
multiply:1.2 | number:2
default:Unbranded
```

| Modifier | Arguments |
| --- | --- |
| `strip_tags` | — |
| `decode_entities` | — |
| `collapse_whitespace` | — |
| `truncate` | length, optional suffix |
| `prefix` / `suffix` | the text |
| `replace` | find, replace |
| `regex_replace` | pattern, replacement |
| `upper` / `lower` / `ucfirst` | — |
| `number` | decimal places |
| `multiply` | factor |
| `map` | `from=to` pairs, separated by `|` or newlines |
| `default` | the fallback, used only when the value is empty |

Two behaviours worth relying on: `prefix` and `suffix` leave an empty value empty rather than
writing a lone prefix, and a malformed regex passes the value through unchanged instead of
emptying that column in every row.

On a repeated value such as `additionalImageUrls`, modifiers apply to each item — except
`default`, which applies to the list as a whole.

## Taxonomy mapping

**Eat → Taxonomy** maps each product type onto the channel's category string. The map belongs to
the channel, not the feed, because Google's taxonomy does not change because you made a second
Google feed.

```
T-Shirts   →  Apparel & Accessories > Clothing > Shirts & Tops
Mugs       →  Home & Garden > Kitchen & Dining > Tableware > Drinkware > Mugs
```

Map from something other than the product type by putting a field handle in the mapping's Value
column instead of `productType`.

## Required attributes and incomplete products

Each channel declares which attributes it insists on. With **Skip incomplete products** on (the
default), a row missing one of them is left out and counted in the run log with the attribute
named — `missing:image_link`, and the number of products it happened to. That number is usually
the most useful thing on the screen: it is the difference between "my feed is fine" and "Merchant
Center rejected 400 items and I don't know why".

Turn it off if you would rather send everything and let the channel do the complaining.

## Previewing

The **Preview** button on the feed screen and `php craft eat/feeds/preview <handle>` both run the
same generator the real feed does. The preview shows resolved values per row, the skip reasons,
and the first 200 KB of the actual file.
