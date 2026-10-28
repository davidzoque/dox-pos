=== Dox POS for WooCommerce ===
Contributors: davidzoque
Tags: woocommerce, pos, point of sale, barcode scanner, inventory
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.60.0
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WooCommerce register for WhatsApp, Instagram and the store counter: barcode scanner, cash and change, receipts, till closing, stock.

== Description ==

Dox POS adds a `/pos` page (`/caja` on a Spanish site) with its own sign-in screen. From there you search products with their photo and stock, record the sales that come in through WhatsApp or Instagram (each one is a WooCommerce order, so stock goes down on its own), put products on layaway while the customer pays, record the stock that arrives and keep the list of what has to be shipped.

It was born for the shop that sells through chat and ships by courier, where a phone is enough. If you also have a physical store, turn on the **Counter**: a till screen for a computer or a tablet with a barcode scanner, cash with the change worked out, receipts on any printer and opening and closing the till.

It follows the country of the store (WooCommerce > Settings > General): the payment methods it offers, the carriers it suggests and the default sales channels are the ones of that country, and where WhatsApp is not the norm (the United States, for instance) the messages to the customer open as text messages in the phone's Messages app instead. Everything can be renamed, turned off or added to in WooCommerce > Dox POS > Sales.

= What it does =

* **Sell and put on layaway.** Channel, customer, city, shipping, discount and payment method. A sale becomes a WooCommerce order, so the stock, the reports and the emails are the ones the store already had. A layaway holds the stock until its deadline (or with no deadline), takes deposits that count as sales on the day the money comes in, and cancels itself if it is not paid in time, unless it already has deposits.
* **Inventory.** Record what arrives with supplier, invoice and cost. It adds to the stock and warns you if the same invoice was already recorded from another phone.
* **Orders.** The ones from the register and, if you want, the ones from the website, with their status and the WhatsApp message ready. Mark them shipped with a carrier and a tracking number, and the customer gets an email with the tracking link, using the store's own email design.
* **Products.** Create and edit products from the phone: photos (iPhone HEIC included, stored as WebP), sizes and colors or whatever each category varies by (capacity, model, material), units of each one, weight and size for shipping, and an automatic SKU that follows the one the store already uses.
* **Shipping costs.** Set what shipping costs without opening wp-admin: a fixed price, by weight, free from an amount or store pickup, for the whole country or for some of its regions. They are saved as WooCommerce shipping zones, so the web checkout and the register charge the same.
* **History.** Sales, the daily cash, the stock ledger and, with costs on, what each product leaves. Everything downloads as a real Excel file.
* **Costs and profit.** A cost per unit for each product, stored in the WooCommerce cost field. Every purchase recalculates the weighted average cost, and every sale freezes the cost in the order, so raising a cost later does not rewrite past sales.
* **The Counter, for a physical store.** Scan barcodes with any USB or Bluetooth scanner, or with the phone camera. Quick buttons for products without a barcode, keyboard shortcuts, sales on hold, a discount, and cash with suggested bills and the change in large type, card or several payment methods in one sale.
* **Till and receipts.** Open the till with its float and close it counting the drawer: the register tells you whether it matches. Every sale has a receipt for 58 or 80 mm paper, printed from the browser on any printer, with a barcode that opens its return. Returns put the pieces back in stock.
* **Roles for the store.** A Cashier role that sells and never sees the WordPress dashboard, and a Counter cashier role that only reaches the Counter. Discounts can be capped for them, and they close the till blind.
* **No signal, no problem.** A sale recorded with the screen open and no connection is kept on the phone and goes in on its own when the connection is back, without duplicating.

