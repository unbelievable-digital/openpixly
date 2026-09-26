# Openpixly for WordPress

ChatGPT Ads **Measurement Pixel** + **Conversions API** for WordPress and WooCommerce, implemented straight from the official docs (https://developers.openai.com/ads/measurement-pixel), plus the **Meta Pixel**, **Meta Conversions API** and a **Meta catalog feed** on the same event bus. Enter a Pixel ID, done.

Built as a small pixel-manager framework: every provider is a single class on the same event bus (OpenAI and Meta ship; Google Ads / TikTok later).

## What it tracks

| Where | OpenAI event | Meta event | Data |
|---|---|---|---|
| Every front-end page | `page_viewed` | `PageView` | page id + title as a `page` content (OpenAI) |
| Single product page | `contents_viewed` | `ViewContent` | product id, name, price, currency |
| Add to cart (classic, AJAX, blocks/Store API) | `items_added` | `AddToCart` | product, quantity, line amount, `event_id` |
| Checkout page | `checkout_started` | `InitiateCheckout` | cart items + total, `event_id = checkout_{cart_hash}` |
| Order-received page | `order_created` | `Purchase` | order items + total, `event_id = order_{id}` (fires once per order) |
| Payment complete (server) | `order_created` via Conversions API | `Purchase` via Conversions API | same `order_{id}` → deduplicated against the browser event |
| New user / customer | `registration_completed` | `CompleteRegistration` | `event_id = reg_{user_id}` |

OpenAI amounts are integers in ISO 4217 minor units; Meta gets decimal `value` + `currency`, `content_ids`, `contents[{id, quantity, item_price}]`, `content_type: product`, `num_items`. Other bus events map to `Lead`, `Schedule`, `Subscribe`, `StartTrial` and `trackCustom`.

Advanced matching: on the thank-you page and for logged-in users, email / phone / first & last name / external id are normalized and SHA-256 hashed on the server exactly as the docs specify, and passed to `oaiq("init", { user })`. Geo fields go as plain text, as required. For Meta the same data is normalized per the [customer information parameters](https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/customer-information-parameters) (`em, ph, fn, ln, ct, st, zp, country, external_id`, all SHA-256) and passed to `fbq("init", id, {…})` and the Conversions API `user_data` (plus `client_ip_address`, `client_user_agent`, `fbp`, `fbc`).

## Install

Copy `openpixly/` into `wp-content/plugins/`, activate, open **Settings > Pixel Manager**, paste your Pixel ID (from Ads Manager > Conversions), enable.

For the Conversions API, also paste the API key from the same tab and use **Send test event** — it calls the endpoint with `validate_only: true`, so nothing is recorded.

Meta: enable the **Meta (Facebook & Instagram Pixel)** section, paste the Pixel (dataset) ID from Events Manager. For the Conversions API generate an access token in the dataset's Settings. Meta has no validate-only mode, so **Send test event** requires the test event code from Events Manager > Test events; the event shows up there and is not counted.

## Settings

- **Pixel ID** — comma-separate several IDs; every event goes to all of them (`oaiq("init")` per ID).
- **Debug mode** — `debug: true` on init; SDK logs to the console.
- **Do not track administrators** — skips the pixel for users with `manage_options`.
- **Consent** — "Require consent first" emits `oaiq("consent", false)` before `init`. Grant it from your banner with `window.openPixel.grantConsent()`; the WP Consent API (`marketing` category) is detected automatically.
- **Send hashed customer data** — advanced matching on/off.
- **No-JavaScript fallback** — `<noscript>` image tag for `page_viewed`.
- **Track WooCommerce events** — per provider: WooCommerce events carry `source: woocommerce` and only reach providers whose switch is on.
- **Conversions API** — enable + API key. Orders are queued through Action Scheduler (ships with WooCommerce) with retries and logged under WooCommerce > Status > Logs, source `openpixly`.

## Product feed (ChatGPT Ads product-feed campaigns)

**Settings > Pixel Manager > Product feed.** Builds a catalog from WooCommerce following the [OpenAI product feed spec](https://developers.openai.com/commerce/specs/file-upload/products) and the [Ads product feeds guide](https://developers.openai.com/ads/product-feeds).

- Schemas: **OpenAI format** (`item_id, title, description, url, brand, seller_name, image_url, availability, price, sale_price, group_id, listing_has_variations, variant_dict, gtin, mpn, product_category, dimensions, weight, is_digital, is_ads_eligible, …`) or the **Google-compatible profile** (`id, link, image_link, item_group_id, product_type, identifier_exists, …`).
- Formats: CSV, TSV, JSONL (OpenAI format only).
- One row per simple product, one per published variation (same `group_id`, `variant_dict` from attributes). Prices as `79.99 USD` using your tax display settings; `sale_price` when a sale is active; availability `in_stock` / `out_of_stock` / `backorder`.
- Required fields enforced: products without brand (WooCommerce Brands or the fallback setting), price or image are skipped and counted.
- Item ids equal WooCommerce product/variation ids — the same ids the pixel sends in `contents[]`, so product-set filters and product insights line up.
- Built in batches of 200 (inline for "Rebuild now", Action Scheduler for the hourly / twice-daily / daily schedule) into `wp-content/uploads/openpixly/`, then served at `https://your-site/openpixly-feed/<token>/products.csv` (plain path, so it can be pasted into Ads Manager > Feeds > "Connect your feed via URL", which rejects query strings) with `noindex` and no-cache headers. Add `?download=1` for an attachment. "Rotate URL" invalidates the token.
- Hooks: `openpixel_feed_row( $row, $product, $parent, $profile )` to adjust or drop rows, `openpixel_feed_columns( $columns, $profile )`, `openpixel_feed_built( $file, $status )`.

**Meta catalog.** "Also build a Meta catalog feed" writes a second CSV in the same pass, following the [Meta catalog field reference](https://developers.facebook.com/docs/marketing-api/catalog/reference): `id, title, description, availability (in stock / out of stock), condition, price, link, image_link, brand, sale_price, item_group_id, additional_image_link, gtin, mpn, product_type, color, size, material`. Served at `https://your-site/openpixly-feed/<token>/meta-catalog.csv`; add it in Commerce Manager > Catalog > Data sources > Data feed > Scheduled feed. Ids equal the Meta pixel's `content_ids`. `openpixel_feed_row` receives `$profile = 'meta'` for these rows.

OpenAI ingests Ads catalogs via the SFTP location shown in Ads Manager > Feeds; download the file and upload it there, or point any fetcher at the private URL.

## Content Security Policy

If your site enforces a CSP, add:

```
script-src  https://bzrcdn.openai.com
connect-src https://bzr.openai.com https://bzrcdn.openai.com
img-src     https://bzr.openai.com
```

With the Meta pixel also `script-src https://connect.facebook.net` and `connect-src` / `img-src https://www.facebook.com`.

Use the `openpixel_script_nonce` filter to put your nonce on the inline snippets.

## Architecture

```
WordPress / WooCommerce hooks
        │
        ▼
OpenPixel_Event_Bus   normalized events: page_view, view_item, add_to_cart, begin_checkout,
        │        purchase, sign_up, generate_lead, schedule, subscribe, start_trial, custom
        │
        ├──► OpenPixel_Provider_OpenAI   oaiq("measure", …)  +  OpenPixel_OpenAI_CAPI (server)
        ├──► OpenPixel_Provider_Meta     fbq("track", …)     +  OpenPixel_Meta_CAPI (server)
        └──► (later) Google         gtag("event", …)
```

- `includes/class-openpixel-event-bus.php` — normalized event model, persistence for events raised on AJAX / redirect requests (WooCommerce session or user transient), draining into the footer or into WooCommerce AJAX fragments.
- `includes/class-openpixel-provider.php` — abstract provider: declarative settings fields, `render_head` / `render_footer`, `to_browser_payload`, `handle_server_event`.
- `includes/providers/class-openpixel-provider-openai.php` — the official loader, init, consent, `measure` mapping, `contents[]` building, `<noscript>` image tag, Conversions API event mapping.
- `includes/class-openpixel-capi-client.php` — shared server-side delivery: Action Scheduler / WP-Cron queue, retries (5xx/408/429), logging.
- `includes/providers/class-openpixel-openai-capi.php` — `POST https://bzr.openai.com/v1/events?pid=…`.
- `includes/providers/class-openpixel-provider-meta.php` / `class-openpixel-meta-capi.php` — fbq loader, init with hashed advanced matching, consent, event mapping, `<noscript>` tag; `POST https://graph.facebook.com/v25.0/<pixel>/events`.
- `includes/integrations/class-openpixel-integration-woocommerce.php` — the WooCommerce hooks, written once against the bus.
- `includes/class-openpixel-money.php` / `class-openpixel-hash.php` — ISO 4217 minor-unit conversion and the documented normalization + SHA-256 rules.
- `assets/js/openpixel.js` — tiny runtime that forwards payloads to `window.oaiq` / `window.fbq`, handles WooCommerce fragments, per-provider replay dedup and consent.

## Sending your own events

```php
// Browser event on the current page:
openpixel()->get_bus()->track( array(
    'name'     => 'generate_lead',
    'event_id' => 'lead_' . $entry_id,
) );

// Persist for the next page (e.g. inside a form handler that redirects):
openpixel()->get_bus()->track( array( 'name' => 'schedule', 'value' => 50, 'currency' => 'EUR' ), true );

// Server-side only (Conversions API):
openpixel()->get_bus()->track( array(
    'name'    => 'subscribe',
    'plan_id' => 'pro_monthly',
    'value'   => 20, 'currency' => 'USD',
    'channel' => 'server',
    'user'    => array( 'email' => $email ),
    'context' => array( 'source_url' => home_url( '/pricing' ), 'ip_address' => $ip, 'user_agent' => $ua ),
) );
```

Unknown names become `custom` events with `custom_event_name` set to the name.

## Adding a provider

```php
class My_TikTok_Provider extends OpenPixel_Provider {
    public function get_id()    { return 'tiktok'; }
    public function get_label() { return 'TikTok Pixel'; }
    public function get_fields() { /* declarative fields, see OpenPixel_Provider */ }
    public function render_head( OpenPixel_Event_Bus $bus ) { /* ttq loader */ }
    public function to_browser_payload( array $event ) {
        return array( 'provider' => 'tiktok', 'event_id' => $event['event_id'], 'args' => array( 'track', 'CompletePayment', array( /* … */ ) ) );
    }
    // Optional: handle_server_event(), get_attribution_cookies(), get_server_test_description() + send_server_test().
}
add_filter( 'openpixel_pixel_providers', function ( $providers ) {
    $providers[] = new My_TikTok_Provider();
    return $providers;
} );
```

Then register a JS handler: `window.openPixel.register('tiktok', function (p) { ttq.track.apply(ttq, p.args.slice(1)); });`. `OpenPixel_Provider_Meta` is the reference for a second built-in provider.

## Hooks

- `openpixel_pixel_providers` — register providers.
- `openpixel_track_event( $event )` — modify or drop (return `null`) any event before providers see it.
- `openpixel_track_page_view` — return `false` to skip automatic `page_viewed`.
- `openpixel_consent_granted( bool, $provider_id )` — tell the plugin consent is already granted (e.g. from a cookie) so it does not emit `consent(false)`. Wired by default to `wp_has_consent( 'marketing' )` when a consent management plugin has registered with the WP Consent API.
- `openpixel_script_nonce` — CSP nonce for inline scripts.
- `openpixel_currency_exponent( int, $currency )` — override minor-unit digits.
- `openpixel_wc_product_item( $item, $product, $quantity )` — adjust WooCommerce item data.
- `openpixel_openai_capi_delivered( $event, $response )` / `openpixel_meta_capi_delivered( $event, $response )` — after a successful Conversions API delivery.

## Roadmap

See [docs/PLAN.md](docs/PLAN.md).
