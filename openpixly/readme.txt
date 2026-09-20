=== Openpixly – Conversion Tracking & Product Feed for OpenAI Ads ===
Contributors: zgrkaralar, unbelievabledigital
Tags: openai, chatgpt ads, meta pixel, conversion tracking, woocommerce
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conversion pixel manager for WooCommerce: OpenAI (ChatGPT Ads) and Meta pixels, their Conversions APIs, and product feeds for both catalogs.

== Description ==

Openpixly installs the official ChatGPT Ads Measurement Pixel on every
page and sends the standard conversion events for you:

* `page_viewed` on every page.
* `contents_viewed` on WooCommerce product pages.
* `items_added` on add to cart (classic, AJAX and block/Store API carts).
* `checkout_started` on the checkout page.
* `order_created` on the order-received page, and again from the server
  through the Conversions API with the same event ID so OpenAI deduplicates it.
* `registration_completed` when a user or customer registers.

Amounts are sent as integers in the currency's ISO 4217 minor unit, customer
identifiers are normalized and SHA-256 hashed on the server before they reach
the browser, and a `<noscript>` image tag covers visitors without JavaScript.

= Meta (Facebook & Instagram) pixel =

Enable the Meta provider and enter your Pixel ID to send the same events to
Meta: `PageView`, `ViewContent`, `AddToCart`, `InitiateCheckout`, `Purchase`
and `CompleteRegistration`, with `content_ids` / `contents` that match the
Meta catalog feed. Advanced matching data is normalized and SHA-256 hashed on
the server. With a Conversions API access token, `Purchase` is also sent from
the server with the same event ID, the `_fbp` / `_fbc` cookies captured at
checkout, and the customer's IP address and user agent, so Meta deduplicates
browser and server events. Each provider has its own "Track WooCommerce
events" switch.

= Consent =

Choose "Require consent first" to initialize the pixel with consent set to
`false`. Grant consent from your cookie banner with `window.openPixel.grantConsent()`;
the WP Consent API "marketing" category is detected automatically.

= Conversions API =

Enable it and paste the API key from the Conversions tab of Ads Manager (for
Meta: the access token from Events Manager). Orders are delivered
asynchronously with retries and logged under WooCommerce > Status > Logs
(source: openpixly). The OpenAI "Send test event" button validates your
credentials without recording anything; the Meta one sends the event with
your test event code so it only shows under Events Manager > Test events.

= Product feed for ChatGPT Ads =

The Product feed tab builds a catalog file from your WooCommerce products in
the OpenAI product feed format (or the Google-compatible profile) as CSV, TSV
or JSONL: one row per simple product or variation, with group_id and
variant_dict for variants, prices as "79.99 USD", availability, images,
brand, category, GTIN/MPN and is_ads_eligible. The file is rebuilt on a
schedule and served at a private tokenized URL you can hand to OpenAI, or
downloaded for upload to the SFTP location shown in Ads Manager > Feeds.

= Meta catalog feed =

Switch on "Also build a Meta catalog feed" and the same build writes a second
CSV in Meta's catalog format (id, title, description, availability, condition,
price, link, image_link, brand, sale_price, item_group_id, ...), served at its
own private URL. Paste it in Commerce Manager > Catalog > Data sources > Data
feed > Scheduled feed. Item ids equal the pixel's content_ids, which is what
Advantage+ catalog ads need.

= Extensible =

The plugin is a small pixel manager: integrations emit normalized events on
a bus and each provider maps them to its own API. Additional providers
(Google Ads, TikTok, ...) can be registered with the
`openpixel_pixel_providers` filter.

== Installation ==

1. Upload the `openpixly` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to Settings > Pixel Manager, enter the Pixel ID of the provider(s) you use and enable them.

== External services ==

This plugin is an integration with OpenAI's ChatGPT Ads measurement services and, optionally, Meta's (Facebook & Instagram) measurement services. Openpixly is developed by Unbelievable Digital and is not affiliated with or endorsed by OpenAI or Meta. Nothing is loaded or sent to a service until a site administrator enables that provider and enters its Pixel ID.

**OpenAI Measurement Pixel (browser SDK)** — https://bzrcdn.openai.com/sdk/oaiq.min.js and https://bzrcdn.openai.com/pixel-config/ — loaded on every front-end page while the provider is enabled, to measure ad conversions. The SDK sends measurement events to https://bzr.openai.com: page views (page id and title), product views, add-to-cart, checkout and purchase events (WooCommerce product ids, names, quantities and amounts), and registrations. The SDK reads and sets first-party cookies (`__oppref`, `__obref`) for click attribution and may collect the visitor's IP address and user agent as part of the request. When "Send hashed customer data" is on, the plugin also passes SHA-256 hashes of the logged-in user's or the order's billing email, phone, first and last name, and the plain-text billing country, city, region and postal code, to improve conversion matching. Raw email, phone or names are never sent.

**OpenAI image tag (no-JavaScript fallback)** — https://bzr.openai.com/v1/sdk/events — when enabled, a 1x1 image records a page view for visitors without JavaScript. It carries only the Pixel ID and event name.

**OpenAI Conversions API (server-to-server)** — https://bzr.openai.com/v1/events — only when enabled with your Conversions API key. When an order is paid, the plugin sends the order id, total, currency and line items (product ids, names, quantities, amounts, variant attributes), the SHA-256 hashed billing email, phone, first and last name, the billing country, city, region and postal code, the customer's IP address and user agent, and the attribution cookie values captured at checkout. The admin "Send test event" button sends a synthetic event with `validate_only` set, which OpenAI validates but does not store.

