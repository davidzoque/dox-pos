=== Dox POS for WooCommerce ===
Contributors: davidzoque
Tags: woocommerce, pos, point of sale, barcode scanner, inventory
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.53.0
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
* **Products.** Create and edit products from the phone: photos (iPhone HEIC included, stored as WebP), sizes, colors, units per size, weight and size for shipping, and an automatic SKU that follows the one the store already uses.
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

= 0.54.0 =
* New: labels to stick on the products, from Products > Labels. Each one carries the name, the SKU, the price, the sizes in stock when it is printed and the barcode of the SKU: scan it at the Counter, with a scanner or the phone camera, and its sizes show up. They print from the browser on the label sheets sold in stationery shops (Letter with 30, 20, 14 or 10 labels, Avery 5160 to 5163; A4 with 24, 21 or 14, Avery L7159, L7160 and L7163), or on a whole adhesive sheet with lines to cut; one per product or one per unit in stock, and a sheet already started can be used from any label.
* New: the sign-in screen, redone. The store's bar color, logo and name on one side and the form on the other (on a phone, a strip with the logo). Password or code by email is now a two-option switch instead of a loose link, the password has an eye to see it while typing, errors and notices come in boxes with their icon, and below it says who to ask for a user.
* Change: the product form, redone on the Dox design system: each part in its own card, what is chosen (category, colors, sizes, package) in the store's tint instead of solid color, a green profit, and the save bar floating at the bottom with the total and the button side by side on a computer.
* Fix: letter sizes now come in their order (XS, S, M, L, XL) and not alphabetically; and the note "No colors" no longer shows when the product has colors.
* Fix: in Settings > Sales, the sample receipt did not show until saving when Settings was opened on another tab.

