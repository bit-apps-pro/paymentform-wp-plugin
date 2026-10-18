=== PaymentForm ===
Contributors: bitapps
Tags: payment form, donation, stripe, checkout, forms
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed payment forms from PaymentForm.io into WordPress pages and posts.

== Description ==

PaymentForm connects your WordPress site to your [PaymentForm.io](https://paymentform.io) workspace so you can place your payment and donation forms anywhere on your site.

* Connect your site to PaymentForm.io from the plugin's admin page.
* Browse your forms and preview them at mobile, tablet and desktop sizes.
* Embed a form with the "PaymentForm Form" block or the `[paymentform id="..."]` shortcode.

== Installation ==

1. Upload the plugin through Plugins → Add New → Upload Plugin, or extract it into `wp-content/plugins/`.
2. Activate it from the Plugins screen.
3. Open the PaymentForm admin page and connect your site to your PaymentForm.io account.

== Frequently Asked Questions ==

= Do I need a PaymentForm.io account? =

Yes. Forms are created and payments are processed on PaymentForm.io; this plugin embeds them on your site.

== External services ==

This plugin relies on the PaymentForm.io service to connect your site and display your forms.

* **PaymentForm API (`api.paymentform.io`)**: used when you connect the site and whenever the admin pages or the block editor list your forms. It receives your site URL, the email address you enter during setup, the verification code sent to that address, and the site's connection token.
* **PaymentForm app (`app.paymentform.io`)**: opened when you sign in or connect the site from the admin page.
* **Form pages (`<your-workspace>.paymentform.io`)**: embedded forms load from your workspace's subdomain whenever a visitor views a page containing a form. Payment details entered into a form go directly to PaymentForm.io and its payment processors, never through your WordPress site.

Terms of Service: TODO
Privacy Policy: TODO

== Source Code ==

The admin interface ships as compiled, minified JavaScript in `assets/`. The human-readable source and build tooling are available at https://github.com/Bit-Apps-Pro/paymentform-wp-plugin.

To rebuild the assets from source:

1. Install Node.js 24 and pnpm 11.
2. Run `pnpm install`, then `pnpm build`.

== Changelog ==

= 0.1.0 =
* Initial release.
