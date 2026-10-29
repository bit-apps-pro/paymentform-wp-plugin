=== PaymentForm ===
Contributors: bitpressadmin
Tags: payment form, donation, stripe, checkout, forms
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connector for the PaymentForm.io service: embed your hosted payment and donation forms in WordPress pages and posts.

== Description ==

[PaymentForm.io](https://paymentform.io) is a hosted service (Software as a Service) for building payment and donation forms, taking payments through supported payment processors, and managing submissions and transactions. This plugin is the official connector that links your WordPress site to your PaymentForm.io workspace, so you can place those forms anywhere on your site.

The forms themselves, payment processing and your form data all live on PaymentForm.io. The plugin does not build forms, process payments or store payment details in WordPress, and it needs a PaymentForm.io account to do anything beyond its connect screen.

What the plugin does:

* Connect your site to PaymentForm.io from the plugin's admin page, either by creating an account or by signing in to an existing one.
* Browse your forms and preview them at mobile, tablet and desktop sizes.
* Embed a form with the "PaymentForm Form" block or the `[paymentform id="..."]` shortcode.
* Open your PaymentForm.io dashboard to build and edit forms, connect payment processors and review submissions.

Every feature of the plugin is available to any connected account; the plugin contains no locked, trial or premium-only code.

== Installation ==

1. Upload the plugin through Plugins → Add New → Upload Plugin, or extract it into `wp-content/plugins/`.
2. Activate it from the Plugins screen.
3. Open the PaymentForm admin page and connect your site to your PaymentForm.io account.

== Frequently Asked Questions ==

= Do I need a PaymentForm.io account? =

Yes. PaymentForm.io is an external hosted service: forms are created and payments are processed there, and this plugin embeds them on your site. You can create an account from the plugin's admin page.

= Where are forms and payments handled? =

On PaymentForm.io. Embedded forms load from your workspace's PaymentForm.io address, and payment details go straight to PaymentForm.io and its payment processors without passing through your WordPress site.

= What happens if I disconnect or deactivate the plugin? =

Embedded forms stop showing on your site. Your forms, submissions and transactions stay in your PaymentForm.io account.

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

The admin interface and blocks ship as compiled, minified JavaScript in `assets/`. Their human-readable source and build configuration (`frontend/`, `package.json`, `pnpm-lock.yaml`, `tsconfig.json`, `vite.config.ts`) are in the public repository at https://github.com/bit-apps-pro/paymentform-wp-plugin.

To rebuild the assets from source:

1. Clone the repository, then install Node.js 24 and pnpm 11.
2. Run `pnpm install`, then `pnpm build`.

Licenses of bundled third-party code are listed in `licenses/`.

== Changelog ==

= 0.1.0 =
* Initial release.
