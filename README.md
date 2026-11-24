# NOW CRM

NOW CRM is a self-hosted contact relationship manager for WordPress and WooCommerce. It stores contacts, lists, tags, segments, campaigns, automations, forms, and abandoned-cart activity in your own WordPress database. It does not call a hosted CRM at runtime and it sends no telemetry.

WooCommerce is optional. Without WooCommerce, contact, list, campaign, and form features still work.

## Project status

`1.0.0-rc.4` is a release candidate for testing and WordPress.org review. It is not a stable release or a WordPress.org listing.

The admin interface and most project documentation are in Traditional Chinese. This release does not promise an English admin interface.

## Features

### Contacts and organization

- One contact record with normalized email, profile fields, source, WordPress user link, and status history.
- Seven statuses: `subscribed`, `pending`, `unsubscribed`, `transactional`, `bounced`, `complained`, and `spammed`.
- Lists, tags, segments, custom fields, notes, and CSV import.
- Contact search, filters, bulk actions, and a detail screen with order and email history.
- WordPress personal data exporter.

### WooCommerce

- Order sync through WooCommerce CRUD, with support for high-performance order storage (HPOS) and the legacy order table.
- New orders, order updates, billing email changes, status changes, refunds, and deletes update the contact and order link.
- Customer lists for WooCommerce contacts. New installs use `Customers`; upgrades reuse the existing list.
- Optional checkout subscription checkbox with Classic and block checkout support.
- Optional double opt-in for the lists that require it.
- Read-only order panel on the WooCommerce order screen.

### Email and campaigns

- Persistent email queue with a unique job key, claim, lease, retry, and a pre-send eligibility check.
- Campaigns with the block editor or raw HTML, preview, test send, scheduling, pause, resume, and cancel.
- Recurring campaigns, templates, sequences, and automations.
- Signed unsubscribe links. The database stores only a hash, and one-click POST is supported.
- Optional open and click tracking with signed tokens. Tracking is a site setting and a per-campaign setting. Fresh installs keep it off.
- Reports for queue state, campaigns, carts, and engagement.

### Forms and cart recovery

- Native forms with nonce, honeypot, rate limit, validation, entry storage, and double opt-in handoff.
- Abandoned-cart capture on Classic and block checkout. The browser script runs only when capture is enabled.
- One reminder by default, a signed restore link, a lost-cart window, and a cooldown.
- The restore link checks products, variations, stock, and coupons before it changes the cart. A failed restore keeps the current cart.
- The plugin sets no tracking cookie of its own for cart capture. It uses the WooCommerce session.

### Delivery events and integrations

- Optional Amazon SES event handling through Amazon SNS. Topic allowlist and SNS signature verification run before any event is applied.
- Hard bounces and complaints can suppress an address. Transient bounces do not permanently block it.
- Outbound webhooks with HMAC signatures, bounded retries, and a delivery log.
- Inbound contact and handler webhooks with scoped keys.
- Application Password based API keys for REST access by users who hold a NOW CRM capability.
- WP-CLI commands under `wp nowcrm`.

## Requirements

| Component | Version |
|---|---|
| PHP | 8.3 or later |
| WordPress | 6.7 or later |
| WooCommerce | 8.0 or later for order and cart features; optional |
| Node.js | Only to build browser assets from source |
| Mail | A working WordPress mail transport for real email |

The installable ZIP includes built browser assets. A site that installs the ZIP does not need Node.js.

## Install

### From a clean Git checkout

Clone the source, then build the browser assets and installable ZIP.

```bash
git clone https://github.com/bboyfan/now-crm-plugin.git
cd now-crm-plugin
npm ci
npm run build
bash bin/build-zip.sh
```

The build writes `dist/now-crm-1.0.0-rc.4.zip`. The ZIP has one top-level folder named `now-crm/` and contains runtime files only.

### In WordPress

1. Open **Plugins → Add New Plugin → Upload Plugin**.
2. Select the ZIP file and install it.
3. Activate NOW CRM.
4. Open the **NOW CRM** menu and enable the features that you need.

You can also copy the plugin folder to `wp-content/plugins/` and activate it from the Plugins screen.

## First run

Activation creates the plugin tables, default settings, and capabilities. It does not import data, sync orders, or send email.

A fresh install keeps these features off:

- WooCommerce order sync
- Automatic subscription of new WooCommerce contacts
- Real email sending
- Abandoned-cart capture
- Campaign open and click tracking

The checkout subscription checkbox is off. Lists that require double opt-in still require a confirmation before marketing email.

