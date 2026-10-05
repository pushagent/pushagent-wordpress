=== Push Agent ===
Contributors: pushagent
Tags: push notifications, web push, woocommerce, order notifications, browser notifications
Requires at least: 4.7
Tested up to: 7.1
Requires PHP: 5.6
Stable tag: 2.2.0
WC requires at least: 3.0
WC tested up to: 10.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free web push notifications for WordPress, plus WooCommerce order-status notifications for your customers.

== Description ==

Push Agent lets your visitors subscribe to browser notifications with one click, and lets you send them news, offers and updates from your Push Agent dashboard at [app.pushagent.net](https://app.pushagent.net). Shops on WooCommerce can also notify customers automatically when their order status changes.

One plugin does both. Paste your access token and you're live; WooCommerce features appear only when WooCommerce is installed.

= Web push notifications =

* No code to paste: the plugin adds the Push Agent script to every page.
* Creates the `pushagent-sw.js` service worker in your site's root folder automatically (and serves it through WordPress if the folder isn't writable).
* A friendly "Allow notifications" card for new visitors; you choose how many seconds to wait before it appears, and "Not now" hides it for 3 days.
* Send to all subscribers or target them by country, device and browser; schedule notifications for later (from your Push Agent dashboard).
* Works in Chrome, Edge, Firefox, Opera, Samsung Internet and Safari.
* Standard Web Push. No Firebase and no Google account needed.

= WooCommerce order updates (Push Agent Growth plan) =

Send customers a browser notification when their order status changes, for example "Packing" or "Handed to courier".

* Works with every order status, including custom statuses added by other plugins.
* Switch each status on or off, and write your own messages with placeholders like {first_name}, {order_number}, {status} and {total}. A live preview shows how the notification will look.
* A "Get updates about this order" box on the order confirmation page asks at the best moment.
* Every send is recorded as an order note, on the plugin dashboard and in your Push Agent dashboard.
* Customers are identified by a scrambled code made from their email with your API key. The email itself is never sent to Push Agent.
* Compatible with WooCommerce High-Performance Order Storage (HPOS).

= A dashboard inside WordPress =

* See your subscribers, your plan and the order updates delivered in the last 30 days at a glance.
* A setup checklist and a health check tell you exactly what is done and what still needs attention.
* Quick links to send a notification, see campaign results and manage subscribers.

Your site must use HTTPS for browsers to allow push notifications (localhost works for testing).

= External service =

This plugin relies on the Push Agent service at [app.pushagent.net](https://app.pushagent.net), run by the makers of this plugin. It is needed to store subscriptions and deliver notifications. What is sent, and when:

* **Every front-end page** loads a script from app.pushagent.net. When a visitor clicks "Allow", their browser's push subscription (an address from the browser's push service) and the browser language are sent to Push Agent, which also records the visitor's IP address and browser user agent to note their approximate city and country and their browser and device type (used for targeting and statistics). Nothing is stored for visitors who don't subscribe.
* **For logged-in customers and on the order confirmation page** (only when WooCommerce order updates are switched on), a scrambled customer code is attached to the subscription. It is a one-way code made from the customer's email and your API key; the email itself is not sent.
* **When an order changes to a status you switched on**, the plugin sends Push Agent the notification title and message you wrote (with the placeholders filled in, so it can include the customer's first name, last name, order number, status and total if you use those placeholders), a link to the order, and the customer codes.
* **When you open the plugin's admin screens** with an API key saved, the plugin asks Push Agent which website and plan the key belongs to, and for your subscriber and order-update counts (cached for 10 minutes).

Service website: [pushagent.net](https://pushagent.net). Privacy policy: [pushagent.net/privacy-policy](https://pushagent.net/privacy-policy/).

== Installation ==

1. Install and activate the plugin.
2. Sign in at [app.pushagent.net](https://app.pushagent.net) (it's free) and add your website.
3. Open **Integration** and copy your **access token**.
4. In WordPress go to **Push Agent → Settings**, paste the token and click **Save settings**.
5. Back in the Push Agent dashboard, click **Verify integration**. The plugin's **Push Agent → Dashboard** shows a checklist of anything left to do.

= Set up WooCommerce order updates =

1. In your Push Agent dashboard open **API & WooCommerce** and create an API key (Growth plan or higher).
2. In WordPress go to **Push Agent → Settings**, paste the API key and save.
3. Open **Push Agent → Order updates**, switch on the statuses you want and write your messages.

== Frequently Asked Questions ==

= Is Push Agent free? =

Yes. The Free plan includes one website and up to 2,500 subscribers. Paid plans add more websites and subscribers, custom opt-in text, scheduling, targeting, the API and WooCommerce order updates. See [pushagent.net/pricing](https://pushagent.net/pricing/).

= Do I need an API key? =

Only for WooCommerce order updates, and to see your subscriber numbers on the plugin dashboard. Regular push notifications work with just the access token.

= Visitors don't see the "Allow notifications" card =

Wait for the delay you set in **Push Agent → Settings**, and use a private window. Browsers only show it on HTTPS sites. If you already allowed or blocked notifications on your site, it won't appear again: reset the permission from the padlock icon in the address bar.

= Why did a customer not get an order update? =

They only get one in browsers where they allowed notifications. The order notes and the plugin dashboard say what happened for every status change.

= The dashboard says pushagent-sw.js is missing =

Your root folder isn't writable, or WordPress is installed in a sub-folder. Download `pushagent-sw.js` from the Integration page of your Push Agent dashboard and upload it to the root folder of your domain (usually the same folder as `wp-config.php`).

= I use a caching plugin or Cloudflare =

Clear the cache once after saving the settings. The script is marked so Rocket Loader and optimisation plugins leave it alone.

= Does it work on multisite? =

Yes. Activate it on each site and enter that site's own access token. The service worker is served per site by WordPress.

= Do order updates count towards my subscriber limit? =

No. They go to people who are already subscribers.

== Screenshots ==

1. The Push Agent dashboard in WordPress: subscribers, plan, order updates, setup checklist and health check.
2. WooCommerce order updates: your own message for every order status, with a live preview.
3. Settings: connect your site with your access token and choose when visitors are asked.
4. Help: answers to common questions and useful links.

== Changelog ==

= 2.2.0 =
* New: Push Agent menu with its own Dashboard showing subscribers, plan, order updates sent, a setup checklist and a health check.
* New: Recent order updates on the dashboard.
* New: Help screen with answers to common questions.
* Improved: Redesigned Settings and Order updates screens, with an on/off switch per order status, clickable placeholders and a live notification preview.
* Improved: The Push Agent address is no longer a setting; the plugin always connects to app.pushagent.net.
* The old Settings → Push Agent page now redirects to the new screens. Your settings are kept.

= 2.1.0 =
* New: WooCommerce order updates - notify customers when their order status changes (Push Agent Growth plan).
* Supports custom order statuses, your own messages per status, and a "Get updates about this order" box after checkout.
* Compatible with WooCommerce High-Performance Order Storage.

= 2.0.0 =
* Rebuilt for the new Push Agent (app.pushagent.net) with standard Web Push.
* Your access token is kept when you update.
* Creates pushagent-sw.js automatically.
* New settings: when to ask visitors, and a status check on the settings page.
* Existing subscribers are moved over silently the next time they visit.

= 1.0.1 =
* Help screenshots updated.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 2.2.0 =
A new Push Agent menu with a dashboard, setup checklist and redesigned settings. Your settings are kept.

= 2.1.0 =
Adds WooCommerce order-status notifications. Your settings are kept.

= 2.0.0 =
Required: the old Push Agent service has been retired. Update to keep sending notifications. Your access token is kept.
