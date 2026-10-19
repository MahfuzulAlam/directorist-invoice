# Directorist Invoices: user guide

Directorist Invoices lets customers and administrators view or download PDF invoices for eligible Directorist purchases. This guide explains how to set up the invoice, add customer details, and find the PDF.

## Contents

- [Before you start](#before-you-start)
- [Set up your business details](#set-up-your-business-details)
- [Choose which customer details appear](#choose-which-customer-details-appear)
- [Complete a customer profile](#complete-a-customer-profile)
- [View or download an invoice](#view-or-download-an-invoice)
- [Orders and invoice status](#orders-and-invoice-status)
- [What appears on the PDF](#what-appears-on-the-pdf)
- [Common questions](#common-questions)

## Before you start

Your site needs WordPress 6.5 or newer, PHP 7.4 or newer with the DOM, iconv, and mbstring extensions, and an active Directorist plugin. WooCommerce and Directorist Booking are needed only if you want invoices for WooCommerce booking orders.

To install a release package:

1. Activate **Directorist**.
2. In WordPress admin, open **Plugins → Add New → Upload Plugin**.
3. Choose the `directorist-invoice-<version>.zip` file, install it, and activate **Directorist - Invoices**.

If you are upgrading, install the new release package over the existing plugin when WordPress prompts you to replace it. Your invoice settings and customer profile details are saved in WordPress, not in the plugin files.

## Set up your business details

In WordPress admin, open **Directorist → Settings → Extensions → Invoice**. Fill in the **Business details** section and save your changes.

| Setting | Where it appears |
| --- | --- |
| **Business name** | At the top of the PDF and in the **From** section. The site name is used if no name is set. |
| **Business logo** | At the top of the PDF. Choose an image from the WordPress Media Library. |
| **Business address** | In the **From** section. You can enter multiple lines. |
| **Contact email** | In the **From** section. The WordPress admin email is used if no email is set. |
| **Tax ID** | Under your business details on the PDF. This is separate from the customer's Tax ID. |

Use a PNG, JPEG, GIF, or WebP logo of 2 MB or less. SVG files are not supported. If no invoice logo has been saved, the site's WordPress custom logo is used when available.

## Choose which customer details appear

On the same **Invoice** settings page, the **Customer details** section has three switches:

| Switch | What it controls |
| --- | --- |
| **Show customer email** | Shows or hides the customer's email on the PDF. |
| **Show customer phone** | Shows or hides the customer's phone on the PDF. |
| **Show customer Tax ID** | Shows or hides the customer's Tax ID on the PDF. |

All three switches are on by default. A switch affects **invoice display only**: it does not remove the field from the customer's Directorist profile. The customer address appears on the PDF whenever it is available and has no switch.

## Complete a customer profile

Customers can open the frontend **Directorist dashboard → Profile** page, enter or update their **Email**, **Phone**, **Address**, and **Tax ID**, then select **Save Changes**. Directorist already provides the first three fields; this extension adds **Tax ID** to the same form.

For an account holder, the invoice takes email, phone, address, and Tax ID from the current Directorist profile when those values are available. For a WooCommerce booking, order billing details can fill in missing profile details. A customer Tax ID left blank will not appear on the invoice, even when its display switch is on.

**Profile changes also affect older invoices.** PDFs are generated when viewed or downloaded, so an invoice opened after a profile update can show the new customer details.

## View or download an invoice

### Administrators

1. Open **Directorist → Orders**.
2. Find a supported order and open its **Actions** menu.
3. Select the invoice icon to **View invoice** or the download icon to **Download PDF**.

The current Directorist order detail view also shows both icons. Older Directorist order posts have invoice links in their post row actions. There is no separate **Invoice** column or **Directorist → Invoice** admin page.

### Customers

1. Sign in and open the frontend **Directorist dashboard**.
2. Open **Order History** and use the invoice or download icon beside an eligible current order. You can also open the **Invoices** tab to find eligible purchases, including older orders and account-linked WooCommerce bookings.
3. Hover over an icon to see its title. The invoice icon opens the PDF in a new browser tab; the download icon saves it.

Supported current Directorist order receipts also show these icons. The **Invoices** tab shows order date, purchase, total, status, and invoice actions, with pagination for longer histories.

| Order source | Admin access | Customer access |
| --- | --- | --- |
| Current Directorist order | **Directorist → Orders** Actions menu and order details | **Order History**, **Invoices**, and supported receipts |
| Older Directorist order post | Legacy order post row actions, when that screen is available | **Invoices** tab |
| WooCommerce order linked to Directorist Booking | No icon is added to the WooCommerce Orders screen | **Invoices** tab when linked to the customer's account |

Only the signed-in order owner or an administrator with permission to manage Directorist orders can access a PDF. Sharing a link does not give another customer access.

## Orders and invoice status

The extension supports current Directorist purchases such as pricing plans and featured listing orders, older Directorist order posts, and WooCommerce orders linked to Directorist Booking. Existing eligible orders appear without a migration.

Pending and unpaid invoices state that payment is still due; they are **not payment receipts**. A refunded order keeps its original purchase amount and includes a refund note. Failed and cancelled orders do not have invoices.

WooCommerce booking invoices appear in a customer's **Invoices** tab only when the booking order is linked to that customer's account. Guest booking orders are not available in a customer dashboard because they have no account owner.

## What appears on the PDF

Each invoice includes its invoice number, date, order status, purchase description, and total. When applicable, it also shows a subtotal, discount, tax, adjustments, payment method, and transaction reference. The **From** section holds your business details; the **Billed to** section holds the customer details allowed by your settings.

The logo and text are placed into a simple, print-friendly layout. View and download use the same invoice content.

## Common questions

### Why is the invoice action missing?

Check that Directorist Invoices is active, that the order has an eligible status, and that you are signed in as the order owner or an administrator. In the admin Orders table, open the row's **Actions** menu; the plugin does not add an Invoice column. For WooCommerce bookings, use the customer's **Invoices** tab and make sure the booking is linked to their account.

If the admin Actions menu changes after a Directorist update, the menu integration may need an extension update. The menu is rendered by Directorist's JavaScript rather than a WordPress action hook.

### Why is a customer detail missing?

Ask the customer to save the value in **Dashboard → Profile**. Then check the matching switch under **Settings → Extensions → Invoice**. The customer address needs a saved profile address or an available order billing address.

### Why is the logo missing?

Choose a supported image from the WordPress Media Library, keep the file under 2 MB, and save the Invoice settings. Try a smaller PNG or JPEG if a very large image does not render.

### Why does an older invoice show new customer details?

The plugin generates PDFs when they are opened. It reads the account holder's current Directorist profile rather than storing a permanent copy of customer details with each order.

### Why does the PDF fail to open?

Refresh the order page to get a fresh invoice link, confirm you are signed in, and try **Download PDF**. If the site displays a PDF library error, reinstall the complete release package and ask the site administrator to check the required PHP extensions.

For more help, visit [wpXplore's Directorist Invoices page](https://wpxplore.com/tools/directorist-invoice/).
