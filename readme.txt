=== Dox POS ===
Contributors: davidzoque
Tags: woocommerce, pos, point of sale, inventory, whatsapp
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.47.0
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

= 0.43.0 =
* New: Settings > Sales suggests the payment methods that are turned on in WooCommerce and the register does not have yet (Addi, Wompi, Mercado Pago...), as one button each under "Add payment method". One click adds it with its name, ready to rename. Nothing is added on its own: many of them only make sense on the website. A sale recorded with one counts as paid and keeps its own key, so WooCommerce never offers to refund it through that gateway.
* Fixed: with a light bar and a main color close to it (a lime green bar with the same green as main color), the tabs of the register disappeared: their text took the main color. Text on the bar, links and the "not paid yet" button now use the main color only when it reads well, and otherwise the soft color or the text color. The settings preview does the same.

= 0.42.0 =
* New: a **Help** link at the top of the settings, next to the version, opens the Dox POS guide in the Dox Studio help center (in Spanish on a site in Spanish). Administrators also find it as "Guide" in the gear menu of the register. It is a plain link: the plugin still calls no outside server.
* Fixed: the sample total in the settings preview and in the layaway message was a fixed 189,000, which reads well in Colombian pesos but showed "$189,000.00" in a store that sells in dollars. It is now a believable price in the currency of the store.
* Fixed: the size and color of the sample product in the settings preview ("M · Pink") was written in Spanish on sites in any language.

= 0.41.1 =
* Since WooCommerce 11.0, an order that goes to failed payment gives back the stock it had taken. The stock history now records that movement under its order ("Payment failed #1234"), among the returned ones, instead of as a change with no order and a guessed reason.
* The order history in the register leaves out the notes that WooCommerce 10.9 and later adds for every email it sends ("Email "Completed order" sent."), which pushed the shop's own notes out of the list. They are still in the order in WooCommerce.

= 0.41.0 =
* New: **shipping costs, set from the register.** The gear at the top of the screen (administrators and shop managers) opens Shipping costs: a fixed price, by weight, free from an amount or store pickup, for the whole country or for some of its regions. Nothing is kept apart: they are the WooCommerce shipping zones and methods, so the web checkout and the register charge the same. What the screen cannot edit (a price written as a formula, free shipping that asks for a coupon, the method of another plugin) is shown as it is and can only be turned on or off.
* New: **shipping by weight**, a WooCommerce shipping method with ranges ("up to 1 lb, 6; up to 5 lb, 10"), a price for heavier orders and an optional amount per extra unit of weight. It works in the web checkout too, with no table rate plugin.
* New: **weight and size of the product** in the Products tab, in the units of the store. The packages the store uses the most show up as buttons, so a shop that ships almost everything in the same bag sets them with one tap. They go to the product and its sizes inherit them; if someone gave a size its own weight in WooCommerce, the form leaves it alone unless you type a new one.
* New: **categories from the register.** The product form has a "New category…" button: a name and, if it goes inside another one, which. It is created on the spot and stays chosen. Categories with no products yet now show up too, behind "More categories", so a store that is just starting (no products at all) can create its first product from the register.
* Changed: when other Dox Studio plugins are installed, the settings of Dox POS move into the shared **Dox Plugins** menu (Dox Plugins > POS), next to the rest. Installed on its own from WordPress.org nothing changes: they stay in WooCommerce > Dox POS. The copy installed from GitHub carries that shared menu with it, so there the settings always live in Dox Plugins > POS. The address is the same either way, and shop managers still get in.
* New: the sale asks for the **postcode** in the countries that use one (ZIP Code in the United States), saves it in the order and uses it to find the shipping zone. A country that does not require it, such as Colombia, sees no change.
* When the store charges nothing for the place of a sale, the register now says so instead of asking for the city again, and the amount can still be typed by hand.
* Fixed: with WP_DEBUG on, a shipping cost written as a formula WooCommerce cannot evaluate ("[qty] > 2 ? 24000 : 12000") made WooCommerce print a notice into the answer, and the sale showed no shipping options at all. What a shipping method prints while it calculates no longer reaches the register.
* Fixed: a few texts of the register showed up in Spanish on a site in another language ("Editar este producto", "Buscando…", "Guardando…", the hint under the categories, the badge of the main photo and the page count of the product search, "1-20 de 134").
* Fixed (copy installed from GitHub): the updater took the first file attached to a release, whatever it was, and releases carried the WordPress.org zip first, which has no Spanish translation and no updater. It now asks for its own zip by name, and releases attach only that one.

