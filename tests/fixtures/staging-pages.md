# Fixture pages: local Docker dev site (http://localhost:8730)

This build has no shared staging host (decision D-10), so the fixture pages from `docs/05-TEST-PLAN.md` §1.3 live on the private Docker dev site `sgl-p3` (WordPress 7.1.3, PHP 8.3, Twenty Twenty-Five 1.5, site name "Example Bakery"). Content and images are invented. Created in Phase 0 and Phase 5 on 2026-10-25.

## Pages

| Slug | ID | Title | URL | Built with |
|---|---|---|---|---|
| `a11y-fx-block-editor` | 13 | A11Y FX Block editor | http://localhost:8730/a11y-fx-block-editor/ | Core paragraph, image and heading blocks + one Custom HTML block |
| `a11y-fx-forms` | 14 | A11Y FX Forms | http://localhost:8730/a11y-fx-forms/ | Custom HTML block + Contact Form 7 6.2.3 form (shortcode block) |
| `a11y-fx-structure` | 15 | A11Y FX Structure | http://localhost:8730/a11y-fx-structure/ | Custom HTML block + the mu-plugin `dev/mu-plugins/a11y-fx-structure-head.php` |
| Home page | — | (blog index) | http://localhost:8730/ | Twenty Twenty-Five header and footer, "Hello world!" post |
| `a11y-fx-elementor` | 42 | A11Y FX Elementor | http://localhost:8730/a11y-fx-elementor/ | Elementor 4.3.5 (free) containers and widgets (Phase 5) |
| `a11y-fx-product` (simple product) | 33 | Country sourdough loaf | http://localhost:8730/product/a11y-fx-product/ | WooCommerce 11.2.1 product, SKU `A11YFX-LOAF` (Phase 5) |
| `a11y-fx-product-variable` | 34 | Celebration cake | http://localhost:8730/product/a11y-fx-product-variable/ | WooCommerce variable product, SKU `A11YFX-CAKE`, variations 35 (6 inch) and 36 (8 inch) (Phase 5) |
| `a11y-fx-cart-classic` | 37 | A11Y FX Classic cart | http://localhost:8730/a11y-fx-cart-classic/ | `[woocommerce_cart]` shortcode block (the classic cart; Phase 5) |
| `shop` | 22 | Shop | http://localhost:8730/shop/ | WooCommerce page (product archive) |
| `cart` | 23 | Cart | http://localhost:8730/cart/ | WooCommerce page, **Cart block** (client-rendered) |
| `checkout` | 24 | Checkout | http://localhost:8730/checkout/ | WooCommerce page, **Checkout block** (client-rendered) |
| `my-account` | 25 | My account | http://localhost:8730/my-account/ | WooCommerce page, `[woocommerce_my_account]` (login form when logged out) |

Since Phase 5, Elementor 4.3.5, WooCommerce 11.2.1 and Contact Form 7 6.2.3 are **active** (later phases scan shop pages); Astra 4.14.1 and Kadence 1.5.2 are installed, Twenty Twenty-Five is the active theme. WooCommerce's page-list entries and, after a theme switch, its Customer Account and Mini-Cart blocks are in Twenty Twenty-Five's header on every page. Elementor switches off WordPress's template buffer on the front end, so the plugin's whole-page pass does not run on this site while Elementor is active (Layer B instead; `DECISIONS-APPLIED.md` row "5"). `a11y-fx-wpbakery` and `a11y-fx-divi` need licences (D-02) and are not built.

## Other objects

