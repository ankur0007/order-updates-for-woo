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

**Live demo:** [Try it instantly on TasteWP](https://tastewp.com/recipe/orderupdatesforwoo) — a throwaway WordPress + WooCommerce site with the plugin pre-installed, no signup needed.

**Store managers can:**

* Create and edit order updates directly from the WooCommerce order edit screen
* Assign updates to team members manually, or rotate them automatically through a round-robin pool
* Add internal staff notes and customer-visible notes side by side
* @mention teammates inline — they get an admin-bar notification and an email
* Mark updates resolved, re-open them, or block notes once solved
* Upload attachments to any note (images, PDFs, docs)
* Mute email notifications per update so a chatty thread doesn't flood your inbox
* See assigned items, mentions, and replies in the WordPress admin bar with deep-link rows
* Track each team member's queue on a dedicated Assignments page — store-wide waiting, resolved, and longest-wait stats, filterable by assignee and status
* Work through a full Notifications inbox — filter by Unread, Favorite or Archived, search, and mark read / favorite / archive / delete in bulk (auto-archives and auto-clears on a schedule)
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
5. Assignments page — per-assignee queue with waiting, resolved, and longest-wait stats
6. Notifications inbox — filter, search, and bulk actions across mentions, assignments, and replies

== Changelog ==

= 1.0.0 =

Initial public release.

* Order updates panel on the WooCommerce order edit screen with internal notes, customer notes, assignees, mentions, attachments, and resolution state.
* @mention autocomplete with per-mention email + admin-bar notifications.
* Round-robin assignment mode for fair workload distribution across staff.
* WordPress admin bar surface for assigned items, mentions, customer replies, and staff replies — with deep-link rows.
* Assignments page — a per-assignee queue with store-wide waiting / resolved / longest-wait cards, filterable by assignee and status; non-admins see only their own.
* Notifications page — a full inbox with All / Unread / Favorite / Archived tabs, search, per-row and bulk actions (read, favorite, archive, delete), and scheduled auto-archive then auto-clear.
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

Order Updates for Woo connects to three external services to provide optional features. Below is a full disclosure of each service, what data is sent, and when.

= Newsletter signup (newsletter.orderupdatesforwoo.com) =

Used to subscribe the site administrator to the plugin's product update newsletter. Only active when the administrator types an email into the welcome screen signup form and clicks "Subscribe". Not required for the core order-updates flow.

* **Subscribe action:** Sends the email address the administrator typed and the site's home URL to a Cloudflare Worker we operate at newsletter.orderupdatesforwoo.com, which then forwards the email to Mailchimp for list subscription. Nothing is sent without an explicit click.

Service provider: Cloudflare Workers (Cloudflare, Inc.) and Mailchimp (Intuit Inc.)
Privacy policy: https://www.cloudflare.com/privacypolicy/ and https://www.intuit.com/privacy/statement/
Terms of service: https://www.cloudflare.com/website-terms/ and https://mailchimp.com/legal/terms/

= Plugin review form (api.web3forms.com) =

Used to collect optional plugin feedback from administrators via a dismissible 5-star review notice in the WordPress admin. Not required for any core feature; nothing is sent unless the administrator submits the form.

* **Submit review:** Sends the star rating the administrator clicked, the administrator's WordPress display name, the administrator's WordPress email address, an optional public profile or store URL, and an optional short message to api.web3forms.com. The endpoint then forwards the submission to the plugin author. Only fires when the administrator clicks a star and then clicks "Submit rating".

Service provider: Web3Forms
Privacy policy: https://web3forms.com/privacy
Terms of service: https://web3forms.com/terms

= Staff avatars on the customer-facing page (gravatar.com) =

Used to render staff member avatars next to their replies on the customer-facing order updates page. Only active when the site administrator turns on the "Show assignee to customers" setting, which is off by default. Uses WordPress's built-in get_avatar_url() function.

* **Avatar image load:** When the customer's browser renders the page, it requests the staff avatar image from gravatar.com using an MD5 hash of the staff member's email address (the standard Gravatar URL). The plugin itself does not transmit any email — the image request happens in the customer's browser as part of loading the page.

Service provider: Gravatar (Automattic Inc.)
Privacy policy: https://automattic.com/privacy/
Terms of service: https://wordpress.com/tos/
