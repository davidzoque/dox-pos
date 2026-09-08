=== Dox POS ===
Contributors: davidzoque
Tags: woocommerce, pos, point of sale, inventory, whatsapp
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.24.5
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The register for a shop that sells on WhatsApp and Instagram: sales, layaways, stock, history and a stock ledger, without wp-admin.

== Description ==

Dox POS adds a `/caja` page with its own sign-in screen. From there you search products with their photo and stock, record the sales that come in through WhatsApp or Instagram (each one is a WooCommerce order, so stock goes down on its own), put products on layaway while the customer pays, record the stock that arrives and keep the list of what has to be shipped.

It is built for the shop that sells through chat and ships by courier, not for a counter with a barcode scanner: no hardware, no receipt printer, no cash drawer. A phone is enough.

= What it does =

* **Sell and put on layaway.** Channel, customer, city, shipping, discount and payment method. A sale becomes a WooCommerce order, so the stock, the reports and the emails are the ones the store already had. A layaway holds the stock and cancels itself if it is not paid within the deadline you set.
* **Stock in.** Record what arrives with supplier, invoice and cost. It adds to the stock and warns you if the same invoice was already recorded from another phone.
* **Orders.** The ones from the register and, if you want, the ones from the website, with their status and the WhatsApp message ready. Mark them shipped with a carrier and a tracking number, and the customer gets an email with the tracking link, using the store's own email design.
* **Products.** Create and edit products from the phone: photos (iPhone HEIC included, stored as WebP), sizes, colors, units per size and an automatic SKU that follows the one the store already uses.
* **History.** Sales, the daily cash, the stock ledger and, with costs on, what each product leaves. Everything downloads as a real Excel file.
* **Costs and profit.** A cost per unit for each product, stored in the WooCommerce cost field. Every purchase recalculates the weighted average cost, and every sale freezes the cost in the order, so raising a cost later does not rewrite past sales.
* **A Cashier role.** Whoever sells gets into the register and never sees the WordPress dashboard.
* **No signal, no problem.** A sale recorded with the screen open and no connection is kept on the phone and goes in on its own when the connection is back, without duplicating.

The business assistant (today's pending work, a store review, a chat, a forecast and a daily summary by email) lives in a separate add-on, Dox POS Pro. The register works fully without it.

= External services =

This plugin loads the two register fonts from **Google Fonts** (fonts.googleapis.com and fonts.gstatic.com), which is on by default. When the register opens, the browser of whoever uses it requests those fonts from Google, which receives their IP address and the usual data of a web request. The settings page does the same while you pick a font, and asks Google whether the name you typed exists. No store, order or customer data is ever sent.

You can turn it off in WooCommerce > Dox POS > Brand, with the "Load the fonts from Google Fonts" switch: the fonts of the phone or the computer are used instead and the plugin does not connect to any outside service.

Google terms: https://policies.google.com/terms
Google privacy: https://policies.google.com/privacy

== Installation ==

1. Upload the `dox-pos` folder to `/wp-content/plugins/` and activate the plugin.
2. Open `/caja` and sign in with an administrator, a shop manager or a user with the "Cashier" role.
3. Set the brand, the colors, the address of the screen, the sales channels and the payment methods in WooCommerce > Dox POS.

== Frequently Asked Questions ==

= Do I need a barcode scanner or a receipt printer? =

No. Dox POS is made for selling through chat and shipping by courier. You search the product by name or SKU, record the sale and the order is in WooCommerce.

= Does it change my stock twice? =

No. The plugin never touches stock directly for a sale: it creates the WooCommerce order and WooCommerce discounts the stock, exactly as it does with an order from the website. Only stock entries add units, and voiding one takes them back.

= Can two people use it at the same time? =

Yes. When a sale is recorded, the order reserves its units with the same mechanism the WooCommerce checkout uses, so the last unit cannot be sold twice.

= Who can see the costs and the profit? =

Administrators and shop managers. Someone with only the Cashier role sells and records stock without seeing any cost. Costs can be turned off entirely in WooCommerce > Dox POS > Products.

= Where does the cost of a product live? =

In the WooCommerce cost field ("Cost of Goods Sold"), so it also shows in the WooCommerce product editor and other plugins can read it. The plugin turns that feature on when you save the settings with the costs switch on.

= Does it work with the block checkout and with HPOS? =

Yes. Orders are created through the WooCommerce API, and the plugin declares compatibility with High-Performance Order Storage and with the cart and checkout blocks.

== Screenshots ==

1. Recording a sale: search on the left, the order on the right, with the channel it came in through, the customer and how they pay.
2. Stock in, with the supplier, the invoice and the cost of each unit.
3. Orders from the register and from the website, with their status and the WhatsApp message ready.
4. Creating a product from the phone: photos, sizes, colors and units.
5. History: sales, daily cash and the stock ledger, with the profit of each sale.
6. The settings, with a live preview of the register.

== Changelog ==

= 0.24.5 =
* The "No logo: the name shows" notice on the settings page follows the color of the bar: with a dark bar it was black on black.

= 0.24.4 =
* The date on the order detail follows the language of the site: it was written in Spanish inside the code, so an English shop read "8 de September".

= 0.24.3 =
* Housekeeping: the code that updates the plugin outside the WordPress.org directory lives in its own file, and the directory build carries neither that file nor the line that loads it.

= 0.24.1 =
* The plugin page and the author page are now two different addresses, as WordPress.org requires.

= 0.24.0 =
* New factory colors: a warm white, a dark bar and an orange button. They are only the starting point; the five colors keep being yours to change in WooCommerce > Dox POS > Brand, and a shop that already saved its own colors does not change.
* The placeholders in the WhatsApp messages are now in English: {name}, {items}, {hours}, {order}, {store}, {carrier} and {tracking}. Messages and tracking links you had already written are translated on their own when the plugin updates.
* Under the hood: table names go through $wpdb->prepare, and the code passes the WordPress coding standard with no findings.

= 0.23.0 =
* The texts inside the register itself (the JavaScript) are now translated too, through the WordPress script translation system. The plugin is fully in English, with the Spanish translation included; nothing changes on a Spanish site.

= 0.22.0 =
* The plugin now ships in English, with the Spanish translation included. Nothing changes on a Spanish site.

= 0.21.1 =
* The WooCommerce cost field is no longer turned on when the plugin is installed: it is turned on when you save the settings with the costs switch on, and the card says so.
* New switch to stop loading the fonts from Google Fonts (WooCommerce > Dox POS > Brand): with it off, the plugin does not connect to any outside service.
* Under the hood: the register stylesheet and script are enqueued through WordPress, and the settings icons are printed through wp_kses.

= 0.21.0 =
* Costs and profit. Each product carries a cost per unit in the WooCommerce cost field: set it when you create or edit a product in the register, or all at once from the inventory Excel with the Cost column filled in. Every stock entry carries what each unit cost and recalculates the product's weighted average cost. Every sale freezes the cost in its order, so raising a cost later does not change history. The history shows the profit and the margin, the stock ledger shows the unit cost of each movement, and the Excel files carry the cost, profit and value at cost columns. Only administrators and shop managers see any of it.

= 0.20.0 =
* While the plugin is not on WordPress.org, it updates itself from the GitHub releases.

= 0.19.0 =
* The plugin is split in two: Dox POS (this one, the whole register) and Dox POS Pro (the assistant). The free one works fully without the Pro.

= 0.18.0 and earlier =
* The register itself: selling, layaways, stock entries, orders from the website, creating and editing products with photos, the history with sales, daily cash and the stock ledger, the Excel files, shipping with tracking, the settings for any brand, two registers at once and working with no signal.
