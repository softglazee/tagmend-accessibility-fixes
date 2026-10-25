=== Tagmend – Accessibility Fixes ===
Contributors: softglaze
Tags: accessibility, wcag, a11y, alt text, aria
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accessibility fixes for theme, page builder and WooCommerce HTML: common WCAG failures repaired on your server. No overlay. Preview each fix.

== Description ==

Tagmend makes accessibility fixes in the HTML your theme, page builder and WooCommerce already produce, on your own server, before the page reaches visitors, starting with common WCAG 2.2 failures such as missing labels, empty links and missing alt text. Before a fix goes live you can read every change it makes, element by element, with the value before and after. It is not an overlay: visitors get no toolbar, widget or extra JavaScript, and each fix can be switched off on its own.

No plugin can provide 100% compliance with accessibility laws or standards such as the ADA, the European Accessibility Act or WCAG.
Tagmend reduces common, machine-detectable WCAG failures and lists what still needs a person. Use it alongside testing by people, including people with disabilities.

= What it changes =

Each repair has its own switch: Off, Preview or Live.

* Images: fills a missing alt attribute from the alt text you set in the Media Library, and marks images inside links and buttons that already have a name as decorative (1.1.1).
* Links and buttons with no name: names icon-only links and buttons from reliable sources such as your site name, email and phone links, known social networks, search forms and menu toggles (2.4.4, 4.1.2).
* Form fields with no label: gives them an accessible name from the placeholder or a known field name (1.3.1, 4.1.2), and reports that a visible label is still missing (3.3.2).
* Embedded frames: gives untitled iframes a title (4.1.2).
* Page language: adds or corrects the lang attribute (3.1.1).
* Skip links: adds one when the page has none, or repairs one whose target is missing (2.4.1; WordPress 6.9 or later).
* Zoom: removes viewport settings that block pinch-zoom (1.4.4; WordPress 6.9 or later).
* Keyboard order: removes positive tabindex values and takes hidden duplicate links out of the tab order (2.4.3, 4.1.2).
* Menus: labels theme menus with their menu location name (1.3.1, 2.4.1).
* "Read more" links: adds the post title to their accessible name (2.4.4).
* Decorative icons in named links and buttons, and empty headings: hidden from screen readers (1.1.1, 1.3.1).
* Optional, off until you turn them on: alt text for empty alt attributes, product image alt from the product name, new-tab notices, a visible focus outline, unique IDs for repeated form fields, and focus fixes inside hidden containers.

= What it reports for a person =

* Text contrast where an element sets both its text and background colour itself; other text is marked "not checked".
* Heading structure, landmarks, page titles and repeated IDs (whole pages, WordPress 6.9 or later).
* Alt text that looks like a file name, repeats nearby text or is too long, and images it could not describe.
* Links, buttons and fields it could not name, fields with no visible label, and link text such as "Click here".
* Autoplaying media, data tables without headers, and controls that are not real buttons or links.

= What it does not do =

* It does not change the content stored in your database. Repairs happen while the page is built and stop the moment you deactivate the plugin.
* It does not repair markup that JavaScript creates after the page loads, such as slider copies or the WooCommerce Cart and Checkout blocks; reports list such content as "not checked".
* It does not invent alt text, change colours or fix how complex widgets behave with a keyboard.

= How it works =

* All processing happens on your server. The plugin sends nothing to SoftGlaze or to anyone else.
* Activating it changes nothing your visitors see: recommended repairs start in Preview.
* It only adds or corrects attributes in your theme's and plugins' markup and never adds, removes or moves their elements. It prints only its own skip link, a hidden "opens in a new tab" note and two small stylesheets, each when that repair is on.
* If anything goes wrong while repairing a page, or the page is very large, your visitors get the page without repairs.
* Whole-page repair (theme headers and footers, builder templates, the page head) uses the page-processing step built into WordPress 6.9 and later. On WordPress 6.5 to 6.8 the plugin repairs post content, template parts, widgets, search forms, menus and featured images only.
* An activity log keeps a dated record of the repairs you turned on and what each scan found.

= Compatibility =