= 0.53.0 =
* New: a new look for the register, built on the Dox design system. The top bar has icons and its counters in small bubbles; Sell shows products as cards and each size as a tile with its stock in a colored pill (green, amber when few are left, red when none); channels and payment methods carry their color and icon (also the payment methods a shop creates); Orders shows each status in a colored pill, the customer's initials and the channel with its icon; the Dashboard, the Assistant, the windows and the login are cleaner. The four colors in Settings (background, bar, primary, text) still dress the whole register.
* New: the order detail, redone. Three figures first (total, how it was paid and where the delivery is), the products on the left and the customer and the profit on the right, each in its card, the history folded at the end and the actions at the bottom. A deposit to store credit opens as a Deposit, with how much, how it was paid and the credit the customer has left. A layaway shows what is left to pay with a bar, its deposits and its deadline.
* Fix: on the iPhone, Safari no longer zooms the register in when a field is tapped (the register stayed bigger, cut off on the left and with the bottom bar under Safari's own bar), and the search no longer takes the focus on its own on a phone (it hid the bottom bar).
* Fix: right after adding a size of a group that shares units, its tiles counted one piece too many until the next search; and the line proposes the closest piece that is still free.
* Change: the button to take one size off the order sits in the corner of its tile, so the size name no longer wraps.
* Fix: a double tap (or Enter twice) on a window's main button no longer does it twice: one deposit on a layaway was recorded as two, and a line without a product could go in twice. Returning to store credit, in Orders and at the Counter, can no longer be saved twice while it is saving.
* Fix: if a sale's answer never arrives (a timeout, a dropped connection) and it is recorded again, the store returns the order it already made instead of making a second one.
* Fix: a cashier who opens an order paid with store credit no longer sees the link to the customer's movements (it is for managers), and something the user is not allowed to do now says so instead of closing the session and losing the sale in progress.
* Fix: the counter-only user can no longer download the inventory or the history in Excel.
* Change: cancelling an order that was already paid says that the money is not refunded from there; marking an order as delivered asks first; Enter in a window does its main button; missing fields say what is missing; on a phone the order detail has a Close button at the bottom; a layaway deposit reads "Deposit on layaway #72" instead of "Deposit: Deposit on layaway #72"; and in Orders, what was deposited and what is left go to a second line instead of widening the list.

= 0.52.0 =
* New: pieces by actual size. In a group of sizes that share units, the product form says how many pieces carry each size on their tag (Pieces by actual size), checks that they add up to the units of the group, and warns when a group is not split yet or does not add up. Sell, Inventory and the Counter say "4 in the group · 2 with tag 12-18 months" instead of looking like 4 of each size.
* New: when a size of a group is sold or put on layaway, the order line proposes which piece leaves (the closest actual size with pieces; if two are equally close, the one with more) and it can be changed right there. That piece goes down, the order's "Actual size" says the one that left, and cancelling, releasing or returning puts it back. Website orders take the closest piece when they are paid. A stock entry asks which tag the pieces came with. The stock history says which piece each movement was.
* New: Settings, Products, "Offer a size only if there is a piece with an actual size close to it", with how close (1 to 4 sizes). Off by default. On, a size with no piece close enough shows as out of stock on the website and in the register, even if the group has units.
* Change: in Orders, a deposit to store credit says "Deposit" instead of "Delivered".

= 0.51.0 =
* New: layaways with their own deadline. When you put something on layaway you choose how long it is held (24 hours, 2, 7 or 30 days, any number of hours or days, or no deadline); it starts with the one in Settings, Layaways, which can now be set in hours or days or as "Layaways do not expire". The deadline can be extended, shortened or removed later from the order detail (Change deadline).
* New: deposits on a layaway. Take part of the money when putting something on layaway, with its payment method, and add more deposits later from the order detail (Add deposit), each one on its own day. Each deposit counts as a sale on the day the money comes in, and the layaway's total (and its payment link) becomes what is left to pay. A deposit that covers everything marks the layaway as paid.
* New: Charge the rest. Paying a layaway now asks how the rest was paid, and only what is left counts as a sale, on the day it is paid.
* New: releasing a layaway with deposits asks what happens with the money: it stays as the customer's store credit (the default) or it is given back, with the payment method you choose (it comes off Sales on the day it goes out). Only administrators and shop managers can give money back; the Cashier role leaves it as store credit.
* New: a layaway with deposits does not cancel itself when it expires: it shows up as expired until you extend it or release it. Layaways with no deposit still cancel themselves, as before.
* New: Dashboard, Layaways due soon: the ones that expire within 3 days and the expired ones with deposits, with what was deposited, what is left and the WhatsApp button.
* New: in Orders and in the order detail each layaway shows what it is worth, what has been deposited, what is left and when it expires.
* New: the layaway message has {due} (when that layaway expires), {deposit} and {remaining}; a sentence whose placeholder does not apply removes itself (the payment link always stays). {hours} is now the hours left on that layaway.
* Change: a layaway now counts as a sale on the day it is paid, not on the day it was put on layaway (only layaways created from this version on: past reports do not change).
* Change: the profit of a layaway counts everything it is worth on the day it is paid; its deposit orders count as sales but not as sales without a cost.
* Change: in stores whose prices exclude tax, the Put on layaway window says that tax is added when it is saved.

= 0.50.0 =
* New: store credit. Money a customer leaves with the shop to use later, kept under their phone number. Take a deposit in Sell (Without a product, Deposit to store credit), for example 50% of a made-to-order piece: it counts as a sale on the day the money comes in, with its payment method.
* New: pay with store credit in Sell and at the Counter, all of it or part, together with another payment method or a split payment. That part does not count as a sale again, because it already counted when the money came in. The tax is still charged in full.
* New: return pieces to store credit instead of giving money back, from the order detail (Orders) or at the Counter (Return, To store credit). The pieces go back to stock and the shop can keep an exchange fee (Settings, Sales, Store credit; 0% by default, it can be changed on each return).
* New: Reports, Store credit: what the shop owes in store credit, each customer's balance and the history of every amount (where it came from and where it was used), adjustments with a reason and "Load a balance" for balances kept in a spreadsheet. The customer gets the balance by WhatsApp or text message, and the receipt shows the credit used and what is left.
* New: sell something that is not in the catalog (Sell, Without a product): a made-to-order piece or a repair, with its own price. It does not touch the stock.
* Fix: money given back at the Counter now comes off Sales, Daily cash and the Dashboard (it only came off the till closing). Returned units no longer count as sold, and their cost no longer counts in the profit.

= 0.49.1 =
* Fix: Edit this product did not save a product whose sizes have different prices. With the Price field empty (which leaves the price of each size as it is), the register sent a price of 0 and the store answered "The price has to be greater than zero", so nothing was saved. An empty Price goes again as "do not touch the prices"; creating a product still asks for a price.
* Fix: in that same case, the line under the cost said "The price is missing" (or "Enter a price and a cost"), as if a price had to be typed, and typing one replaces the price of every size. It now says that the sizes have different prices, from the lowest to the highest.