| Object | ID | Notes |
|---|---|---|
| Attachment "Butter croissant" | 6 | `a11y-fx-croissant.jpg` 640x420; library alt "Butter croissant on a wooden board" |
| Attachment "Baguettes" | 7 | `a11y-fx-baguette.jpg` 640x420; library alt "Three baguettes cooling on a rack" |
| Attachment "Sourdough loaf" | 8 | `a11y-fx-sourdough.jpg` 120x80; library alt "Round sourdough loaf with a floured crust" |
| Attachment "Chocolate cake" | 9 | `a11y-fx-cake.png` 640x420; library alt "Three-layer chocolate cake" |
| Attachment "Cinnamon rolls" | 10 | `a11y-fx-cinnamon-rolls.jpg` 300x200; library alt "Cinnamon rolls in a baking tin" |
| Attachment "Bakery oven" | 11 | `bakery-oven.webm`, about 4 s, silent, recorded with `tools/fixtures/make-video.mjs` |
| Contact Form 7 form "A11Y FX cake order" | 12 | `[contact-form-7 id="6cc4431" title="A11Y FX cake order"]` |
| Product category "A11Y FX Bakery" | 16 | slug `a11y-fx-bakery` (Phase 5) |
| Menu "A11Y FX Find us" | 17 | items 38–40: Opening hours, Delivery, Allergens (custom links; Phase 5) |
| Attachments "Country sourdough loaf", "Sourdough crumb", "Sourdough crust", "Celebration cake", "Bakery counter" | 28, 29, 30, 31, 32 | keys `loaf-product`, `loaf-gallery-1`, `loaf-gallery-2`, `cake-product`, `counter`; **library alt empty on purpose** (Phase 5) |
| Contact Form 7 form "A11Y FX newsletter" | 41 | `[contact-form-7 id="badcbfb" title="A11Y FX newsletter"]`: `<label for="newsletter-email">` + `[email* your-email id:newsletter-email]` + submit (Phase 5) |
| Elementor kit | 27 | created by Elementor on activation; Google Fonts switched off (`elementor_google_font` = 0) so runs stay offline |
| Admin persistent cart | user 1 | one Country sourdough loaf in `_woocommerce_persistent_cart_1`, so the administrator's own cart pages have an item for Phase 6 scans |

Every fixture attachment carries the meta `_a11y_fx_key`, so the build script finds it again instead of importing a copy.

## What each page contains

**`a11y-fx-block-editor`.** Failures: two image blocks whose `<img>` has no `alt` while the Media Library has one (IDs 6, 7; F01); an inline image with no `alt` inside the text link "Order a sourdough loaf" (ID 8; F03); icon-only links in Custom HTML: Facebook (bare SVG), Instagram (`i.fa.fa-instagram`), `mailto:` (`span.dashicons-email`) (F05, F07); an `iframe` without `title` (F09); a link with `tabindex="3"` (F14); an empty `h2` heading block (F20); two "Read more" links inside `article` elements (F19); a `target="_blank"` link (F21); a card whose image link duplicates the visible title link and carries `aria-hidden="true"` (F15). Controls that must stay untouched: the image block with a deliberate `alt=""` (ID 9; F02 is Off by default); links with visible text ("visit the shop", "Order online", "Cinnamon rolls"); the "Opening hours" link named by `title` (its SVG is `aria-hidden`); the YouTube link named by `svg > title`; the newsletter field with a real `<label for>`.