The business assistant (today's pending work, a store review, a chat, a forecast and a daily summary by email) and the extras for a busy store (cashiers with a PIN, several tills, a Stripe card reader, a customer screen, direct printing to Star and Epson receipt printers with the cash drawer, and a fuller receipt editor) live in a separate add-on, Dox POS Pro. The register works fully without it.

= Third-party libraries =

The Counter's camera scanner uses **ZXing** (zxing-js/library 0.21.3, Apache License 2.0) on browsers that cannot read barcodes on their own, such as Safari on the iPhone. It is bundled in `assets/vendor/zxing` with its license, loaded only when someone taps Camera, and never loaded from outside the site. Source code: https://github.com/zxing-js/library

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

No. For selling through chat a phone is enough: you search the product by name or SKU, record the sale and the order is in WooCommerce. In a physical store, the Counter works with any USB or Bluetooth barcode scanner (it types like a keyboard) or with the camera, and prints receipts from the browser on any printer.

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

= Does it work with a store in several languages (Polylang, WPML)? =

Yes, for the lists. With Polylang or WPML each product exists once per language. Dox POS shows only the products in the store's default language (and the ones with no language), so each product appears once in Sell, Inventory, the Excel file and the counts. A web order placed in another language counts toward its main product in the best sellers and in Movements, and a product created from the register is created in the default language. Creating and editing the translations from the register (a tab per language, creating the product in every language, repairing a half-made translation) is part of Dox POS Pro; without it, editing a product changes only the main product. Dox POS changes stock only through WooCommerce, but it does not copy stock between translations: Polylang (free) does not do it either, so the store needs Polylang for WooCommerce, WooCommerce Multilingual (WPML) or a snippet that keeps the stock of the translations the same.

= Does it work with the block checkout and with HPOS? =

Yes. Orders are created through the WooCommerce API, and the plugin declares compatibility with High-Performance Order Storage and with the cart and checkout blocks.

== Screenshots ==

1. Recording a sale: search on the left, the order on the right, with the channel it came in through, the customer and how they pay.
2. Inventory, with the supplier, the invoice and the cost of each unit.
3. Orders from the register and from the website, with their status and the WhatsApp message ready.
4. Creating a product from the phone: photos, sizes, colors and units.
5. History: sales, daily cash and the stock ledger, with the profit of each sale.
6. The settings, with a live preview of the register.
7. The Counter for a physical store: scan a barcode and the product goes into the sale.
8. Charging cash at the Counter, with suggested bills and the change in large type.
9. The receipt editor, with its live preview.

== Changelog ==

= 0.60.0 =
* Fix: stores with Price Based on Country: the register no longer sells with the prices or the currency of the country the cashier opens it from. The page /pos/ and the register's own API (/dox-pos/ only, never the shop, the checkout, the dashboard or /wc/store/) leave out the visitor's prices, so the register always sells in the store currency, as its notice says. Before, a cashier in another country could sell in that country's currency without choosing it.
* Fix: in stores that keep orders as posts (without HPOS), the Orders list showed the deposit orders of layaways, the layaways due soon and the layaway payments in History were not narrowed by the database, and uninstalling could miss the demo orders. They are now filtered in PHP or with meta_key/meta_value, so they work with and without HPOS.
* New: when an amount belongs to an order whose currency is not the register's (an add-on that sells in several currencies), it shows the currency code next to it, like $39.00 USD, in Orders, the order detail, the windows and History. A store with one currency sees no change.
* New: in the customer box of a sale, if the customer has store credit only in another currency, the register shows it as information ("Store credit in another currency: $35.10 USD"); it cannot be used there. New filter dox_pos_credit_lookup and action dox_pos_stop_visitor_prices (see README.md).
* Fix: in stores that keep orders as posts (without HPOS), a new sale could return an earlier order instead of being recorded, because the check for a repeated sale used a query WooCommerce ignores there.
* Stores in several languages (Polylang or WPML): the register still shows each product once in Sell, Inventory, search, counts and Excel files, writes the stock log once on the main product and creates new products in the default language. Creating and editing the translations from the register (the language tabs, "Also create in the other languages", "Missing in English" and Create now) is now part of Dox POS Pro. Without it, editing a product changes only the main product and the translations are left as they are.
* New: if the store uses Polylang, WPML, Price Based on Country, CURCY, WOOCS or Aelia Currency Switcher and Dox POS Pro 0.42.0 or newer is not active, a short notice on the Dox POS settings and in the register says what Pro adds. It can be dismissed, once per user, and it never shows in the WordPress dashboard or on the website.
* Technical: new hooks for add-ons in the product form: the dox_pos_product_form_fields filter (slots name_before, name_after, price_after, short_before, short_after, description_after), the dox_pos_product_saved action, the dox_pos_product_response, dox_pos_product_edit_data and dox_pos_catalog_item filters, the dox_pos_products_settings and dox_pos_products_sanitize filters, the dox_pos_settings_products and dox_pos_rest_routes actions, and the productoListo, productoPayload, productoCargado... events of window.DoxPOS (see README.md).
* Technical: new hooks for add-ons that sell in another currency (Dox POS Pro does it with Price Based on Country zones). The plugin itself still sells in the store currency with the WooCommerce price: dox_pos_currency() (filter dox_pos_currency) replaces the scattered get_woocommerce_currency() calls; dox_pos_money_format() (filter dox_pos_money_format) gives the symbol, separators and decimals that dox_pos_money() and window.DoxPOS cfg.money use; dox_pos_product_price() (filter dox_pos_product_price, with the context sell, list or edit) is how the catalog, the cart, the order and shipping read a price; the dox_pos_order_problem filter lets an add-on refuse a sale, the dox_pos_order_before_totals action runs before the totals are calculated; reports skip other currencies when dox_pos_report_currency() says so; store credit keeps an optional currency per movement (the filter dox_pos_credit_currency); the dox_pos_shipping_rates, dox_pos_order_profit_pre, dox_pos_xlsx_money_format and dox_pos_history_response filters, the dox_pos_product_validate filter (an add-on can reject a product save with a WP_Error) and the historialConsulta and historialPintado events. Without an add-on every one of them returns exactly what it did before. See README.md.
* Technical: includes/multilingual.php keeps dox_pos_lang_plugin(), dox_pos_lang_default(), dox_pos_lang_where(), dox_pos_lang_main_id(), dox_pos_lang_twin_variation(), dox_pos_lang_term_args(), dox_pos_lang_assign_term() and dox_pos_lang_assign(); the rest moved to the Pro.

= 0.59.1 =
* Fix: with Polylang, saving a product from the register no longer replaces the photos of its translations when the photos of the main product did not change. A translation can have its own photos (uploaded apart, with the alt text in its language); they are kept, and the translation only takes the main product's photos when they change in the register.
* Technical: dox_pos_lang_copy_images() copies the photo and the gallery to a translation, each on its own, only when the main product's one differs from the last one it saw (_dox_pos_lang_imgs); the first time, a translation that already has a photo or a gallery keeps it.

= 0.59.0 =
* New: stores in several languages with Polylang or WPML. Each product exists once per language, and the register showed it twice (or three times): in Sell, Inventory, Edit product, the Excel file, the stock counts and the codes the product form suggests. Now only the products in the store's default language show, plus the ones with no language; a variation follows its product. Searching by the name in another language still finds the product, scanning the code of a translation sells the main product, and a web order placed in another language counts toward its main product in the best sellers.
* New: in Movements, when the translations share their stock, a change is written once, on the main product (also for a variation, matched by its attributes). The tools that keep the stock of the translations the same save the other translation too, and that second save was written as a second movement. When each translation keeps its own stock, its movements stay on it.
* New: a product created from the register is saved in the default language, whatever language filter the dashboard has.
* New: with Polylang, a product created in the register is also created in every other language of the store, linked as its translation and published, so it sells in every language from day one: same price, cost, photos, units, sizes and colors, and the categories in their translation (one with no translation is left out, and the register says so). The SKU of each translation ends in its language (VE-0012-EN), because WooCommerce does not allow the same SKU twice; the dox_pos_translation_sku filter changes it. It can be turned off in Settings > Products > Languages ("Also create in the other languages").
* New: the product form (creating and "Edit this product") has a tab per language (ES, EN...) above the name and the descriptions. Each tab keeps the text of its language and saves it to its own translation; a dot marks a language that still has the main language's text. Everything else is shared: editing the price, cost, photos, categories, units, sizes or colors from the register writes them to every translation (the dox_pos_lang_sync_price filter can keep the price apart).
* New: a translation that was left half made (the connection dropped or the server stopped while it was being created) is finished the next time, never made twice: when the register sends the same product again, when the product is saved from the register, or when it is opened in Edit product. A product missing a translation shows "The English version is missing" in the form with a Create now button (with the setting on, it is created as soon as the product opens), and Inventory and Edit product mark it "Missing in English".
* New: the product form has a Short description, the line WooCommerce shows under the price.
* New: the categories in the product form are only the ones in the main language, and a category created from the register is saved in the main language.
* WPML: the register shows each product once and counts it once, as above. Creating the translations, the language tabs and the Missing marker are Polylang only: with WPML the translations are still made in WPML.
* Stock still changes only through WooCommerce, so whatever the store uses to copy stock between translations (Polylang for WooCommerce, WooCommerce Multilingual or a snippet) keeps working. Polylang (free) does not copy it.
* Technical: new includes/multilingual.php with dox_pos_lang_plugin(), dox_pos_lang_where() (a NOT EXISTS on the language terms of Polylang or on icl_translations of WPML, for products and for variations through their parent), dox_pos_lang_main_id() (with Polylang, a variation of a translation maps to the variation of the main product: the one it was copied from, marked with _dox_pos_lang_of, or the one with the same attributes), dox_pos_lang_assign() and, for the translations, dox_pos_lang_sync() (WC_Admin_Duplicate_Product to create them, then the shared fields copied without writing to the kardex). Each copy is marked in the same save that creates it (_dox_pos_lang_of, _dox_pos_lang and _dox_pos_lang_pending until it is finished), so dox_pos_lang_sync() can adopt an unlinked copy (dox_pos_lang_orphan()) or finish a pending one instead of making another; dox_pos_lang_missing() feeds lang_missing in the edit data and nolang in the catalog and the edit list; POST /products/{id}/translations creates what is missing. Creating or finishing a translation takes a lock per product and language (dox_pos_lang_lock()), so two requests at once never make two; a translation in the trash is left alone. WPML only filters the lists. Without Polylang or WPML they make no queries and leave every query as it was.

= 0.58.2 =
* Fix: in History > Sales, the By channel, By payment method and By salesperson lists no longer overlap. A long name, like a payment method called "Daviplata o Bancolombia", pushed its total over the next column; it now wraps onto a second line and the total stays in its own column.
* Technical: in assets/css/caja.css, .bl .chan can wrap and the first span of each .bl row takes min-width:0.

= 0.58.1 =
* Fix: in Orders, the lines of an order placed on the website now show the size and color the customer chose ("Ballet flats · 18-24 months"), like the orders made in the register. Before, they showed only the product name, so whoever packed did not know which size to take. When the piece shares units, its actual size still shows next to it.
* Technical: new dox_pos_order_line_name() in includes/orders.php, used by the order list and the order detail; it adds the attribute values of the variation that are not already in the line name (compared by segment, not as a substring).

Full changelog: https://help.doxstudio.com/dox-pos-changelog/