Tested on WordPress 6.5 (PHP 7.4) and WordPress 7.1 (PHP 8.3 and 8.4). On WordPress 7.1 it was tested with Elementor 4.3.5 (free version), WooCommerce 11.2.1 and the themes Twenty Twenty-Five 1.5, Astra 4.14.1 and Kadence 1.5.2.
Some page builders switch off the WordPress page-processing step; there the page-wide repairs (skip links, zoom, repeated form IDs) and page-level reports do not run, and the Overview says so after a scan.
WooCommerce® and its associated designs are trademarks of Automattic Inc.
Elementor, Astra and Kadence are trademarks of their respective owners.
This plugin is not affiliated with or endorsed by Automattic or any other owner named here.

= Privacy =

The plugin stores scan reports about your own pages (element names and attributes, never form values) and an activity log with the WordPress user IDs of the administrators who changed its fixes or settings or ran scans. It sets no cookies, collects nothing about your visitors and sends nothing off your site.

= External services =

This plugin does not use any external service and its PHP code makes no HTTP requests. When you scan pages from the Reports screen or with "Scan this page" in the admin bar, your own browser opens those pages on your own site while you are logged in; nothing is sent anywhere else.

Links shown in the admin screens but never requested by the plugin:

* W3C "Understanding WCAG 2.2" pages, one per success criterion: https://www.w3.org/WAI/WCAG22/Understanding/

== Installation ==

1. Install and activate the plugin from Plugins > Add New Plugin.
2. Open Accessibility Fixes > Overview and follow the three steps: scan your home page, review the changes, go live.
3. If you use a page cache, purge it after changing a repair so visitors get the new version.

== Frequently Asked Questions ==

= Is this plugin enough to meet accessibility laws or WCAG? =

No. No plugin can provide 100% compliance with accessibility laws or standards such as the ADA, the European Accessibility Act or WCAG.
This plugin repairs a set of common, machine-detectable errors in your page HTML and lists what still needs a person: colour contrast, heading structure, the quality of alt text, keyboard behaviour of menus and sliders, captions and more. Whether people with disabilities can use your site is something only testing by people can show. This plugin does not give legal advice.

= Is this an overlay? =

No. Visitors get no toolbar, widget or extra JavaScript. The repairs are in the HTML your server sends.

= What happens when I activate it? =

Nothing changes for visitors. Recommended repairs start in Preview. Use "View repaired" in the admin bar, or the report of a scanned page, to see each change before you go live.

= Can it break my layout? =

Most repairs change attributes such as alt, aria-label, lang and tabindex, which do not change how the page looks. A few can show: an empty submit button gets the text "Submit" or "Search", the skip link appears on keyboard focus, the optional focus outline is visible, and optional unique IDs for repeated form fields can affect styles that select them. You can switch any repair off, skip it on one page, or exclude paths.

= Does it work with page caching? =

Yes. Cached pages contain the repairs. After changing a repair, purge your page cache; the Fixes and Overview screens remind you and name the cache plugin they detect. Scans and admin views are marked as not cacheable.

= Will it slow my site down? =

On our test site (WordPress 7.1, PHP 8.3, WooCommerce active) the repairs took a median of 1 to 8 ms of server time on real pages of 70 to 150 KB (95th percentile at most 10 ms) and about 11 ms (95th percentile at most 15 ms) on a 200 KB page. With the recommended fixes Live and no page cache, the full response took a median of 0 to 7 ms longer (one reading of 18 ms did not repeat), and the first byte came up to 14 ms later when the page-wide repairs hold it until the page is complete. Cached pages take no extra time.

= Why does whole-page repair need WordPress 6.9? =

WordPress 6.9 added a built-in step that lets plugins process the finished page. This plugin uses it rather than capturing the page itself.

= How do I turn everything off quickly? =

Set a repair to Off, deactivate the plugin, or add define( 'SOFTGLAZE_A11Y_DISABLE', true ); to wp-config.php.

= What happens when I deactivate or delete it? =

Deactivating stops every repair at once and keeps your settings and reports. Deleting keeps them too, unless you turned on "Delete all data when the plugin is deleted" in Settings.

== Screenshots ==

1. The Fixes screen: each repair has its own Off, Preview and Live switch, with the WCAG success criterion and the risk.
2. Report details for one page: every change made, with the element, the attribute and the value before and after.
3. "Needs a person": the issues the plugin cannot safely repair, such as contrast, heading order and alt text quality.
4. The admin bar on a front-end page: view the original page, view the repaired page, or scan it.
5. The Reports screen after scanning the suggested pages.
6. The activity log: a dated record of the repairs turned on and the scans run.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
