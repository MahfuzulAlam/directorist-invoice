# Directorist Invoices

PDF invoices for purchases made with Directorist. Customers can view an invoice in their browser or download it from their dashboard. Administrators can access invoices from Directorist orders. Developed by [wpXplore](https://wpxplore.com/).

**[Read the complete user guide](DOCUMENTATION.md)**

## Features

- Creates PDF invoices on demand for current Directorist orders, older Directorist order posts, and WooCommerce orders connected to Directorist Booking.
- Adds **View invoice** and **Download PDF** actions to supported order screens. The actions use icons with hover titles and accessible labels.
- Adds an **Invoices** tab to the frontend Directorist dashboard, including older eligible orders.
- Shows your business name, logo, address, contact email, and Tax ID on invoices.
- Uses the customer's Directorist profile address, email, phone, and Tax ID when available. The extension adds the Tax ID field to the frontend profile form.
- Lets administrators choose whether customer email, phone, and Tax ID appear on PDFs. The customer address appears when available.
- Clearly marks unpaid orders and refunds. Failed and cancelled orders have no invoice.
- Restricts invoice access to the order owner and users who can manage Directorist orders.

## Requirements

| Requirement | Version or status |
| --- | --- |
| WordPress | 6.5 or newer |
| PHP | 7.4 or newer, with DOM, iconv, and mbstring |
| Directorist | Installed and active |
| WooCommerce and Directorist Booking | Needed only for WooCommerce booking invoices |

The release package includes Dompdf and its dependencies. Activate Directorist before activating this extension.

## Quick start

1. In **Plugins → Add New → Upload Plugin**, upload the `directorist-invoice-<version>.zip` release package and activate it. You can also place the `directorist-invoice` folder in `wp-content/plugins/`.
2. Open **Directorist → Settings → Extensions → Invoice** and enter your business details. Choose which customer details to show.
3. Ask customers to complete **Dashboard → Profile**, including the new **Tax ID** field if needed.
4. View an eligible order under **Directorist → Orders** and use its **Actions** menu to view or download the PDF. Customers can use the icons in **Order History** or the **Invoices** dashboard tab.

See [DOCUMENTATION.md](DOCUMENTATION.md) for field descriptions, booking and legacy order behavior, invoice contents, and troubleshooting.

## How invoices work

PDFs are generated when someone opens or downloads them; the plugin does not store a separate PDF for every order. Existing eligible orders need no migration. For account holders, the invoice uses their **current** Directorist profile details, so editing a profile can change the customer details shown when an older invoice is opened again.

View links open a PDF in a new browser tab. Download links save the PDF. Access requires a signed-in order owner or a user with permission to manage Directorist orders, and each PDF link is protected by a WordPress nonce.

## For developers

- Business details can be changed with the `directorist_invoice_business` filter. Its array keys are `name`, `logo`, `address`, `email`, and `tax_id`.
- PDF failures fire `directorist_invoice_pdf_error` with the exception. Customers see a generic error message.
- Directorist 8.10.4 does not expose a hook for items in its React Orders **Actions** menu. This extension adds those items to the rendered menu; changes to Directorist's markup may require an update to `assets/order-view.js`.
- If you install from a source checkout without `vendor/`, run `composer install --no-dev` inside this plugin directory before using PDF downloads.

Remote resources, embedded PHP, and PDF JavaScript are disabled in Dompdf. The bundled Dompdf version is 3.1.6.

## Support

For product information and support, visit [wpXplore's Directorist Invoices page](https://wpxplore.com/tools/directorist-invoice/).
