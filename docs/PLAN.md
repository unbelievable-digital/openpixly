# Openpixly — Implementation Plan

Source of truth: https://developers.openai.com/ads/measurement-pixel (+ `supported-events`, `conversions-api`, `image-tag`, `multiple-pixels`). Everything below maps directly to those docs.

## What the docs tell us

| Fact | Consequence for the plugin |
|---|---|
| Loader is a fixed snippet from `https://bzrcdn.openai.com/sdk/oaiq.min.js`, queue global is `window.oaiq`. | We ship the official loader ourselves. Site owners only enter a Pixel ID; no snippet pasting. |
| `oaiq("init", { pixelId, debug, user })`. Multiple pixels = call `init` once per ID; `measureSingle` targets one. | Pixel ID field accepts a comma-separated list. `debug` is a settings toggle. |
| SDK does **not** auto-fire `page_viewed`. | Plugin fires `page_viewed` on every front-end page load. |
| `oaiq("measure", eventName, data, options)`; `data.type` must match the event's shape (`contents`, `customer_action`, `plan_enrollment`, `custom`). | A small PHP event model validates shape before it ever reaches JS. |
| `amount` is an **integer in ISO 4217 minor units** and requires `currency`. | Money helper converts WooCommerce decimal prices using the currency's real exponent (JPY=0, KWD=3, most=2), not the store's display decimals. |
| `contents[]` items only allow `id, name, content_type, quantity, amount, currency` on the Pixel (`group_id`, `variant_dict` are Conversions API only). | Content builder strips anything else; CAPI builder may add `group_id` (parent product) and `variant_dict` (attributes). |
| Consent defaults to `true`; `oaiq("consent", false)` before `init` blocks pings; blocked events are not replayed. | Optional "require consent" mode that emits `consent(false)` first and exposes `window.openPixel.grantConsent()` + WP Consent API integration. |
| `user` object on `init` improves matching: SHA-256 of normalized email/phone/external_id/first/last name + raw country/city/region/postal_code. Strict normalization rules. | PHP hashing helper implements the exact normalization rules from the docs. Populated from the logged-in user / order billing details. |
| Dedup key = Pixel ID + event name + `event_id`; first event wins. | Deterministic IDs: `order_{id}` for purchases, `checkout_{cart_hash}` for checkout, `reg_{user_id}` for registration. Same ID reused by the server-side event. |
| Conversions API: `POST https://bzr.openai.com/v1/events?pid=<PIXEL-ID>`, `Authorization: Bearer <KEY>`, ≤1000 events/batch, `timestamp_ms` within 7 days, `source_url` required for web, `oppref` not auto-captured, `obref` from `__obref` cookie goes inside `user`. Docs: "more reliable than the pixel alone, use when possible". | Server-side `order_created` on payment complete with the same `order_{id}`; capture `__oppref`/`__obref` cookies at checkout and persist on the order; ship IP + user agent from the order. `validate_only` used for the admin "test" button. |
| Image tag `https://bzr.openai.com/v1/sdk/events?pid=&event=&data[type]=` for no-JS fallback. | Optional `<noscript>` `page_viewed` image tag. |
| CSP needs `script-src bzrcdn.openai.com`, `connect-src bzr.openai.com bzrcdn.openai.com`, `img-src bzr.openai.com`. | Documented in README; filter to add a nonce to the inline snippet. |

## Architecture (keeps the "any pixel later" goal)

```
WordPress / WooCommerce hooks
        │
        ▼
OpenPixel_Event_Bus  ── normalized events (view_item, add_to_cart, begin_checkout,
        │           purchase, registration, page_view) with money in minor units
        │
        ├──► OpenPixel_Provider_OpenAI  → oaiq("measure", ...) in the page + Conversions API
        ├──► OpenPixel_Provider_Meta    → fbq("track", ...)  + Meta Conversions API
        └──► (later) Google provider→ gtag("event", ...)
```

The WooCommerce integration is written **once** against the bus; every provider translates the normalized event into its own API. That is what makes adding Meta/Google a one-class job later.

Events that happen on requests that render no page (AJAX add-to-cart, registration POST + redirect) are queued in the WooCommerce session / a short-lived user transient and flushed into the next page footer, and also pushed through `woocommerce_add_to_cart_fragments` so classic AJAX add-to-cart fires immediately.

## Phase 1 — Native pixel + WooCommerce browser events (this release)

- [x] Read all docs.
- [x] Replace the paste-a-snippet provider with a native OpenAI provider: Pixel ID(s), debug, consent mode, exclude admins, `<noscript>` image tag.
- [x] Loader in `<head>` at priority 1 (docs: "near the top of `<head>`").
- [x] `page_viewed` on every front-end page, `contents` type, page id/title as content.
- [x] Event bus + money + hashing helpers.
- [x] WooCommerce integration (only loads when WC is active):
  - `contents_viewed` on single product pages.
  - `items_added` on `woocommerce_add_to_cart` (classic, AJAX, Store API/blocks) with product, quantity, line amount.
  - `checkout_started` on the checkout page, `event_id = checkout_{cart_hash}`.
  - `order_created` on the order-received page, `event_id = order_{id}`, fired once per order (order meta flag), with hashed billing data on `init`.
  - `registration_completed` on `user_register` (covers `woocommerce_created_customer`).