The iframe has `src="https://www.youtube-nocookie.com/embed/a11yfxTour"` (the host F09's label map reads on the server) and a `srcdoc` placeholder, which the browser shows instead of fetching `src`. That keeps axe and pa11y runs offline and deterministic. pa11y reports the srcdoc frame as `frame-tested`; axe through Playwright does not.

**`a11y-fx-forms`.** Failures (Custom HTML "enquiry" form): placeholder-only `your-name`, `your-email`, `your-message` (F08; axe accepts a placeholder as a name, so these show in NVDA and in R06, not in axe); `input[type=tel][name=phone]` with no name at all (F08, axe `label`); `select[name=pickup-day]` with no label (F08, `select-name`); `input[type=submit]` with an empty `value` (F06, `input-button-name`). A `role="search"` form with a placeholder-only search field and an icon-only submit button (F06 "Search", `button-name`). The same "Call me back" form twice, both with `id="callback-phone"` and `label for="callback-phone"` (F17; axe 4.14 reports `duplicate-id-aria` and `form-field-multiple-labels` as needs-review, not as violations). The Contact Form 7 form has placeholder-only name, email and message fields. Controls: "Gift note" (`label for`), "Allergies" (`aria-labelledby`), the Contact Form 7 "Cake size" select (wrapping `label`).

**`a11y-fx-structure`.** In the content (Custom HTML): a skip link to the missing `#bakery-main-content` (F12); two unlabelled `nav` elements (F18 / R08, axe `landmark-unique`); an autoplaying, looping `video` without `muted` or `controls` (R10); a hours table without `th` (R13); `div role="button"` without `tabindex` (R12). From the mu-plugin, on this page only: `<html>` without `lang` (F10), the core viewport tag replaced by `width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no` (F13), and a second broken skip link (`#bakery-missing-target`) printed on `wp_body_open` as the first link in `<body>`. axe's `skip-link` rule only examines in-page links that come before the first ordinary link, so the content skip link is invisible to axe; the `wp_body_open` one makes F11/F12 measurable. Twenty Twenty-Five 1.5 on WordPress 7.1.3 prints its own working skip link (`#wp-skip-link` to `#wp--skip-link--target`) right after it.

**`a11y-fx-elementor` (Phase 5).** Elementor 4.3.5 free (inline SVG icons on, containers). Container 1: Heading "Cakes and bread from Example Bakery", Button "Order a cake" (controls). Container 2: Icon widgets linked to `tel:+441234567890`, `https://wa.me/441234567890`, the WooCommerce cart page and `/opening-hours/` (icon-only links: F05 tel, host map, cart page; the last one is R05); an Icon Box with only an envelope icon and a `mailto:orders@example.com` link (F05); an Icon Box with title "Find the shop" (Elementor names its icon link itself: control). Container 3: Social Icons widget (Facebook, Instagram, X; named by Elementor: control), Image widget linking the "Bakery counter" image (library alt empty) to its file in the lightbox (R05), the WordPress "Navigation Menu" widget with menu 17. Container 4: background video (the Phase 0 WebM; R10). Containers 5 and 6: the same section twice, CSS ID `bakery-newsletter` (R11), each with the CF7 newsletter form, whose second copy loses its id, so its label points at the first form's field (F08 names it "Email"). Form, Nav Menu, Template and theme-builder widgets are Elementor Pro (placeholders in the free plugin), so they are not on the page.

**`a11y-fx-product`, `a11y-fx-product-variable` (Phase 5).** Simple product "Country sourdough loaf" ($6.50) with a main image and two gallery images, and variable product "Celebration cake" (Size: 6 inch $28, 8 inch $36) with a main image, all with an empty library alt (WooCommerce prints the product name itself). The WooCommerce pages are WooCommerce's own: Shop (archive), Cart and Checkout (blocks, client-rendered), My account (shortcode). `a11y-fx-cart-classic` holds the classic `[woocommerce_cart]` shortcode for the remove link, quantity and coupon fields. `tools/a11y/urls.json` measures `shop-with-item`, `cart-classic`, `cart-block` and `checkout-block` with one loaf in the cart (its `prime` path adds it through the product page).

**Theme settings (Phase 5).** The script also sets Astra's header builder (`astra-settings`: primary right `menu-1, search, account, woo-cart, social-icons-1`; mobile `search, woo-cart, mobile-trigger`; social links to Facebook and Instagram) and Kadence's (`theme_mods_kadence`: main right `navigation, search, cart, social`; mobile `search, mobile-cart, popup-toggle`; Popout cart; social links), so the header icons of both themes are on every page when they are active, and it sets WooCommerce's site visibility to Live (a new store switches itself to "Coming soon" once its admin screens load).

## Edge-case page (Phase 2, not on the site)

`tests/fixtures/edge/images-icons.html` is a hand-written document, not a captured page: 15 image cases (library alt by class, URL, `data-src`, `data-attachment-id`; a `data-id` that does not match its file; an attachment without alt; another host; a URL not in the library; images in named links and buttons; aria-hidden and `role="presentation"` images; a `noscript` copy), 6 alt-quality cases (file name, "Image of", link text, caption, digits) and 15 icon cases (Divi-style Private Use Area span, Font Awesome, a bare glyph span, icons in unnamed links, SVGs with and without `title`, `role="img"`, `aria-hidden`, a self-closing SVG). It points at attachments 6–11 on the dev site. Its expected output `images-icons.expected.html` was made on the dev site (copy to `dev/fixtures-share/edge/`, then `wp eval-file /fixtures/tools/make-expected.php /fixtures/edge/images-icons.html`), so the real Media Library lookup produced it; PHPUnit compares against it with a lookup double holding the same alt texts and files.

## Edge-case page (Phase 3, not on the site)

`tests/fixtures/edge/links-forms.html` is a hand-written document for F05, F06, F07's dependency on them, F08, F19, F21 and R05–R07: a logo link to the home page (image with `alt=""`) and a root-relative SVG home link; a `nav` with an id and a toggle button pointing at it with `aria-controls`, a `menu-toggle` button, three close buttons (class `modal-close`, class `dismiss-banner`, `fa-xmark` icon), an empty `role="button"` div and an empty `input[type=button]` (R05), a button named by its own `aria-label`; social links by host (Facebook with an `aria-hidden` icon, Instagram, twitter.com with a Divi glyph, wa.me), by icon class only (LinkedIn on another host), a YouTube link whose `aria-labelledby` points at a missing id, `mailto:` with a query and `tel:` with an encoded number, a cart link (WooCommerce inactive, so the icon class names it), an account link whose icon class is not in the map (R05), an image-only link (F01's job), links named by `title` and by text, an `aria-hidden` duplicate link; three search forms (`role="search"`, a `name="s"` field with a screen-reader label, an empty submit `value`); a placeholder-only form with map names (`your-email`, `your-message`, `quantity`), a select, a checkbox and an unknown name (R05), a hostile placeholder, a labelled field and an `aria-label` field; "Read more »" and "Continue reading" in articles (one heading hostile), a "Learn more" link with its own `aria-label`, "Read more" and "click here" outside articles (R07); new-tab links: plain, with an existing `aria-describedby`, already saying "opens in a new tab", and `target="_BLANK"` with a "new window" title; and the hidden note element Front_End prints on `wp_footer`, so F21 applies in visitor mode. Its expected output `links-forms.expected.html` was made on the dev site like the Phase 2 page (`wp eval-file /fixtures/tools/make-expected.php /fixtures/edge/links-forms.html`), so the home URL `http://localhost:8730` and the site name "Example Bakery" are in it; the PHPUnit engine test case answers `home` and `blogname` with those values.

