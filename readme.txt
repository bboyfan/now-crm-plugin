=== NOW CRM ===
Contributors: bboyfan
Tags: crm, contacts, email marketing, marketing automation, customer data
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted CRM for WordPress and WooCommerce: contacts, lists, tags, campaigns, automation, forms, and abandoned-cart email.

== Description ==

NOW CRM is a self-hosted contact relationship manager. It uses WordPress and WooCommerce APIs. It does not call a hosted CRM at runtime.

= Contacts and lists =

* One contact record with email, profile fields, source, and status history.
* Seven statuses: subscribed, pending, unsubscribed, transactional, bounced, complained, and spammed.
* Lists, tags, segments, custom fields, notes, and CSV import.
* WordPress user link and a WordPress personal data exporter.

= WooCommerce =

* Optional. Without WooCommerce, contacts and lists still work. Order and cart features stay off.
* Order sync uses WooCommerce CRUD and supports high-performance order storage (HPOS).
* New orders, updates, billing email changes, status changes, refunds, and deletes update the contact and order link.
* A customer list groups WooCommerce contacts. New installs use the neutral name "Customers". An upgrade reuses an existing customer list and does not rename or duplicate it.
* Checkout consent checkbox and double opt-in are optional and off by default on a fresh install.

= Email and campaigns =

* Persistent email queue with claim, lease, retry, and a pre-send recheck.
* Campaigns with the block editor or raw HTML, preview, test send, scheduling, pause, resume, and cancel.
* Recurring campaigns, templates, sequences, and automations.
* Signed unsubscribe links with single use and one-click POST support.
* Optional open and click tracking. The site and each campaign start with tracking off. If you turn off site tracking, links already sent still work but stop recording events.

= Forms and cart recovery =

* Native forms with nonce, honeypot, rate limit, validation, and double opt-in handoff.
* Optional abandoned-cart capture on Classic and block checkout. When enabled, it records checkout email and cart data before order placement.
* One reminder by default, a signed restore link, and a lost-cart window.
* The restore link checks products, variations, stock, and coupons before it changes the cart. A failed restore keeps the current cart.

= Delivery events and integrations =

* Optional Amazon SES event handling through Amazon SNS. SNS signature verification and a topic allowlist run before any event is applied.
* Hard bounces and complaints can suppress an address. Transient bounces do not permanently block it.
* Outbound webhooks with HMAC signatures and retry.
* Inbound contact and handler webhooks with scoped keys.
* WP-CLI commands under `wp nowcrm`.

= Requirements =

* PHP 8.3 or later.
* WordPress 6.7 or later.
* WooCommerce 8.0 or later for order and cart features. WooCommerce is optional.
* A working WordPress mail transport for real email.

= First run =

Activation creates the plugin tables, default settings, and capabilities. It does not import data, sync orders, or send email.

A fresh install keeps these features off: WooCommerce order sync, automatic subscription of new WooCommerce contacts, real email sending, abandoned-cart capture, and campaign tracking. Enable only the features you need.

Before you enable sending, configure a mail transport for WordPress. The plugin does not include an SMTP account or mail provider.

= Limits =

* `wp_mail()` returning true means the mail stack accepted the message. It does not prove delivery.
* Open pixels can be fetched by mailbox providers. Treat opens as a weak signal, not proof that a person read the message.
* If a worker stops during a send, the queue can mark the job `unknown`. The plugin does not resend it automatically.
* If WP-Cron is disabled, use a system cron job or the Action Scheduler runner.
* Cart restore can fail when a cart contains third-party item data, an unavailable variation, or no stock. The plugin then keeps the current cart.
* The admin interface and most documentation are in Traditional Chinese. This release does not promise an English admin interface.

= Build and source =

Source code and build tools: https://github.com/bboyfan/now-crm-plugin

Build the browser assets and the installable ZIP from a clean checkout:

1. Run `npm ci`.
2. Run `npm run build`.
3. Run `bash bin/build-zip.sh`.

The build writes `dist/now-crm-1.1.zip`. The ZIP has one top-level folder named `now-crm/`. It contains runtime files only: the plugin PHP file, readme, license, PHP includes, in-use CSS and JavaScript, and the built browser assets.

Tests and local fixtures are not part of the plugin ZIP. The repository documents their preparation.

== Installation ==

1. In WordPress, open Plugins, then Add New Plugin, then Upload Plugin. Select the ZIP file.
2. Activate NOW CRM.
3. Open the NOW CRM menu. Enable the features you need in Settings.

You can also extract the ZIP into `wp-content/plugins/` and activate the plugin from the Plugins screen.

== Frequently Asked Questions ==

= Does the plugin need WooCommerce? =

No. Without WooCommerce, order sync and cart features do not run. Contacts, lists, tags, campaigns, and forms still work.

= Does the plugin send email by default? =

No. Real sending is off on a fresh install. The plugin uses the WordPress mail transport that your site already uses.

= Does the plugin call a hosted CRM or a hosted API? =

No. It does not call a hosted CRM. Optional SES event handling and webhooks contact only the endpoints that you configure. See External Services.

= Does the plugin collect telemetry? =

No. It does not send usage data to the plugin author or to WordPress.org.

= Does the plugin track email opens and clicks? =

Only when the site setting and the campaign setting allow it. Fresh installs keep campaign tracking off. Opens are a weak signal because mailbox providers can prefetch the pixel.

= Does the plugin track shopping carts? =

Only when abandoned-cart capture is enabled. The checkout script reads the current WooCommerce session and sends the checkout email to your site. The plugin sets no tracking cookie of its own.

= Can I import existing data? =

