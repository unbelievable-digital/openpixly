# Openpixly — project guide for Claude

WordPress plugin: conversion pixel manager + product feed for ChatGPT Ads. Lives in `openpixly/` (that folder is what ships). Repo: https://github.com/unbelievable-digital/openpixly. WordPress.org slug `openpixly`, approved and published 2026-09-10.

## Sources of truth (never guess, read these)

- Pixel: https://developers.openai.com/ads/measurement-pixel.md
- Events + data shapes: https://developers.openai.com/ads/supported-events.md
- Conversions API: https://developers.openai.com/ads/conversions-api.md
- Image tag: https://developers.openai.com/ads/image-tag.md
- Product feeds: https://developers.openai.com/ads/product-feeds.md, field spec https://developers.openai.com/commerce/specs/file-upload/products.md, delta API https://developers.openai.com/ads/delta-feeds.md
- Meta: pixel https://developers.facebook.com/docs/meta-pixel/reference, Conversions API https://developers.facebook.com/docs/marketing-api/conversions-api/using-the-api, user data normalization https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/customer-information-parameters, catalog fields https://developers.facebook.com/docs/marketing-api/catalog/reference
- Append `.md` to any developers.openai.com page for raw markdown. Docs change; re-fetch before implementing anything new.

## Architecture rules

- **Event bus is provider-agnostic.** Integrations (WooCommerce, forms, …) call `openpixel()->get_bus()->track()` with normalized names (`page_view, view_item, add_to_cart, begin_checkout, purchase, sign_up, generate_lead, schedule, subscribe, start_trial, custom`), money in **major units** + currency, raw (unhashed) user data. Never call `oaiq` or a provider API from an integration.
- **Providers translate.** `OpenPixel_Provider` subclasses map bus events to their API (`to_browser_payload`, `handle_server_event`), declare settings via `get_fields()` (admin renders them generically), and print their own loader in `render_head`. Meta (`OpenPixel_Provider_Meta`, id `meta`) is the second built-in provider; adding Google/TikTok = one PHP class + `window.openPixel.register('<id>', fn)` in JS. Providers also declare `get_attribution_cookies()` (captured at checkout into order meta `_openpixel_<key>`, handed back in `context`) and `get_server_test_description()` / `send_server_test()` for the admin test button. Do not add provider-specific branches to core, admin or integrations.
- **Event source:** integrations tag events with `source` (`woocommerce`); core skips providers whose setting of that name is off. Keep it so each provider's "Track WooCommerce events" stays independent.
- **Money:** `OpenPixel_Money::to_minor()` for the OpenAI pixel/CAPI (integer minor units, ISO 4217 exponent, not store decimals). Meta takes decimal major units (`value`, `item_price`). Feed uses major units `79.99 USD`.
- **Hashing:** Meta normalization differs (city/state/zip/country are hashed too, names keep inner spaces, US zip = 5 digits): `OpenPixel_Hash::meta_user()`. `OpenPixel_Hash` implements the documented normalization exactly (phone `+1 (415) 555-2671` must hash to `758fbf68…`). Hash on the server; never send raw PII to the browser or in URLs.
- **Deduplication:** deterministic `event_id`s — `order_{id}`, `checkout_{cart_hash}`, `reg_{user_id}`, `cart_{key}_{ts}`. Browser and CAPI reuse the same id. Fire purchase once per order (order meta `_openpixel_pixel_fired`, `_openpixel_capi_queued`).
- **Server-side channel:** `channel => 'server'` events go to `openpixel_server_event` → each provider's `OpenPixel_CAPI_Client` subclass (`OpenPixel_OpenAI_CAPI`, `OpenPixel_Meta_CAPI`) `queue_event` → Action Scheduler action `openpixel_openai_capi_send` / `openpixel_meta_capi_send` (4 attempts, 5xx/408/429 retry only; shared in the base class). The client must be instantiated eagerly (provider constructor) or cron has no callback. Meta has no validate-only: tests need the `capi_test_code` setting; the access token goes in the POST body, never the URL.
- **Events raised without a page** (AJAX add-to-cart, registration redirect) are persisted in the WooCommerce session / user transient and flushed in the next footer or through `woocommerce_add_to_cart_fragments` (`#openpixel-pending`). JS dedups replays via `sessionStorage` `openpixel_seen`, keyed `<provider>:<event_id>` because providers share event ids.
- **Product feed:** `OpenPixel_Product_Feed` builds in 200-product batches into `uploads/openpixly/feed-<hash>.<ext>`, status in option `openpixel_feed_status`, served at `/openpixly-feed/<token>/products.<ext>` (path, matched from REQUEST_URI, no rewrite flush; OpenAI URL import rejects query strings; legacy `?openpixel_feed=<token>` still works) (`hash_equals`, noindex, no-cache). Item ids must equal the ids used in pixel `contents[]` / Meta `content_ids`. Optional Meta catalog (`meta_catalog` setting): second CSV written in the same batch loop to `feed-<hash>-meta.csv`, served at `/openpixly-feed/<token>/meta-catalog.csv`, profile `meta` (availability `in stock` / `out of stock`).
- **Prefix everything** `openpixel_` / `OpenPixel_` / `OPENPIXEL_` (kept from the earlier "Open Pixel" name; unique enough, do not churn). Text domain, slug, folder, main file, log source, uploads dir and Action Scheduler group are all `openpixly`. No `oaip` anywhere.
- **Names:** plugin is "Openpixly – Conversion Tracking & Product Feed for OpenAI Ads" (coined word first, trademark last after "for"). wp.org review rejected "Open Pixel" (existing agency name, generic-first). OpenAI only as the provider label "OpenAI (ChatGPT Ads Measurement Pixel)", Meta only as "Meta (Facebook & Instagram Pixel)"; readme states no affiliation with either.
- **wp.org review requirements already met, keep them:** `== External services ==` section in readme.txt listing every OpenAI and Meta endpoint, the data sent and when, OpenAI terms + privacy links, and the consent story (opt-in for site owner, admins excluded, "Require consent first" mode). Any new external call must be added there. Contributors line must include `zgrkaralar`.

