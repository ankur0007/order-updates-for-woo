=== Order Updates for Woo ===
Contributors: the-ank
Tags: woocommerce, customer support, help desk, support tickets, order status
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Customer support help desk built into WooCommerce orders — threaded staff and customer conversations, assignees, @mentions, attachments, and ratings.

== Description ==

Order Updates for WooCommerce adds a dedicated order-updates workflow for store teams and customers — replacing scattered order notes with clean, threaded conversations per topic.

**Official website:** [orderupdatesforwoo.com](https://orderupdatesforwoo.com) — see every feature in detail, submit a support ticket, and read or share real, verified reviews.

**Store managers can:**

* Create and edit order updates directly from the WooCommerce order edit screen
* Assign updates to team members manually, or rotate them automatically through a round-robin pool
* Add internal staff notes and customer-visible notes side by side
* @mention teammates inline — they get an admin-bar notification and an email
* Mark updates resolved, re-open them, or block notes once solved
* Upload attachments to any note (images, PDFs, docs)
* Mute email notifications per update so a chatty thread doesn't flood your inbox
* See assigned items, mentions, and replies in the WordPress admin bar with deep-link rows
* View an analytics dashboard with totals, resolution rate, average rating, per-assignee performance, and per-product breakdowns
* Get an email for every customer reply, even when not @mentioned — assignees are always notified

**Customers can:**

* View their order updates from My Account, or via a secure guest link in their email
* Open a new update or reply to an existing thread with text, emoji, and attachments
* See who's assisting them ("Anita is assisting you with this order update")
* Auto-scroll to new replies on a 30-second poll — no refresh needed
* Edit their own notes within the configurable edit window
* Rate resolved updates with stars and an optional comment
* Get a follow-up email branched on rating: 4★/5★ get a friendly share prompt; 1–3★ get an empathetic reply CTA

**Embed anywhere:**

The customer-facing portal works on My Account out of the box. Use the `[order_updates_portal order_id="" order_key=""]` shortcode to embed it on Elementor, Divi, Gutenberg, or any custom page.

**Trademark notice:** WooCommerce and Woo are trademarks of Automattic Inc. This plugin is independent and is not affiliated with, endorsed by, or sponsored by Automattic or WooCommerce. The names are used only to describe what the plugin works with.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/order-updates-for-woo/` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Make sure WooCommerce is installed and active.
4. Open a WooCommerce order to start using the Order Updates panel.
5. (Optional) Visit *WooCommerce → Settings → Order Updates* to configure assignment mode, edit window, share text, and email defaults.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

Yes. WooCommerce must be installed and active.

= Can customers view updates without logging in? =

Yes. Guests open the page through a secure order-key link delivered in their notification emails.

= Does the plugin support WooCommerce HPOS (High-Performance Order Storage)? =

Yes. HPOS compatibility is declared and tested.

= How does round-robin assignment work? =

In *Settings → Order Updates*, switch assignment mode to "Round robin" and pick the staff users in the pool. Each new update is assigned to the next person in the rotation.

= Can I embed the customer page on a custom landing page? =

Yes. Use the `[order_updates_portal]` shortcode. It works with Elementor, Divi, the block editor, and standard WordPress page templates.

= Will the analytics page slow down on large stores? =

No. Analytics queries are bounded by date range, indexed on `created_at`, transient-cached, and pre-warmed daily by WP-Cron. First admin visit of the day always hits cache.

== Screenshots ==

1. Order updates panel on the WooCommerce order edit screen
2. Customer-facing order updates page
3. Analytics dashboard with totals, charts, and per-assignee/product breakdowns
4. Order list column and filters for updates

== Changelog ==

= 1.0.0 =

Initial public release.

* Order updates panel on the WooCommerce order edit screen with internal notes, customer notes, assignees, mentions, attachments, and resolution state.
* @mention autocomplete with per-mention email + admin-bar notifications.
* Round-robin assignment mode for fair workload distribution across staff.
* WordPress admin bar surface for assigned items, mentions, customer replies, and staff replies — with deep-link rows.
* Email deep links — every notification (assignee, admin, mention, customer) opens directly on the relevant update.
* Customer My Account experience: per-update unread badge, auto-scroll on landing, threaded replies with emoji and attachments, optional rating + comment on resolved updates, "Anita is assisting you" personalisation.
* 30-second poll on the customer page so new staff replies appear without refresh, including the rating box auto-appearing once an update is resolved.
* Customer rating follow-up email — promoters see share buttons, detractors see an empathy CTA.
* Edit-with-history for both staff and customer notes, with a configurable edit window.
* Guest-friendly customer URL with order key for non-logged-in access; new-update creation is rate-limited to prevent abuse.
* `[order_updates_portal]` shortcode for embedding the customer page on Elementor, Divi, Gutenberg, or custom templates.
* Analytics dashboard: totals, resolution rate, average rating, per-day chart, per-assignee table, per-product table — with date-range filters and indexed, cached queries that scale to large stores.
* Per-update email mute toggle so staff aren't flooded by busy threads.
* Customer email preference toggle so customers can opt out of update emails.
* WooCommerce HPOS compatibility declared.

== Upgrade Notice ==

= 1.0.0 =

Initial public release.

== External services ==

This plugin connects to three third-party services. Each one is either fully opt-in or limited to a specific feature, and none are required for the core order-updates flow. The data sent, the trigger, and the provider's policies for each service are listed below.

= Newsletter signup (Cloudflare Workers + Mailchimp) =

This plugin includes an optional newsletter signup form on the plugin's welcome screen. It is used to subscribe the site administrator to the plugin's product updates list.

It sends the email address the administrator types into the form and the site's home URL, only when the administrator types an email and explicitly clicks "Subscribe". Nothing is sent automatically and nothing is sent without that click. The request goes to a Cloudflare Worker we operate, which forwards the email to Mailchimp's list-subscribe API.

This service is provided by Cloudflare (the relay) and Mailchimp (the list provider): [Cloudflare terms of use](https://www.cloudflare.com/website-terms/), [Cloudflare privacy policy](https://www.cloudflare.com/privacypolicy/), [Mailchimp terms of use](https://mailchimp.com/legal/terms/), [Mailchimp privacy policy](https://mailchimp.com/legal/privacy/). The endpoint the plugin posts to is https://newsletter.orderupdatesforwoo.com/subscribe.

= In-admin plugin review form (Web3Forms) =

This plugin shows a dismissible 5-star review notice to administrators inside the WordPress admin. It is used to collect optional feedback that reaches the plugin author.

It sends the star rating the administrator clicks, the administrator's WordPress display name, the administrator's WordPress email address, an optional public profile or store URL the administrator types in, and an optional short message, only when the administrator clicks a star and then clicks "Submit rating". Nothing is sent if the administrator dismisses, snoozes, or ignores the notice.

This service is provided by Web3Forms: [terms of use](https://web3forms.com/terms), [privacy policy](https://web3forms.com/privacy). The endpoint is https://api.web3forms.com/submit.

= Avatars on the customer-facing page (Gravatar) =

This plugin uses WordPress's built-in `get_avatar_url()` function to show staff member avatars on the customer-facing order updates page. This is only used when the site administrator turns on the "Show assignee to customers" setting, which is off by default.

When that setting is on, the customer's browser requests the staff avatar image from Gravatar using an MD5 hash of the staff member's email address (the standard Gravatar URL). The plugin itself does not transmit the email; the image request happens in the customer's browser when it loads the avatar.

This service is provided by Automattic (Gravatar): [terms of use](https://wordpress.com/tos/), [privacy policy](https://automattic.com/privacy/).
