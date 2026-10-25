=== Dox POS for WooCommerce ===
Contributors: davidzoque
Tags: woocommerce, pos, point of sale, barcode scanner, inventory
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.58.2
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

= 0.58.2 =
* Fix: in History > Sales, the By channel, By payment method and By salesperson lists no longer overlap. A long name, like a payment method called "Daviplata o Bancolombia", pushed its total over the next column; it now wraps onto a second line and the total stays in its own column.
* Technical: in assets/css/caja.css, .bl .chan can wrap and the first span of each .bl row takes min-width:0.

= 0.58.1 =
* Fix: in Orders, the lines of an order placed on the website now show the size and color the customer chose ("Ballet flats · 18-24 months"), like the orders made in the register. Before, they showed only the product name, so whoever packed did not know which size to take. When the piece shares units, its actual size still shows next to it.
* Technical: new dox_pos_order_line_name() in includes/orders.php, used by the order list and the order detail; it adds the attribute values of the variation that are not already in the line name (compared by segment, not as a substring).

= 0.58.0 =
* New: write off stock. In Inventory, add what is damaged, lost or given away and tap Write these off instead: it asks why (damaged or defective, lost or stolen, gift or sample, other), takes the units out of the inventory and notes the loss at what each unit cost. It is for products that never went through a sale, so there is no order to note the loss in. Only administrators and shop managers see the button.
* New: the written-off units show in Reports > Movements (filter Written off, with the reason and the cost per unit) and as a Written off figure in Reports > Daily cash, with the units and the loss.
* Technical: new reason writeoff in the stock log (dox_pos_stock_context( 'writeoff' )) whose note carries the reason; the loss is read from those rows (-delta times unit_cost), no new table. includes/writeoffs.php: dox_pos_write_off() (needs dox_pos_can_see_losses(), refuses more units than the stock and products that do not track stock, subtracts from the real size when the line carries one), dox_pos_writeoff_reasons() (cfg.writeoff, empty for who cannot), dox_pos_writeoffs_by_day() (totals writeoff, writeoff_units and writeoff_unknown in dox_pos_history_cash()) and REST route POST /writeoffs. New kind bajas in dox_pos_stock_kinds(). Needs a connection: it is not queued like the entries.

= 0.57.0 =
* New: Varies by. Each category says what its products vary by: up to two of the store's attributes, whatever they are. Clothes by Size and Color, thermoses by Capacity and Color, phone cases by Model, rings by Size and Material, shoes by Shoe size. The product form in the register keeps its grid: the first option goes in the rows and the second in the columns, and the titles change on their own (Capacity instead of Sizes). An option that is a color keeps its swatches; any other shows text buttons, like sizes. Set it in Settings > Products > Varies by, or from the register: when a product is created, the chosen category shows "Thermoses varies by Capacity and Color · Change it". From there a new attribute can be created with its values (the register warns when it looks like one the store already has, like Sizes and Size), and Add to Capacity… creates a value that is missing (a 750 ml), already marked.
* New: a store can have several size attributes, for example letters for clothes (Talla) and numbers for shoes (Talla calzado). In Settings > Products > Sizes and colors, mark every attribute that holds sizes. Until you change it they are detected by name, also the ones whose name starts or ends with size (Talla calzado, Shoe size); turning them all off means the store does not use sizes.
* New: a product created without colors (or without its second option) can get them from the register. When the product is edited, its category's option shows up: the units it already has go to the first value chosen, and the rest are added as new variations. Its SKUs and history stay as they were.
* Change: creating and editing use the product's own attributes, and the search, the sale, the inventory, the labels and the SKUs read any attribute ("500 ml · Red"). Shared units between sizes and pieces by actual size stay for sizes. A product that varies by three things or more still sells from the register and is edited in WooCommerce.
* Change: nothing to set up when updating. Each category keeps what it used: its size attribute (the one its products use, or the one set by hand) as the first option and the color as the second. A store with only Size and Color works exactly as before.
* Change: color swatches work with any theme, not only XStore. The register reads the shade of each color from XStore or from the most used swatch plugins (Variation Swatches for WooCommerce by GetWooPlugins or by CartFlows, ThemeHigh and VillaTheme), and a color created from the register is saved where the store's theme or plugin looks for it, so the shop shows its swatch too (a color attribute created from the register is created as a color type when the swatch plugin offers it). The shade set in the swatch plugin wins over an older one, colors with a photo swatch keep their photo and are not asked for a shade, and VillaTheme colors (saved as rgba) are read too. Short size labels are also read from ThemeHigh.
* Change: a color always goes in the columns of the product form, even when it is chosen as the first option in Varies by, because photos and shared units go by color. Choosing above the option that was below swaps them instead of emptying the second one.
* Change: when colors (or the first option) are added to a product that already exists, the first value chosen can be tapped again to remove it until saving, and saving says which value its units go to and asks to confirm. A product that had Color only as information (for the store's filter) can also get colors.
* Change: on a phone, Settings > Products > Varies by shows each menu across the screen (with Automatic, only one); the bottom bar of the product form hides while typing so the keyboard does not cover the field; the links of the form are easier to tap; Sell shows the whole SKU of each variation; the Counter lists sizes in order; and a value created with one product shows up in the next one without reloading.
* Fix: a subcategory whose products have no sizes (thermoses that vary by Capacity) now takes its parent's Varies by when the store has several size attributes.
* Fix: the warning about a similar attribute no longer mixes up short different words (Olor and Color, Tela and Talla, Marca and Manga), and using the existing one says which values were added.
* Fix: with the color on Automatic, the attribute called exactly Color keeps being the color even if another one with color in its name (Color de hilo) has more products.
* Fix: a color that already existed keeps its swatch or its image when it is chosen again from the register.
* Fix: with the size attribute on Automatic, a store with an empty "Talla" and its shoes in "Talla calzado" got the empty one, and the product form offered sizes nobody uses. Among several, the one that the most products have now comes first.
* Change: a color without a shade (created with New attribute…, or in a store without swatches) can get one from the register. When a color attribute is created with its values, each value shows a box to pick its shade; and when a color without a shade is chosen in a product, the form offers to pick it (or Not now). A color that already has a shade or an image keeps it.
* Change: changing Varies by from the register says clearly that it affects the next products of the whole category (and of its subcategories without their own), with how many products it has today, which stay as they are. If none of them varies, it offers Make it not vary; after saving it says how many subcategories changed too.
* Change: faster with several size attributes. What the products of each category use is counted once and kept until a product, a variation or a category changes, instead of on every request; and Settings > Products > Varies by works it out once for all the categories instead of once per category.
* Fix: after saving changes to a product, only the Saved window shows. The Create another window of a new product came out on top of it.
* Fix: saving Settings no longer undoes a Varies by changed from the register while the settings page was open: only the categories changed on the page are saved.
* Fix: closing the "looks like an existing attribute" warning no longer creates the attribute; it has Use it, Create anyway and Cancel. Saving Varies by from the register keeps the values already marked if the rows did not change.
* Fix: when colors are added to an existing product, marking a value, typing its units, removing it and marking it again no longer overwrites the units the product already had. Retrying a save that failed halfway does not create the same variations twice.

= 0.55.0 =
* New: what is sold without a product (a made-to-order piece, a repair) can carry a photo. In Sell > Without a product, Photo (optional): on a phone it opens the camera or the gallery. It is saved as WebP like product photos and shows in the sale line, in the order detail and in the WooCommerce order, under the line's name. Deposits to store credit do not carry a photo.

Full changelog: https://help.doxstudio.com/dox-pos-changelog/