## Conventions

- WordPress coding standards, tabs, `esc_*` on output, `sanitize_*` on input, nonces on admin-post actions, `manage_options` capability. Plugin Check must pass with no errors (`npx pressship verify ./openpixly`).
- Keep `readme.txt` (wp.org) and `README.md` (GitHub) in sync; short description ≤ 150 chars; bump `Version` header, `OPENPIXEL_VERSION` and `Stable tag` together; add a changelog entry.
- `docs/PLAN.md` tracks phases; update checkboxes when shipping.
- Commit style: Conventional Commits (`feat:`, `fix:`, `docs:`, `design:`), body explains the doc fact behind the change.
- Assets for wp.org live in `.wordpress-org/` (icon 256/128, banner 1544x500 / 772x250, concept sources in `source/`).

## Testing

- Local store: `npx pressship demo ./openpixly --port 8881 --skip-browser` (WordPress Playground, SQLite). Site files under `~/.wordpress-playground/sites/<hash>/`; a `dynamic-host.php` mu-plugin makes it reachable over LAN/Tailscale. Admin `admin`/`password`, auto-login `?pressship_auto_login=1`.
- WooCommerce is installed by unzipping into that site's `wp-content/plugins/` and activating through wp-admin; products/settings created through the WC REST API with the cookie + `X-WP-Nonce`.
- End-to-end script: `scratchpad/e2e.mjs` (Playwright): wraps `window.oaiq` to log every call, records `bzr.openai.com` responses, walks home → product → add to cart → cart → checkout → COD order → thank-you reload. Expect 202s, `order_created` once, `page_viewed` id `order-received` on reload.
- Conversions API: admin "Send test event" = `validate_only`. Real delivery is visible in WooCommerce > Status > Logs, source `openpixly`. Trigger Action Scheduler with `curl wp-cron.php?doing_wp_cron=…`; if an action shows stale `claim_id`, reset it in `wp_actionscheduler_actions`.
- Feed: enable in the Product feed tab, "Rebuild now", then fetch the private URL; check header row, `price` format, `group_id`/`variant_dict` on variations, skipped count.

## Publishing

- `npx pressship verify ./openpixly` → `pack` → `publish --submit --dry-run -y`. Real submit needs `pressship login`; the CLI's overview prompt needs a TTY, so call `submit()` from `pressship/dist/wordpress-org/submit.js` with `{ yes: true, overview }` from a script instead of `expect` (spinner floods a pty).
- Released: 1.2.0 live at https://wordpress.org/plugins/openpixly/ (SVN r3689603 code, r3689605 assets, 2026-09-10; git tag `v1.2.0`).
- Released: 1.3.0 (Meta pixel, Conversions API, catalog feed) SVN r3704553, 2026-09-20; git tag `v1.3.0`.
- Consent: `openpixel.js` depends on `wp-consent-api` (enqueue priority 20) and core wires `openpixel_consent_granted` to `wp_has_consent('marketing')` only when `wp_get_consent_type()` is non-empty (GitHub #1). Test stub for this lives in the demo store as `openpixly-consent-test.php` (fakes an opt-in CMP).
- Next releases: bump version (header, `OPENPIXEL_VERSION`, `Stable tag`, changelog), then `npx pressship release ./openpixly --slug openpixly --username zgrkaralar -y`. Do NOT pass `--version`: the CLI treats it as its own version flag and just prints `0.1.0`; the version comes from the plugin header.
- SVN password is saved in `~/.config/pressship/svn-credentials.json` (mode 600, never commit). Working copy is `.pressship-svn/openpixly` (gitignored).
- Pressship does not upload `.wordpress-org/` images. When icons/banners change: copy them into `.pressship-svn/openpixly/assets/`, `svn add`, `svn propset svn:mime-type image/png assets/*.png`, `svn commit`.
- Never commit API keys or Pixel IDs; the test store's values live only in its SQLite DB.
