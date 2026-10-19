<?php
/**
 * PDF invoice markup.
 *
 * @package Directorist_Invoice
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
	@page { margin: 0; }
	body { margin: 0; color: #1d2939; background: #fff; font-family: "DejaVu Sans", sans-serif; font-size: 15px; line-height: 1.5; }
	.top { padding: 42px 52px 26px; }
	.top table { width: 100%; border-collapse: collapse; }
	.top td { vertical-align: top; }
	.brand { color: #172a42; font-size: 19px; font-weight: bold; line-height: 1.3; }
	.brand-logo-panel { display: inline-block; background: #f2f4f7; padding: 8px 10px; margin-bottom: 10px; }
	.brand-logo { display: block; max-width: 165px; max-height: 62px; }
	.title { color: #172a42; text-align: right; font-size: 33px; font-weight: bold; letter-spacing: .5px; line-height: 1.1; }
	.number { text-align: right; color: #667085; padding-top: 7px; font-size: 14px; font-weight: normal; }
	.header-rule { margin: 0 52px; border-top: 2px solid #243e5c; }
	.body { padding: 24px 52px 0; }
	.meta { width: 100%; border-collapse: collapse; margin-bottom: 26px; table-layout: fixed; }
	.meta td { width: 50%; vertical-align: top; word-wrap: break-word; padding-right: 22px; }
	.meta td:last-child { padding-left: 12px; padding-right: 0; }
	.label { color: #667085; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: .8px; margin-bottom: 8px; }
	.name { color: #172a42; font-size: 17px; font-weight: bold; margin-bottom: 6px; }
	.line { line-height: 1.65; color: #475467; font-size: 14px; }
	.invoice-meta { border-top: 1px solid #e4e7ec; border-bottom: 1px solid #e4e7ec; padding: 13px 0 10px; margin-bottom: 29px; }
	.detail-grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
	.detail-grid td { width: 50%; vertical-align: top; padding: 4px 22px 9px 0; word-wrap: break-word; }
	.detail-grid td:last-child { padding-left: 12px; padding-right: 0; }
	.detail-grid .label { margin-bottom: 4px; }
	.detail-value { color: #243247; font-size: 14px; line-height: 1.45; }
	.items { border-collapse: collapse; width: 100%; table-layout: fixed; page-break-inside: avoid; }
	.items th { background: #f5f7fa; color: #475467; text-align: left; padding: 13px 15px; font-size: 11px; text-transform: uppercase; letter-spacing: .7px; }
	.items td { padding: 19px 15px; border-bottom: 1px solid #e4e7ec; vertical-align: top; font-size: 15px; }
	.items .right { text-align: right; width: 30%; }
	.item-title { font-size: 15px; font-weight: bold; line-height: 1.5; }
	.totals { width: 48%; margin-left: auto; margin-top: 20px; border-collapse: collapse; page-break-inside: avoid; }
	.totals td { padding: 6px 0; font-size: 14px; }
	.totals .right { text-align: right; white-space: nowrap; }
	.totals .muted { color: #667085; }
	.totals .grand td { border-top: 2px solid #243e5c; padding-top: 15px; color: #172a42; font-size: 20px; font-weight: bold; }
	.note { margin-top: 30px; padding-top: 14px; border-top: 1px solid #e4e7ec; color: #475467; font-size: 13px; line-height: 1.6; }
	.footer { margin: 47px 52px 29px; border-top: 1px solid #e4e7ec; padding-top: 11px; color: #98a2b3; font-size: 11px; }
</style>
</head>
<body>
<div class="top"><table><tr><td class="brand">
<?php
if ( $business['logo_data_uri'] ) :
	?>
	<div class="brand-logo-panel"><img class="brand-logo" src="<?php echo esc_attr( $business['logo_data_uri'] ); ?>" alt=""></div><br><?php endif; ?><?php echo esc_html( $business['name'] ); ?></td><td><div class="title"><?php esc_html_e( 'INVOICE', 'directorist-invoice' ); ?></div><div class="number">#<?php echo esc_html( $invoice['number'] ); ?></div></td></tr></table></div>
<div class="header-rule"></div>
<div class="body">
	<table class="meta"><tr>
		<td>
			<div class="label"><?php esc_html_e( 'Billed to', 'directorist-invoice' ); ?></div>
			<div class="name"><?php echo esc_html( $invoice['billing_name'] ); ?></div>
			<?php if ( ! empty( $invoice['billing_address'] ) ) : ?>
				<div class="line"><?php echo nl2br( esc_html( $invoice['billing_address'] ) ); ?></div>
			<?php endif; ?>
			<?php if ( $customer_fields['email'] && ! empty( $invoice['billing_email'] ) ) : ?>
				<div class="line"><?php echo esc_html( $invoice['billing_email'] ); ?></div>
			<?php endif; ?>
			<?php if ( $customer_fields['phone'] && ! empty( $invoice['billing_phone'] ) ) : ?>
				<div class="line"><?php esc_html_e( 'Phone:', 'directorist-invoice' ); ?> <?php echo esc_html( $invoice['billing_phone'] ); ?></div>
			<?php endif; ?>
			<?php if ( $customer_fields['tax_id'] && ! empty( $invoice['billing_tax_id'] ) ) : ?>
				<div class="line"><?php esc_html_e( 'Tax ID:', 'directorist-invoice' ); ?> <?php echo esc_html( $invoice['billing_tax_id'] ); ?></div>
			<?php endif; ?>
		</td>
		<td><div class="label"><?php esc_html_e( 'From', 'directorist-invoice' ); ?></div><div class="name"><?php echo esc_html( $business['name'] ); ?></div><div class="line"><?php echo nl2br( esc_html( $business['address'] ) ); ?></div><div class="line"><?php echo esc_html( $business['email'] ); ?></div>
		<?php
		if ( $business['tax_id'] ) :
			?>
			<div class="line"><?php esc_html_e( 'Tax ID:', 'directorist-invoice' ); ?> <?php echo esc_html( $business['tax_id'] ); ?></div><?php endif; ?></td>
	</tr></table>
	<div class="invoice-meta"><table class="detail-grid">
		<tr>
			<td><div class="label"><?php esc_html_e( 'Date', 'directorist-invoice' ); ?></div><div class="detail-value"><?php echo esc_html( Directorist_Invoices::format_date( $invoice['date'] ) ); ?></div></td>
			<td><div class="label"><?php esc_html_e( 'Status', 'directorist-invoice' ); ?></div><div class="detail-value"><?php echo esc_html( Directorist_Invoices::status_label( $invoice['status'] ) ); ?></div></td>
		</tr>
		<?php
		if ( $invoice['method'] || $invoice['transaction'] ) :
			?>
			<tr>
			<td>
			<?php
			if ( $invoice['method'] ) :
				?>
				<div class="label"><?php esc_html_e( 'Payment', 'directorist-invoice' ); ?></div><div class="detail-value"><?php echo esc_html( ucwords( str_replace( '_', ' ', $invoice['method'] ) ) ); ?></div><?php endif; ?></td>
			<td>
			<?php
			if ( $invoice['transaction'] ) :
				?>
				<div class="label"><?php esc_html_e( 'Reference', 'directorist-invoice' ); ?></div><div class="detail-value"><?php echo esc_html( $invoice['transaction'] ); ?></div><?php endif; ?></td>
		</tr><?php endif; ?>
	</table></div>
	<table class="items"><thead><tr><th><?php esc_html_e( 'Description', 'directorist-invoice' ); ?></th><th class="right"><?php esc_html_e( 'Amount', 'directorist-invoice' ); ?></th></tr></thead><tbody><tr><td><div class="item-title"><?php echo esc_html( $invoice['title'] ); ?></div></td><td class="right"><?php echo esc_html( Directorist_Invoices::money( $invoice['subtotal'], $invoice['currency'] ) ); ?></td></tr></tbody></table>
	<table class="totals">
		<tr><td class="muted"><?php esc_html_e( 'Subtotal', 'directorist-invoice' ); ?></td><td class="right"><?php echo esc_html( Directorist_Invoices::money( $invoice['subtotal'], $invoice['currency'] ) ); ?></td></tr>
		<?php
		if ( $invoice['discount'] > 0 ) :
			?>
			<tr><td class="muted"><?php esc_html_e( 'Discount', 'directorist-invoice' ); ?></td><td class="right">-<?php echo esc_html( Directorist_Invoices::money( $invoice['discount'], $invoice['currency'] ) ); ?></td></tr><?php endif; ?>
		<?php
		if ( $invoice['tax'] > 0 ) :
			?>
			<tr><td class="muted"><?php esc_html_e( 'Tax', 'directorist-invoice' ); ?></td><td class="right"><?php echo esc_html( Directorist_Invoices::money( $invoice['tax'], $invoice['currency'] ) ); ?></td></tr><?php endif; ?>
		<?php
		if ( abs( $invoice['adjustment'] ) > 0.001 ) :
			?>
			<tr><td class="muted"><?php esc_html_e( 'Other charges / adjustments', 'directorist-invoice' ); ?></td><td class="right"><?php echo esc_html( Directorist_Invoices::money( $invoice['adjustment'], $invoice['currency'] ) ); ?></td></tr><?php endif; ?>
		<tr class="grand"><td><?php esc_html_e( 'Total', 'directorist-invoice' ); ?></td><td class="right"><?php echo esc_html( Directorist_Invoices::money( $invoice['total'], $invoice['currency'] ) ); ?></td></tr>
	</table>
	<div class="note">
		<?php if ( in_array( $invoice['status'], array( 'pending', 'unpaid', 'created', 'on-hold' ), true ) ) : ?>
			<?php esc_html_e( 'Payment is pending. This invoice records your order and is not a receipt for payment.', 'directorist-invoice' ); ?>
		<?php elseif ( 'refunded' === $invoice['status'] ) : ?>
			<?php esc_html_e( 'This invoice records the original purchase. The order has been refunded.', 'directorist-invoice' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Thank you for your purchase. Please keep this invoice for your records.', 'directorist-invoice' ); ?>
		<?php endif; ?>
	</div>
</div>
<div class="footer"><?php echo esc_html( $business['name'] ); ?> &nbsp;·&nbsp; <?php echo esc_html( $invoice['number'] ); ?></div>
</body>
</html>
