=== Dox POS ===
Contributors: davidzoque
Tags: woocommerce, pos, point of sale, inventory, whatsapp
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.37.0
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The register for a shop that sells on WhatsApp and Instagram: sales, layaways, stock, history and a stock ledger, without wp-admin.

== Description ==

Dox POS adds a `/pos` page (`/caja` on a Spanish site) with its own sign-in screen. From there you search products with their photo and stock, record the sales that come in through WhatsApp or Instagram (each one is a WooCommerce order, so stock goes down on its own), put products on layaway while the customer pays, record the stock that arrives and keep the list of what has to be shipped.

It is built for the shop that sells through chat and ships by courier, not for a counter with a barcode scanner: no hardware, no receipt printer, no cash drawer. A phone is enough.

= What it does =

* **Sell and put on layaway.** Channel, customer, city, shipping, discount and payment method. A sale becomes a WooCommerce order, so the stock, the reports and the emails are the ones the store already had. A layaway holds the stock and cancels itself if it is not paid within the deadline you set.
* **Inventory.** Record what arrives with supplier, invoice and cost. It adds to the stock and warns you if the same invoice was already recorded from another phone.
* **Orders.** The ones from the register and, if you want, the ones from the website, with their status and the WhatsApp message ready. Mark them shipped with a carrier and a tracking number, and the customer gets an email with the tracking link, using the store's own email design.
* **Products.** Create and edit products from the phone: photos (iPhone HEIC included, stored as WebP), sizes, colors, units per size and an automatic SKU that follows the one the store already uses.
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
2. Inventory, with the supplier, the invoice and the cost of each unit.
3. Orders from the register and from the website, with their status and the WhatsApp message ready.
4. Creating a product from the phone: photos, sizes, colors and units.
5. History: sales, daily cash and the stock ledger, with the profit of each sale.
6. The settings, with a live preview of the register.

== Changelog ==

= 0.37.0 =
* Google Fonts are off until the shop turns them on in WooCommerce > Dox POS > Brand: a fresh install uses the fonts of the phone or the computer and does not connect to any outside service. A shop that had already made its choice keeps it.
* Font names go into the register stylesheet as letters, digits, spaces and hyphens only, and the line that starts the register is added through the WordPress script system instead of being printed by the template.
* The register and the sign-in page declare the language of the site instead of a fixed one.
* The default address of the register follows the language of the site: /pos on an English site, /caja on a Spanish one. It is written to the settings when the plugin is installed, so it never moves afterwards, and shops that were already using /caja stay there. In English the screen is called POS instead of Register, which on a website reads like signing up.

= 0.36.0 =
* The lists of the register no longer leave half a line empty: a long one splits into two columns that still read top to bottom.
* Add-ons can now draw the same figure cards and strip cells the Dashboard uses, so every screen of the register counts in the same shapes.

= 0.35.0 =
* The Dashboard is laid out again: today's figures in three big cards, then one strip with the week, the month and whatever the add-ons contribute, and the lists in two columns. No more half-empty rows or cards stretched to fill a gap.
* The chart of the last days switches between 7, 14, 30 and 90 days, and remembers the choice on that device. Its total is now the headline of the card, saying what it covers and what it averages a day.
* Best sellers lists six products.

= 0.34.0 =
* Hardening after a full code review. A sale only takes the channels set in Sales, a discount larger than the products is rejected, and the sales, entries and shipping routes skip malformed lines instead of failing.
* The Cashier role can no longer confirm the payment of a website order, cancel an order that was already shipped or delivered, or void a stock entry from another day or another person: those are for administrators and shop managers.
* A stock entry refuses a product that does not track stock and a date that does not exist, and the units and the entry are saved together: if the entry cannot be saved, the stock is not touched.
* Sizes that share units by colour now move by the difference instead of copying the number, so two sales of different sizes of the same colour at the same instant both count.
* Amounts with decimals: the register reads and shows prices, costs, discounts and shipping with the store's decimal separator, and the phone keyboard offers the decimal key when the store uses decimals. Stores with whole amounts (COP) see no change.
* The Sales tab warns when WooCommerce has "Manage stock" turned off, because then nothing lowers the stock.
* Uninstalling no longer deletes the settings of Dox POS Pro and removes the plugin's scheduled tasks.
* Cost imports skip the "(no SKU)" rows in any language, the Excel files and the history get more memory and time for long periods, and the unit price in the order detail keeps the store's decimals.

= 0.33.3 =
* In Reports > Movements, filtering by a product now shows a coloured strip with the product's name and a "See them all" button, instead of a line of small text that was easy to miss.

= 0.33.2 =
* The Inventory list is titled just "All products", with the count.

= 0.33.1 =
* Inventory no longer starts empty either: before you search it lists the whole catalogue from A to Z, twenty products at a time, and loads more as you reach the bottom. Searching by name or SKU works as before, and the list refreshes after each entry so the stock is current.

= 0.33.0 =
* Several groups of sizes can share units within the same colour: 0-6 and 6-12 months from one pool, 2-3 and 3-4 years from another. The "Shares" box on each size is now a small menu (does not share, shares, or shares in another group), the colour card gets one "Shared units" box per group, and the register, the website checkout, the kardex and the Excel treat each group as its own pool. With one colour and one group everything stays exactly as before.

