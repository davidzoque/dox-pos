=== Dox POS for WooCommerce ===
Contributors: davidzoque
Tags: woocommerce, pos, point of sale, inventory, whatsapp
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.47.1
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The register for a shop that sells on WhatsApp and Instagram: sales, layaways, stock, history and a stock ledger, without wp-admin.

== Description ==

Dox POS adds a `/pos` page (`/caja` on a Spanish site) with its own sign-in screen. From there you search products with their photo and stock, record the sales that come in through WhatsApp or Instagram (each one is a WooCommerce order, so stock goes down on its own), put products on layaway while the customer pays, record the stock that arrives and keep the list of what has to be shipped.

It is built for the shop that sells through chat and ships by courier, not for a counter with a barcode scanner: no hardware, no receipt printer, no cash drawer. A phone is enough.

It follows the country of the store (WooCommerce > Settings > General): the payment methods it offers, the carriers it suggests and the default sales channels are the ones of that country, and where WhatsApp is not the norm (the United States, for instance) the messages to the customer open as text messages in the phone's Messages app instead. Everything can be renamed, turned off or added to in WooCommerce > Dox POS > Sales.

= What it does =

* **Sell and put on layaway.** Channel, customer, city, shipping, discount and payment method. A sale becomes a WooCommerce order, so the stock, the reports and the emails are the ones the store already had. A layaway holds the stock and cancels itself if it is not paid within the deadline you set.
* **Inventory.** Record what arrives with supplier, invoice and cost. It adds to the stock and warns you if the same invoice was already recorded from another phone.
* **Orders.** The ones from the register and, if you want, the ones from the website, with their status and the WhatsApp message ready. Mark them shipped with a carrier and a tracking number, and the customer gets an email with the tracking link, using the store's own email design.
* **Products.** Create and edit products from the phone: photos (iPhone HEIC included, stored as WebP), sizes, colors, units per size, weight and size for shipping, and an automatic SKU that follows the one the store already uses.
* **Shipping costs.** Set what shipping costs without opening wp-admin: a fixed price, by weight, free from an amount or store pickup, for the whole country or for some of its regions. They are saved as WooCommerce shipping zones, so the web checkout and the register charge the same.
* **History.** Sales, the daily cash, the stock ledger and, with costs on, what each product leaves. Everything downloads as a real Excel file.
* **Costs and profit.** A cost per unit for each product, stored in the WooCommerce cost field. Every purchase recalculates the weighted average cost, and every sale freezes the cost in the order, so raising a cost later does not rewrite past sales.
* **A Cashier role.** Whoever sells gets into the register and never sees the WordPress dashboard.
* **No signal, no problem.** A sale recorded with the screen open and no connection is kept on the phone and goes in on its own when the connection is back, without duplicating.