= 0.40.0 =
* New: Dox POS now shows up in the shared **Dox Plugins** menu when other Dox Studio plugins are installed, with a link to its settings. The register itself does not move: it stays under WooCommerce > Dox POS, which is where anyone using it looks for it. On a site where Dox POS is the only Dox plugin, no extra menu is added at all.
* Fixed (copy installed from GitHub): a site set to a Spanish variant other than Spain, such as Spanish (Colombia) or Spanish (Mexico), showed the register in English, because the bundled translation is es_ES and WordPress does not fall back between variants on its own. Any Spanish variant now gets the Spanish translation, unless the site has its own translation for that variant.

= 0.39.0 =
* The totals and the headings of the register now use the font of the phone or the computer, the same one as the rest of the screen, instead of always falling back to Georgia. A shop that prefers a serif picks it in WooCommerce > Dox POS > Brand from a list of fonts every device already has (Georgia, Charter, Iowan Old Style, Palatino, Baskerville, Times New Roman): nothing is downloaded and nothing is requested from outside. With Google Fonts on, the one you pick here is what shows if Google does not answer.
* The register shows the site icon in the browser tab again, and carries it when you add the register to the home screen of a phone. It was missing on sites whose theme replaces the WordPress icon tags with its own, because the register is a page outside the theme; it now prints them itself, and falls back to the icon the theme stores if WordPress has none.

= 0.38.1 =
* Deleting the plugin no longer erases anything of yours. Until now, removing it from Plugins took the settings, the brand, the address of the register and the Cashier role with it, because that is what its uninstall file did. Now it keeps all of it, so installing it again picks up where you left off. WordPress asks nothing while it deletes a plugin, so a new switch in WooCommerce > Dox POS > Screen lets a shop say beforehand that it does want everything gone; with it on, the plugins list says so right next to the plugin. Orders, stock, the stock entries and the ledger were never touched and still are not.

= 0.38.0 =
* The register follows the store country (WooCommerce > Settings > General). Payment methods: Nequi only for a Colombian store, Zelle and Venmo for a US one, and card, cash, bank transfer and cash on delivery everywhere. Suggested carriers: Coordinadora, Servientrega, Interrapidísimo, TCC, Envía, Deprisa and 4-72 in Colombia; USPS, UPS, FedEx and DHL in the United States; Estafeta, Redpack, Paquetexpress and 99minutos in Mexico; Correos, SEUR, MRW, GLS and Nacex in Spain; the local ones in Argentina, Chile, Peru and Ecuador; DHL, UPS and FedEx anywhere else. A store that never saved its carriers now gets the ones of its country suggested when it marks an order as shipped. Default sales channels: Instagram, TikTok, Facebook and In person where WhatsApp is not the norm.
* Text messages where WhatsApp is not used (the United States, Canada, Australia, New Zealand, Japan, Korea, China): the layaway message, the shipping notice and every "write to them" button open the phone's Messages app with the text written, and the customer field says Phone. A new "Messages to the customer" setting in WooCommerce > Dox POS > Sales switches between the two for any store.
* "Use the site's one" for the logo now finds the logo of themes that keep it in their own settings (UiCore, Flatsome, WoodMart, Avada, Divi), not only the one from Appearance > Customize, and says so when there is none to use.
* The switches in the settings (costs, website orders, dashboard, shipping email, Google Fonts) are rows with a title and a line that says what they do, so the costs card no longer reads as a wall of text.
* The Pro tab says what the add-on is: an AI assistant that runs the shop with you, and what it does.

= Older versions =
The full history is in `changelog.txt`, inside the plugin folder, and at https://github.com/davidzoque/dox-pos/blob/main/changelog.txt