= 0.32.1 =
* The Units block of the product form is now one card per colour: its sizes in rows, each with its number and a small "Shares" box, and under them the colour's shared units with a line saying which sizes share them. Cards stack on a phone and sit in a grid on a wide screen, so a product with six colours no longer turns into a table that scrolls sideways. "All sizes share" in the card header ticks every size of that colour at once.

= 0.32.0 =
* Shared units by colour. In a product with several colours, "Shares units" now works per colour: the Coral sizes share Coral's units and the Rosa sizes share Rosa's, each with its own box under the table. Selling a size only lowers the units of its colour, the register warns with the colour's figure ("Romper Marian · Coral has 3 left for all its sizes together"), and the website checkout counts the whole colour while it holds stock during a payment, so two customers cannot take two sizes when one unit is left. The inventory Excel, the Dashboard, the product card and the cost average count each colour's units once. A product with one colour keeps the plain product total, as before.

= 0.31.2 =
* A Dashboard tab for administrators and shop managers, before Sell: sold today with yesterday next to it, today's profit, what is owed (cash on delivery on the way and layaways), the week and the month against the previous ones at the same point, the last fourteen days as bars, what came in today by payment method, the best sellers of the last thirty days, the orders to handle and the stock. Every card opens the tab it comes from. The register opens on it for whoever manages the shop (a switch in Settings > Register turns that off); salespeople keep landing on Sell.
* Sell no longer starts empty: before you type anything it lists the best sellers of the last thirty days, with their sizes and stock, ready to tap.
* The Units table of the product form is simpler: the "All to 1" and "All to 0" buttons are gone, and each size has a "Shares units" checkbox instead of the "from the total" and "its own" links. Ticking it takes that size out of its own box and onto the shared units, which get one box under the table together with the list of sizes that share them.

= 0.30.0 =
* Each size decides where its units come from. In the product form, creating or editing, every size (and colour) has a small link: "from the total" moves it onto the product's shared total, "its own" gives it separate units. The shared total gets its own box as soon as one size uses it, and "One total for all" switches every size at once. So a product can have, say, 2-3 and 3-4 years drawing from one pool while 6-12 months keeps its own count, without opening WooCommerce.

= 0.29.2 =
* A product where some sizes take their units from the product's total and others carry their own (the usual case in a shop that grew over time) now shows all its sizes when you edit it: the shared ones are marked "from the total" and the rest are typed in as always. Before, the whole table was hidden and those units could only be changed in WooCommerce.
* The three controls in the Units header no longer run into each other, and "One total for all" looks like the switch it is.

= 0.29.0 =
* The tab is called Reports, not History: what is in there (sales, the day's cash, stock movements and performance) are reports on how the shop is doing. The order detail keeps its own History with the order's notes.
* A new product can carry one total for all its sizes, the way many shops keep their stock: under Units, "One total for all". The store then discounts from that total whichever size is sold, and you can spread it by size later from Edit one.

= 0.28.1 =
* An add-on can choose which tab the register opens on when there is no address to follow (Dox POS Pro uses it to open on Today for whoever manages the shop). Everyone else keeps opening on Sell.

= 0.28.0 =
* Tap a product anywhere (the assistant, the history, the stock entries) and its card opens right there: photo, code, price, cost and what each unit leaves for those who manage the shop, units per size (or the shared total), category, and the buttons to edit it or see it in the store. Before, it jumped straight to the editor.
* The loss on an order stands out: a proper "Note a loss" button on the detail, a red tag with the amount once noted, and the same tag on the orders list and in the history. The profit comes out in colour: green when it reaches the shop's target margin, amber when it falls short, red when it is low.

= 0.27.0 =
* Losses. From the order detail, an administrator or shop manager can note what an order cost beyond the goods (a shipment that cost more than what was charged, a freight refunded, a repair), with the reason. It comes off the profit of that sale, shows in the history, the cash report and their Excel files, and the order keeps a note of who wrote it.
* Your own payment methods. Settings > Sales has "Add payment method": a second Nequi account, Daviplata, Addi, whatever the shop takes. They can be turned off, renamed and removed, and a sale recorded with one counts as paid.
* Sizes that share one stock total. When a product keeps one total for all its sizes (the parent manages the stock and the sizes inherit it), the register says so ("5 left for all sizes") instead of repeating the number on every size and adding them up, the order cannot take more than that total across sizes, and the message when it does not fit names the product and what is really left. Recording stock on one of those sizes says it goes to the shared total, and the product editor can spread that total by size so each one carries its own units from then on.

= 0.26.0 =
* The stock tab is called Inventory, and the latest entries show each garment with its photo, name and quantity; tapping one opens its product card.

= 0.25.4 =
* The "View" button of each check in the assistant's review no longer opens a "That order does not exist" window: the register only opens an order when there is a real order number.

= 0.25.3 =
* A refund in the shop left the Orders tab blank: the list asked for orders without saying which type and got the refunds too. And the settings cards no longer leave an empty column.

= 0.25.1 =
* Photos are reduced on the phone before uploading (to the size the shop keeps), so a 48-megapixel photo goes up as about 100 KB and works on any hosting.

= 0.25.0 =
* Photos from new phones (24 and 48 megapixels) no longer take minutes to convert: the memory given to ImageMagick fits the photo and the reduction happens in two steps.

= 0.24.8 =
* The sales card in the history said "Sales" in English on a Spanish site: the plural form was missing from the translation.

= 0.24.6 =
* The total, the figures in the history and the headings are set in Manrope instead of Libre Baskerville: the register reads as a tool, not as a book. If your shop already picked its own fonts in Settings, nothing changes.

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