- [x] Declarative settings fields per provider so the admin page renders any future provider's fields without edits.

## Phase 2 — Conversions API (server-side)

- [x] API key setting (stored via WP options; never printed to the page).
- [x] Capture `__oppref` / `__obref` cookies during checkout → order meta.
- [x] `order_created` sent on `woocommerce_payment_complete` / first `processing|completed` status, same `order_{id}` id, `action_source=web`, `source_url` = order-received URL, `user` = hashed billing + IP + UA + `obref`, `contents[]` with `group_id` / `variant_dict`.
- [x] Async delivery through Action Scheduler (ships with WooCommerce) with retry; WC logger output under source `openpixly`.
- [x] Admin "Send test event" using `validate_only: true`.
- [ ] `registration_completed` and `lead_created` server-side where a browser event may be lost.
- [ ] Verify on a real store with a real Pixel ID + API key (debug mode + WooCommerce logs).

## Phase 2b — Product feed (shipped in 1.2.0)

Source: https://developers.openai.com/ads/product-feeds + https://developers.openai.com/commerce/specs/file-upload/products + https://developers.openai.com/ads/delta-feeds

| Fact | Consequence |
|---|---|
| Ads catalogs are Google-compatible product feeds uploaded via SFTP from Ads Manager > Feeds; no public upload API. | Plugin generates the file; site owner uploads it or gives OpenAI the private URL. |
| Two schemas: OpenAI format (`item_id`, `url`, `image_url`, `seller_name`, `is_ads_eligible`) and Google-compatible (`id`, `link`, `image_link`, `item_group_id`). | Schema selector; OpenAI format default. |
| Required: item_id, title, description, url, brand, seller_name, image_url, availability, price. Money as `79.99 USD`. Variants: same `group_id`, `listing_has_variations=true`, `variant_dict`. | Row builder enforces required fields, skips + counts incomplete products; variations become rows. |
| CSV/TSV/JSONL, UTF-8, JSON inside cells, booleans `true`/`false`, empty = no value. | `OpenPixel_Feed_Writer`. |
| Delta Feeds API `PATCH /feeds/{id}/products` updates price/availability/title per variant with the Ads API key; access enabled per account. | Not built yet — see Phase 3. |

- [x] Feed settings tab, schema + format selection, seller/brand defaults, schedule.
- [x] Batched build (inline + Action Scheduler), private tokenized URL, download, rotate.
- [x] `openpixel_feed_row` / `openpixel_feed_columns` / `openpixel_feed_built` hooks.
- [ ] Verify against a real Ads Manager feed upload (SFTP) and Upload History.

## Phase 3 — More providers & sources

- [ ] Delta Feeds sync: Ads API key + feed id settings; on product price/stock change queue `PATCH /feeds/{feed_id}/products` (minor-unit amounts, `availability.status`), with 403 `product_feed_delta_api_disabled` handling.
- [ ] Product-feed campaign helper: create `mode: product_feed` campaign, ad group with `product_set` filters and a `product_ad_template` from the admin (Ads API), and show product-segmented insights.
- [ ] `ads_metadata` / `custom_label_0..4` mapping from product tags or attributes for product-set filters.
- [ ] Feed health: per-row validation report (missing brand/image/GTIN), row count trend, last fetch time by OpenAI (User-Agent log on the feed endpoint).

- [x] Meta Pixel + Conversions API provider (1.3.0): fbq loader, hashed advanced matching, consent revoke/grant, `eventID` dedup with server `Purchase`, `_fbp`/`_fbc` captured at checkout, test event code.
- [x] Meta catalog feed (1.3.0): second CSV in the same build pass, `/openpixly-feed/<token>/meta-catalog.csv`, ids = pixel `content_ids`.
- [ ] Verify Meta on a real store: Events Manager > Test events (browser + server dedup), catalog scheduled-feed fetch, catalog match rate.
- [ ] Google Ads / GA4 provider, TikTok — each a single class on the bus.
- [ ] WooCommerce Subscriptions → `subscription_created` / `trial_started` (`plan_enrollment`, `plan_id` = product id).
- [ ] Lead forms (Contact Form 7, WPForms, Gravity Forms) → `lead_created`.
- [ ] WP Consent API / common CMP integrations for consent mode.
- [ ] Per-event enable/disable UI and event debugger panel.

## Non-goals

- No calling the Conversions API from the browser (explicitly forbidden by docs).
- No raw PII in any event or image-tag URL.