The business assistant (today's pending work, a store review, a chat, a forecast and a daily summary by email) lives in a separate add-on, Dox POS Pro. The register works fully without it.

= External services =

The register can load its two fonts from **Google Fonts** (fonts.googleapis.com and fonts.gstatic.com). This is off by default: a fresh install uses the fonts of the phone or the computer and the plugin does not connect to any outside service.

If you turn on the "Load the fonts from Google Fonts" switch in WooCommerce > Dox POS > Brand, the browser of whoever opens the register requests those fonts from Google, which receives their IP address and the usual data of a web request. With the switch on, the settings page loads the fonts the same way while you pick one, and when you type a font name the plugin asks Google whether a font by that name exists. No store, order or customer data is ever sent, and turning the switch off stops every request.

Google terms: https://policies.google.com/terms
Google privacy: https://policies.google.com/privacy

== Installation ==

1. Upload the `dox-pos` folder to `/wp-content/plugins/` and activate the plugin.
2. Open `/pos` (`/caja` on a Spanish site) and sign in with an administrator, a shop manager or a user with the "Cashier" role.
3. Set the brand, the colors, the address of the screen, the sales channels and the payment methods in WooCommerce > Dox POS. If you use other Dox Studio plugins, the same page is in their shared menu: Dox Plugins > POS.

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

= How do I charge shipping by weight? =

Open the register, tap the gear at the top and choose Shipping costs. Add a cost "By weight" and write the ranges ("up to 1 lb, 6", "up to 5 lb, 10") and what a heavier order pays. It is a regular WooCommerce shipping method, so the web checkout charges the same, with no table rate plugin. Each product gets its weight and its size in the Products tab of the register; a product with no weight counts as zero.

= I am not in Colombia. Does it fit my shop? =

Yes. The register reads the store country from WooCommerce. A Colombian store gets Nequi, the local carriers (Coordinadora, Servientrega, Interrapidísimo, TCC...) and WhatsApp; a US store gets Zelle and Venmo, USPS, UPS, FedEx and DHL, and text messages instead of WhatsApp; Mexico, Spain, Argentina, Chile, Peru and Ecuador have their own carriers, and any other country starts with DHL, UPS and FedEx. Card, cash, bank transfer and cash on delivery are there everywhere, and you can rename, turn off or add payment methods, carriers and channels in WooCommerce > Dox POS > Sales.

= Does it work with the block checkout and with HPOS? =

Yes. Orders are created through the WooCommerce API, and the plugin declares compatibility with High-Performance Order Storage and with the cart and checkout blocks.

== Screenshots ==

1. Recording a sale: search on the left, the order on the right, with the channel it came in through, the customer and how they pay.
2. Inventory, with the supplier, the invoice and the cost of each unit.
3. Orders from the register and from the website, with their status and the WhatsApp message ready.
4. Creating a product from the phone: photos, sizes, colors and units.
5. History: sales, daily cash and the stock ledger, with the profit of each sale.
6. The settings, with a live preview of the register.

== Changelog ==

= 0.47.1 =
* Improved: with Colciudades on a store in Colombia, a city typed by hand in the register ("medellin", "MEDELLIN", "bogota") is saved with its name from the list ("Medellín", "Bogotá"), ignoring accents, capital letters and extra spaces. The register fixes it when you leave the field, and the server does it again when the order is saved. A place that is not on the list (a rural district, for instance) is saved as typed.

= 0.47.0 =
* Changed: on the phone, Sell and Inventory no longer have the two tabs at the top. When you add something, a bar appears at the bottom ("View the order" or "Review and save") that opens what you picked, with an arrow to keep searching and an "Empty all" button that asks first.
* New: a size that is already in the order (or in what arrived) shows how many with a "+2" and has a minus button next to it to remove one without going to review.
* Changed: on the phone, "Download Excel" and "Upload costs" sit under the Inventory search.
* Changed: Products opens with the list of products and a "New product" button. Each product opens in its own card with an arrow to go back (to the list, or to the tab you came from). If you leave with unsaved changes, the register asks whether to save, discard or keep editing.
* Changed: when the register opens on the Dashboard, the Dashboard goes first in the bottom bar on the phone.

= 0.46.0 =
* New: the actual size of a piece. When several sizes share the same units (a bodysuit labelled 18-24 months that also fits 12-18), mark the size on the label with the `_dox_pos_real_size` meta (`yes`) on that variation. The register then highlights it in the product sizes (Sell, Inventory and the product card), and the sizes that share it say "Also fits · it is the 18-24 months one".
* New: when the register or the website sells another size of that group, the order line says "Actual size: 18-24 months", so whoever packs knows which piece to take. It shows in the register orders, in the WooCommerce order and in the emails to the store, but not to the customer.
* Fixed: the units in stock on the Dashboard counted the sizes of hidden products.

= 0.45.0 =
* New: the register sign-in screen can offer "Sign in with a code by email" next to the password, when another plugin on the site provides one-time codes (through the `dox_pos_login_code_enabled`, `dox_pos_login_code_send` and `dox_pos_login_code_verify` filters). The cashier types the 6-digit code from their phone into the register, with no password. Codes are only sent to accounts that can use the register, and the register checks the session that was really opened before letting anyone in. Without such a plugin nothing changes: the register asks for username and password as always, and Dox POS sends no email of its own for this.

= 0.44.0 =
* New: on a phone, the register sections move to a bar at the bottom of the screen, like an app. The four used every day (Sell, Orders, Inventory and the Dashboard, or My day for the cashier) are always in view and within reach of the thumb; when there are more than five sections, "More" opens a sheet with the rest. Orders shows the number of orders waiting on its icon. On a tablet or a computer the tabs stay at the top as before.
* Improved: while typing on a phone, the bottom bar steps aside so the keyboard and the order total have room.

Full changelog: https://help.doxstudio.com/dox-pos-changelog/
