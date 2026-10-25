=== Tagmend – Accessibility Fixes ===
Contributors: softglaze
Tags: accessibility, wcag, a11y, alt text, aria
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accessibility fixes for theme, page builder and WooCommerce HTML: common WCAG failures repaired on your server. No overlay. Preview each fix.

== Description ==

Tagmend adds an Accessibility Fixes menu to the WordPress admin. This development version adds missing image alt text from the Media Library, gives images inside named links and buttons an empty alt, names empty links and buttons from reliable sources (your site name, email and phone links, known social networks, search forms, menu toggles and icon classes), names form fields without a label from their placeholder or a known field name, adds the post title to "Read more" links, hides decorative icons inside named links and buttons, resets positive tabindex values, takes hidden duplicate links out of the keyboard tab order, labels theme menus with their menu location name, hides empty headings where that leaves the heading levels in order, and repairs untitled iframes, a missing or malformed page language and viewport tags that block zooming. On WordPress 6.9 or later it also adds a skip link to pages that have none and repairs skip links whose target is missing; when another plugin switches off the WordPress page buffer these repairs need (some page builders do), those page-wide repairs and the viewport repair do not run, and the other repairs still work on post content, template parts, widgets and menus. Filling empty alt text from the Media Library, using the product name for WooCommerce product images, taking focusable items inside hidden content out of the tab order, giving repeated form fields their own ids (WordPress 6.9 or later), noting links that open a new tab and a visible focus outline are available and start Off. Each fix has its own Off, Preview and Live setting; nothing changes for visitors until a fix is set to Live.