The product feed feature does not contact OpenAI or Meta by itself; it generates files on your server that you choose to give to them.

Service provider: OpenAI, L.L.C. Terms of use: https://openai.com/policies/terms-of-use/ — Privacy policy: https://openai.com/policies/privacy-policy/ — ChatGPT Ads developer documentation: https://developers.openai.com/ads

**Meta Pixel (browser SDK)** — https://connect.facebook.net/en_US/fbevents.js — loaded on every front-end page while the Meta provider is enabled, to measure ad conversions. The SDK sends events to https://www.facebook.com/tr: page views, product views, add-to-cart, checkout and purchase events (WooCommerce product ids, names, quantities and amounts), and registrations. The SDK reads and sets first-party cookies (`_fbp`, `_fbc`) and may collect the visitor's IP address and user agent as part of the request. When "Send hashed customer data" is on, the plugin also passes SHA-256 hashes of the logged-in user's or the order's billing email, phone, first and last name, city, region, postal code, country and customer id (Meta advanced matching). Raw values are never sent.

**Meta image tag (no-JavaScript fallback)** — https://www.facebook.com/tr — when enabled, a 1x1 image records a PageView for visitors without JavaScript. It carries only the Pixel ID and event name.

**Meta Conversions API (server-to-server)** — https://graph.facebook.com/ — only when enabled with your access token. When an order is paid, the plugin sends the order id, total, currency and line items (product ids, quantities, unit prices), the SHA-256 hashed billing email, phone, first and last name, city, region, postal code, country and customer id, the customer's IP address and user agent, and the `_fbp` / `_fbc` cookie values captured at checkout. The admin "Send test event" button sends a synthetic event carrying your test event code, which Meta shows under Test events and does not count as a conversion.

Service provider: Meta Platforms, Inc. / Meta Platforms Ireland Ltd. Meta Business Tools terms: https://www.facebook.com/legal/terms/businesstools — Privacy policy: https://www.facebook.com/privacy/policy/ — Developer documentation: https://developers.facebook.com/docs/meta-pixel

**Consent.** Measurement is opt-in for the site owner (disabled until configured) and administrators are excluded by default. To make it opt-in for visitors, choose "Require consent first" on each provider: the pixel then starts with consent revoked (`oaiq("consent", false)` / `fbq("consent", "revoke")`) and only measures after your cookie banner calls `window.openPixel.grantConsent()` or the WP Consent API reports the "marketing" category as allowed. Server-side Conversions API events are sent for paid orders whenever that option is enabled; leave it off if your legal basis requires browser consent for them too. Disclose the pixels you use in your site's privacy policy.

== Frequently Asked Questions ==

= Where do I find my Pixel ID and Conversions API key? =

In ChatGPT Ads Manager, Conversions tab. For Meta: Events Manager > Data sources (Pixel / dataset ID) and the dataset's Settings > Conversions API > Generate access token.

= My site uses a Content Security Policy. =

Allow `script-src https://bzrcdn.openai.com`, `connect-src https://bzr.openai.com https://bzrcdn.openai.com`
and `img-src https://bzr.openai.com`. For the Meta pixel add `script-src https://connect.facebook.net` and `connect-src` / `img-src https://www.facebook.com`. Use the `openpixel_script_nonce` filter to add your nonce to the inline snippets.

= Does it work with WooCommerce HPOS? =

Yes. The plugin only uses the WooCommerce CRUD order API and declares HPOS compatibility.

== Changelog ==

= 1.3.0 =
* New: Meta (Facebook & Instagram) Pixel provider: PageView, ViewContent, AddToCart, InitiateCheckout, Purchase, CompleteRegistration and custom events, hashed advanced matching, consent mode, noscript fallback.
* New: Meta Conversions API: server-side Purchase with the same event ID as the browser event, `_fbp` / `_fbc` captured at checkout, async delivery with retries, test event code support.
* New: Meta catalog feed (CSV) built alongside the OpenAI product feed, at its own private URL, with ids that match the pixel's content_ids.
* Each provider's "Track WooCommerce events" switch now only affects that provider.
* Fix: the browser de-duplication of replayed events is now per provider, so two pixels sharing an event ID both receive it.

= 1.2.1 =
* Product feed URL is now a plain path (https://your-site/openpixly-feed/<token>/products.csv) so it can be pasted into Ads Manager "Connect your feed via URL", which rejects query-string URLs. The old ?openpixel_feed= URL keeps working.

= 1.2.0 =
* Renamed to Openpixly (slug openpixly). Not affiliated with OpenAI.
* Readme: external services, data sent and consent documented.
* Product feed: WooCommerce catalog export in the OpenAI product feed format or Google-compatible profile (CSV/TSV/JSONL), variants with group_id/variant_dict, scheduled rebuilds, private tokenized URL, download and URL rotation.
* Fix: Conversions API deliveries failed with "no callbacks are registered" when run from Action Scheduler.
* page_viewed on the order-received page now reports "order-received" instead of the Checkout page.
* No duplicate contents_viewed after a classic add-to-cart reload.

= 1.1.0 =
* Native Measurement Pixel loader, page_viewed, consent mode, debug mode, noscript image tag.
* WooCommerce events: contents_viewed, items_added, checkout_started, order_created, registration_completed.
* Advanced matching with server-side normalization and SHA-256 hashing.
* Conversions API: server-side order_created with deduplication, async delivery, retries, logging, validate-only test.
* Provider-agnostic event bus for future Meta/Google providers.

= 1.0.0 =
* Initial release.