## Edge-case page (Phase 4A, not on the site)

`tests/fixtures/edge/structure.html` is a hand-written document for F11, F12, F14, F20 and R02/R08/R09: our own skip link as Front_End prints it at the start of `body` (so F11's page pass has something to settle), a header with three links (the second with `tabindex="2"`, the third with `tabindex="0"`), a broken `a.skip-link` (`#site-content`) and a broken "Skip to navigation" link without a class (`#missing-nav`); a `main` without an id; nine heading cases (empty, `&nbsp;` only, `br` only, an image with alt, `aria-label`, an id, an empty child element, `aria-hidden`, inside an `aria-hidden` element) followed by an h2, a jump to h4 and a second h1; in-page links that must stay (`#top`, a named anchor, an existing id, a percent-encoded id, an ordinary in-page link); tabindex `" +4"`, `-1`, `abc` and `12`; two unlabelled `nav` elements and a labelled one. Expected output `structure.expected.html` made on the dev site like the other edge pages (`wp eval-file /fixtures/tools/make-expected.php /fixtures/edge/structure.html`): `main` gets `id="softglaze-a11y-main"`, both broken skip links point there, three headings get `aria-hidden="true"`, three tabindex values become `0`.

Dev-only for Phase 4A: `dev/mu-plugins/sglp3-no-core-skip-link.php` switches core's block-theme skip link off for requests with `?sglp3_no_core_skip=1`, so F11's own link can be seen on Twenty Twenty-Five.

## Edge-case page (Phase 4B, not on the site)

`tests/fixtures/edge/focus-ids.html` is a hand-written document for F15, F16, F17 and R10–R13: a header menu toggle with `aria-controls` and its aria-hidden menu; nine card cases for F15 (copy and title link in an `article`, in a `div` within ten tags, far apart in an `article`, far apart in a `div`, a copy with `tabindex`, a lonely copy, an unreachable partner, an unnamed partner, `#` links); F16 containers (a promotion banner holding a link, button, text field, hidden field, disabled button, focusable span, an already-out link, a link without href and a frame; a decorative link with `tabindex="2"`; a link inside a plain wrapper) and every kind of container it must leave alone (dropdown and slider classes, `aria-controls`, `href="#id"` and `data-toggle-target` targets, `hidden`, `role="dialog"`, a list inside a `nav`, a nested menu); three copies of a "callback" form (described phone field, labelled-by button), a form with one id twice, a form without a label, loose fields and repeated paragraphs (R11); autoplay video and audio cases, `role="button"`/`"link"`/`"tab"` and `onclick` cases, and five tables (no `th`, with `th`, layout, single row, nested). Expected output `focus-ids.expected.html` made on the dev site like the other edge pages (`wp eval-file /fixtures/tools/make-expected.php /fixtures/edge/focus-ids.html`): 17 attribute changes (three F15 `tabindex="-1"`, seven F16 `tabindex="-1"` including the `2` that F14 would have made `0`, seven F17 id, `for` and `aria-labelledby` edits).

## Rebuild or refresh

From the workspace root (Git Bash). The script is idempotent: it updates the pages in place and reuses the attachments and the form.

```bash
docker exec sgl-p3-cli wp plugin activate contact-form-7
node tools/fixtures/make-video.mjs "$TMP/bakery-oven.webm"          # once; any short WebM works
docker cp "$TMP/bakery-oven.webm" sgl-p3-cli:/tmp/bakery-oven.webm
docker cp tools/fixtures/create-fixtures.php sgl-p3-cli:/tmp/create-fixtures.php
MSYS_NO_PATHCONV=1 docker exec sgl-p3-cli wp eval-file /tmp/create-fixtures.php /tmp/bakery-oven.webm --user=sgladmin
```

`--user` must be an administrator: the Custom HTML blocks need `unfiltered_html`, otherwise kses strips the `iframe`, forms and SVGs.

Phase 5 fixtures (WooCommerce, Elementor and Contact Form 7 active; idempotent, the ids above stay the same):

```bash
docker exec sgl-p3-cli wp plugin activate woocommerce elementor contact-form-7
docker cp tools/fixtures/create-fixtures-phase5.php sgl-p3-cli:/tmp/create-fixtures-phase5.php
MSYS_NO_PATHCONV=1 docker exec sgl-p3-cli wp eval-file /tmp/create-fixtures-phase5.php --user=sgladmin
```

## Captured HTML

`tests/fixtures/html/<slug>.html` (and the copies in `dev/fixtures-share/`, mounted at `/fixtures` for `wp softglaze-a11y analyse --file=/fixtures/<slug>.html`) were captured on 2026-10-25 with the plugin active and nothing Live, anonymous, with a cache-busting `?a11y_cb=<epoch>` query argument:

| File | Bytes | SHA-256 |
|---|---|---|
| `home.html` | 71391 | `ceb72bcfa2f2ab9d3e3b26f9af893cedda949fc3094e60f87c7be6b193216156` |
| `a11y-fx-block-editor.html` | 82020 | `09507a78eabd8f4ef3c6571a11714166c99fd8e17f97370c664764150e12ac37` |
| `a11y-fx-forms.html` | 72728 | `1fb70678b7a74a01ecdcfe7e5c07b952659e4589afdcae39375ce9abe7c90f38` |
| `a11y-fx-structure.html` | 68621 | `cc4cb5d866ac3576b857877600d3d64d05d1dfdd608b33ea66b483d30a0b3dec` |

Contact Form 7 echoes the request URI (with the cache-busting argument) into its form `action`, so `a11y-fx-forms.html` differs between captures in that one attribute.

Phase 5 added two captures (anonymous, nothing Live, Elementor, WooCommerce and Contact Form 7 active, Twenty Twenty-Five with WooCommerce's hooked header blocks); the four Phase 0 captures were not retaken, because the golden tests read only the files:

| File | Bytes | SHA-256 |
|---|---|---|
| `a11y-fx-elementor.html` | 134903 | `bb6f5ebef0783119cfdf5cc5a68e56aa628ec1c43c2be626771d06d720bf91d9` |
| `a11y-fx-product.html` | 146318 | `8b6cc906e334d3f023fd6fe2eea04bd22fdb9feccad9dac430b236b6d2aab2dc` |

Their expected files were generated on the dev site with `make-expected.php` and reviewed with `dev/fixtures-share/review/token-diff.php`: Elementor page 9 edits (F07 on the Customer Account and Mini-Cart icons, F05 on four icon links, F08 on the second newsletter field), product page 6 (F07 on the same two icons and a store notice's dismiss icon). PHPUnit answers WooCommerce's cart and account URLs for them with `tests/phpunit/helpers/woocommerce-url-stubs.php`.
