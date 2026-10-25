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

This plugin relies on the PaymentForm.io service, operated by PaymentForm.io, to connect your site and display your forms. Nothing is sent before you open the plugin's setup or connect screens.

* **PaymentForm API (`api.paymentform.io`)**
    * When you open the account-setup wizard, it asks the API to check that your site is reachable. This sends your site URL, a one-time setup token and the address of the site's setup-proof endpoint.
    * When you create an account from the wizard, it sends the email address you enter, your country, your site language, your site URL, the setup token and the setup-proof address. The verification code you then type is sent to confirm the email address, and you can ask for it to be resent.
    * When you connect an existing account, the one-time code returned by PaymentForm.io is exchanged for a connection token, along with your site URL.
    * While connected, the admin pages and the block editor send the connection token to read your account details and list your forms. The token is refreshed automatically before it expires. Opening the PaymentForm dashboard from the plugin requests a one-time sign-in link.
    * When you disconnect, the connection token is revoked.
* **PaymentForm app (`app.paymentform.io`)**: opened in your browser when you sign in, connect the site or open the dashboard from the admin page.
* **Form pages (`<your-workspace>.paymentform.io`)**: embedded forms load from your workspace's subdomain whenever a visitor views a page containing a form, and only while the site is connected. Payment details entered into a form go directly to PaymentForm.io and its payment processors, never through your WordPress site.
* **Requests from PaymentForm.io to your site**: during setup PaymentForm.io calls `/paymentform/setup-proof` on your site to confirm you control it, after connecting it redirects you back to `/paymentform/callback`, and it may send signed event notifications to `/paymentform/webhook`.

Terms of Service: https://paymentform.io/terms-conditions/
Privacy Policy: https://paymentform.io/privacy-policy/

== Source Code ==

The admin interface and blocks ship as compiled, minified JavaScript in `assets/`. Their human-readable source and build configuration are included in this plugin (`frontend/`, `package.json`, `pnpm-lock.yaml`, `tsconfig.json`, `vite.config.ts`) and are also available at https://github.com/Bit-Apps-Pro/paymentform-wp-plugin.

To rebuild the assets from source:

1. Install Node.js 24 and pnpm 11.
2. Run `pnpm install`, then `pnpm build`.

Licenses of bundled third-party code are listed in `licenses/`.

== Changelog ==

= 0.1.0 =
* Initial release.