Before you enable real sending, configure a mail transport for WordPress. The plugin does not include an SMTP account, a mail provider, or AWS credentials.

Use the `capture` email transport for local checks. It stores a bounded local copy of each message and does not send it.

## WooCommerce and consent

The plugin gives the site operator controls. It does not decide whether a consent process is legally sufficient.

- WooCommerce order sync finds a contact by the normalized billing email. It creates a contact only when none exists.
- Sync never changes the status of an existing contact. A new contact gets a status from the auto-subscribe setting and the saved checkout choice.
- New contacts from WooCommerce are added to the customer list. The list name comes from the `nowcrm_customer_list_name` filter when that filter is set. Without the filter, an upgrade reuses the existing customer list and a fresh install uses `Customers`.
- The checkout checkbox is off by default. A saved yes can start double opt-in when the list policy requires it. A saved no is not a marketing subscription.
- Automatic WooCommerce sync does not resubscribe an unsubscribed, bounced, complained, or spammed contact.
- Abandoned-cart email is limited to `subscribed` and `transactional` contacts.
- Open and click tracking, cart capture, and smart link click counts are optional. Enable them only when your privacy notice and consent process allow it.
- Unsubscribe and suppression records survive sync and import.

## Data and privacy

NOW CRM stores its data in the WordPress database. The tables can contain contact details, order links, list and tag membership, email jobs and events, cart snapshots, form entries, SES event receipts, and suppressions.

The plugin does not send data to the plugin author. It has no hosted backend, no telemetry, and no remote CRM.

The WordPress personal data exporter includes the contact profile, custom fields, memberships, status history, order links, email metadata and engagement events, carts, form entries, SES receipts, and suppressions. The `wp nowcrm email retention` command previews or applies retention of historical email payloads.

Deactivation and uninstall keep the plugin tables and options. Delete data separately if your policy requires it.

### External services

The plugin uses the site's configured mail transport. Optional integrations accept Amazon SES events through Amazon SNS, send signed webhooks to endpoints you configure, or receive data through scoped inbound URLs. See the External Services section of [`readme.txt`](readme.txt) for connection triggers, data categories, and provider policies.

## Limits

- `wp_mail()` returning true means the mail stack accepted the message. It does not prove delivery.
- Open pixels can be fetched by mailbox providers. Treat opens as a weak signal, not proof that a person read the message.
- If a worker stops during a send, the queue can mark the job `unknown`. The plugin does not resend it automatically.
- If WP-Cron is disabled, use a system cron job or the Action Scheduler runner.
- Cart restore can fail when a cart contains third-party item data, an unavailable variation, or no stock. The plugin then keeps the current cart.

## Development

### Browser assets

Browser source is in `src/` and builds with `@wordpress/scripts`:

```bash
npm ci
npm run check-types
npm run test:unit
npm run build
```

`assets/build/` is committed so a site can install the ZIP without Node.js. Run `npm run build` again after you change `src/`.

### PHP tests

The PHPUnit suite uses the WordPress test library, WooCommerce, PHPUnit 9.6, the Yoast PHPUnit Polyfills, and a disposable database. Those items are not in this repository.

A prepared development environment can run:

```bash
tests/bin/run-tests.sh
tests/bin/run-tests.sh --hpos
tests/bin/run-tests.sh --multisite
tests/bin/run-tests.sh --filter WooSyncTest
```

`tests/bin/run-tests.sh` is not standalone. It expects a WordPress installation, WooCommerce, the test library, the PHPUnit PHAR, a Docker network, and a disposable database supplied by the development environment. Environment variables can override the defaults. See [`docs/testing.md`](docs/testing.md) for the fixture list and safety rules.

## Documentation

- [`docs/architecture.md`](docs/architecture.md): layers, data model, queue, security boundaries, Multisite scope, and known limits.
- [`docs/testing.md`](docs/testing.md): test fixtures, test commands, and safety rules.
- [`docs/operations.md`](docs/operations.md): import, SES, and WooCommerce handoff procedures.

## Support

Use [GitHub Issues](https://github.com/bboyfan/now-crm-plugin/issues) for bugs and feature requests.

Include the WordPress version, WooCommerce version, PHP version, and the steps that reproduce the problem. Do not include customer data, email addresses, API keys, or webhook secrets.

## License

NOW CRM is free software under the GNU General Public License, version 2 or later (GPL-2.0-or-later).

Copyright (C) 2026 WENSZU.

See [`LICENSE`](LICENSE) for the full license text.