Yes, by explicit WP-CLI commands. The plugin can read FluentCRM tables without writing to them, and it can backfill WooCommerce order gaps. Activation and normal requests never import data. Test the import on a copy of the site first.

= Does the plugin work on Multisite? =

On a Multisite network, the plugin runs only on the site IDs returned by the `nowcrm_allowed_site_ids` filter. The default is the main site. Other sites do not create or read CRM data.

= Is the plugin legal compliance guaranteed? =

No. The plugin provides consent, suppression, unsubscribe, and tracking controls. The site operator remains responsible for consent, privacy notices, retention, and local law.

= Does uninstalling delete data? =

No. Deactivation and uninstall keep the plugin tables and options. Delete data separately if your policy requires it.

== Privacy ==

NOW CRM stores contact and activity data in your WordPress database. This can include email addresses, names, phone numbers, order links, list and tag membership, email events, cart snapshots, form entries, and SES event receipts.

The plugin sends no data to the plugin author. It does not include a hosted service, telemetry, or a remote CRM.

Data handling controls:

* The WordPress personal data exporter includes the contact profile, memberships, status history, order links, email metadata and engagement events, carts, form entries, SES receipts, and suppressions.
* The `wp nowcrm email retention` command previews or applies retention of historical email payloads.
* Unsubscribe and suppression states are kept across sync and import. Automated WooCommerce sync does not resubscribe a suppressed contact.
* Tracking and cart capture are off by default. Before you enable them, tell customers what data you collect, why you collect it, and how long you keep it. Use the site's consent process where required.
* The plugin is a tool, not legal advice. It does not claim compliance with the GDPR, CAN-SPAM, or any other law.

== External Services ==

This plugin uses an external service only when the site operator enables or configures it. The plugin author receives no data.

= WordPress mail transport =

* Trigger: real email sending is enabled and a message is queued.
* Data sent: recipient address, sender name and address, reply-to address, subject, HTML and text body, and email headers. Headers can include `List-Unsubscribe`, `X-NowCRM-Job`, `X-SES-MESSAGE-TAGS`, and `X-SES-CONFIGURATION-SET`.
* Destination: the mail transport configured in WordPress. This can be a host mailer, an SMTP plugin, a transactional email plugin, or Amazon SES.
* Privacy and terms: the policy of your mail provider applies. The plugin has no account with that provider.

= Amazon SES event notifications through Amazon SNS (optional) =

* Trigger: the operator configures an allowed SNS topic ARN and subscribes the site endpoint `https://example.com/wp-json/nowcrm/v1/ses/sns` to that topic in AWS.
* Data received: the SNS envelope and the SES notification. This can include the SES message ID, recipient address, delivery status, bounce type, complaint details, and timestamps.
* Data sent to AWS: an HTTPS GET to the SNS `SubscribeURL` to confirm a subscription, and an HTTPS GET to fetch the SNS signing certificate from `https://sns.<region>.amazonaws.com/`.
* Credentials: the plugin does not store AWS credentials and does not call the SES API. Sending uses the WordPress mail transport.
* Privacy policy: https://aws.amazon.com/privacy/
* Terms of service: https://aws.amazon.com/service-terms/

= Outbound webhooks (optional) =

* Trigger: the operator adds a webhook URL and selects one or more events.
* Data sent: JSON event data. The envelope includes a delivery ID, event name, site URL, and timestamp. Contact events can include contact ID, email, first name, last name, phone, status, source, and timestamps. Order events can include order ID, number, status, total, and currency. Cart and email events can include cart ID, job ID, recipient, transport, and message ID. List and tag events include the term name, slug, and type.
* Destination: an HTTPS URL configured by the operator. The plugin also permits HTTP for local or private network endpoints. Each request carries an HMAC signature.
* Privacy and terms: the policy of the endpoint operator applies. The plugin author operates no webhook endpoint.

= Inbound webhooks (optional) =

* Trigger: the operator generates a scoped key and gives the URL to another system. That system sends an HTTP POST.
* Data received: for the contact route, an email address, profile values, and list or tag names or IDs. A registered generic handler receives the posted JSON in its own WordPress action.
* Data sent by the plugin: none. The remote system is the sender.
* Privacy and terms: the policy of the sending system applies.

= Smart links (optional) =

* Trigger: a visitor opens a smart link URL that the operator created.
* Data sent: none to the plugin author. The site answers with a redirect to the target URL that the operator configured.
* Privacy and terms: the policy of the target site applies.

== Changelog ==

= 1.1 =
* Prepare the public release package: public README, complete GPLv2 license, and explicit external services disclosure.
* Use the neutral customer list name "Customers" for new installs. Reuse an existing customer list on upgrade without rename or duplicate.
* Ship runtime files only in the plugin ZIP. The build fails when a required browser asset is missing.
* Keep WooCommerce auto-subscribe and mail tracking off by default. Keep saved settings on upgrade and stop recording from previously sent links when tracking is off.
* Apply the linked CRM record's current permissions to its block editor working copy.

= 1.0.0-rc.3 =
* Add a read-only contact summary panel to the WooCommerce order screen for both HPOS and the legacy order table.

= 1.0.0-rc.2 =
* Track SES delivery receipts per send attempt and add delivery diagnostics.
* Add WooCommerce email handoff checks and read-only reconciliation. Update checkout consent and preference list defaults.
* Add WordPress personal data export and explicit, batch-based email retention.

= 1.0.0-rc.1 =
* First release candidate.

== Upgrade Notice ==

= 1.1 =
Back up the database before you upgrade. Existing customer lists and saved settings are kept.
