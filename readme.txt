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

== External Services ==

This plugin connects to a small number of third-party services. All three are optional or limited to administrator-initiated actions in the WordPress admin. None of them are needed for the core order-updates flow itself.

= 1. Newsletter signup (Mailchimp via a Cloudflare Worker proxy) =

What it is: an opt-in newsletter signup form on the plugin's welcome/admin screen.

What is sent and when: only when the site administrator types an email into the form and explicitly clicks "Subscribe", the plugin sends the email address and the site's home URL to a Cloudflare Worker we operate. The Worker forwards the email to Mailchimp's API for list subscription. Nothing is sent automatically and nothing is sent without the administrator's click.

Endpoint: https://shrill-breeze-aef9.order-update-for-woocommerce.workers.dev/subscribe

Terms of service: https://www.cloudflare.com/website-terms/ (Cloudflare Workers, the relay) and https://mailchimp.com/legal/terms/ (Mailchimp, the list provider).
Privacy policy: https://www.cloudflare.com/privacypolicy/ (Cloudflare) and https://mailchimp.com/legal/privacy/ (Mailchimp).

= 2. In-admin plugin review form (Web3Forms) =

What it is: an inline 5-star rating form shown to administrators in a dismissible admin notice on plugin pages. Administrators can leave a star rating and an optional short message that reaches the plugin author.

What is sent and when: only when an administrator clicks a star and then clicks "Submit rating", the form posts the star rating, the administrator's WordPress display name, the administrator's WordPress email address, an optional "public profile / store" URL they type in, and an optional short message to Web3Forms, which forwards it to the plugin author's inbox. Nothing is sent unless the administrator actively submits the form. Dismissing or snoozing the notice sends nothing.

Endpoint: https://api.web3forms.com/submit

Terms of service: https://web3forms.com/terms
Privacy policy: https://web3forms.com/privacy

= 3. Gravatar (avatar images for staff replies) =

What it is: WordPress's built-in avatar service, provided by Automattic via Gravatar. The plugin uses the standard `get_avatar_url()` WordPress function to render staff member avatars next to their replies on the customer-facing order updates page. This only renders avatars when the "Show assignee to customers" setting is enabled — by default it is off, and the staff avatar is not shown to customers.

What is sent and when: when the customer-facing page renders a staff avatar, the customer's browser requests the avatar image from gravatar.com using an MD5 hash of the staff member's email address (the standard Gravatar URL). The site itself does not transmit the email; the request happens in the customer's browser as part of loading the image.

Terms of service: https://wordpress.com/tos/
Privacy policy: https://automattic.com/privacy/
