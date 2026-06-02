<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
/**
 * Email template — customer-facing notifications.
 *
 * Same card-style layout as the admin template, including the same WC-default
 * purple palette so the email reads as part of the store's existing email
 * stream rather than a different product.
 */
do_action('woocommerce_email_header', $email_heading, $email);
?>

<?php if (! empty($status_label)) : ?>
	<p style="margin:0 0 16px;">
		<span style="display:inline-block; padding:5px 12px; background:#f3eafd; color:#7f54b3; border-radius:999px; font-size:12px; font-weight:600;">
			<span style="display:inline-block; width:6px; height:6px; margin-right:6px; background:#7f54b3; border-radius:50%; vertical-align:middle;"></span><?php echo esc_html($status_label); ?>
		</span>
	</p>
<?php endif; ?>

<?php if (! empty($greeting_name)) : ?>
	<p style="margin:0 0 20px; font-size:15px; color:#515151;">
		<?php
		printf(
			/* translators: 1: customer first name, 2: intro text. */
			esc_html__( 'Hi %1$s — %2$s', 'order-updates-for-woo' ),
			'<strong style="color:#101517;">' . esc_html($greeting_name) . '</strong>',
			esc_html($intro_text)
		);
		?>
	</p>
<?php else : ?>
	<p style="margin:0 0 20px; font-size:15px; color:#515151;"><?php echo esc_html($intro_text); ?></p>
<?php endif; ?>

<?php
// Only draw the card when it has something to show. Link-only emails (e.g. the
// chat-link refresh) carry no title, note, attachments or detail rows, so the
// box would otherwise render empty.
$has_card_content = ! empty($order_update['title'])
	|| ! empty($note_content)
	|| ! empty($note_attachments)
	|| ! empty($detail_rows);
?>

<?php if ($has_card_content) : ?>
<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%; margin:0 0 24px; background:#fafafa; border:1px solid #e5e5e5; border-radius:6px;">
	<tr>
		<td style="padding:20px 22px;">

			<?php if (! empty($order_update['title'])) : ?>
				<p style="margin:0 0 4px; font-size:11px; font-weight:600; letter-spacing:0.06em; text-transform:uppercase; color:#666666;">
					<?php esc_html_e('Update', 'order-updates-for-woo'); ?>
				</p>
				<h2 style="margin:0 0 16px; font-size:18px; font-weight:700; color:#101517; line-height:1.3;">
					<?php echo esc_html((string) $order_update['title']); ?>
				</h2>
			<?php endif; ?>

			<?php if (! empty($note_content)) : ?>
				<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%; margin:0 0 16px;">
					<tr>
						<td style="border-left:3px solid #7f54b3; background:#ffffff; padding:12px 16px; border-radius:4px;">
							<p style="margin:0 0 4px; font-size:11px; font-weight:600; letter-spacing:0.06em; text-transform:uppercase; color:#666666;">
								<?php echo esc_html($note_label); ?>
							</p>
							<div style="margin:0; font-size:14px; color:#515151; font-style:italic; line-height:1.6;">
								<?php echo wp_kses_post( wpautop( wptexturize( $note_content ) ) ); ?>
							</div>
							<?php if (! empty($note_author) || ! empty($note_created_at)) : ?>
								<p style="margin:8px 0 0; font-size:12px; color:#666666; font-style:normal;">
									&mdash;
									<?php if (! empty($note_author)) : ?>
										<strong style="font-weight:600; color:#101517;"><?php echo esc_html($note_author); ?></strong>
									<?php endif; ?>
									<?php if (! empty($note_author) && ! empty($note_created_at)) : ?>
										<span style="color:#9ca3af;"> &middot; </span>
									<?php endif; ?>
									<?php if (! empty($note_created_at)) : ?>
										<?php echo esc_html($note_created_at); ?>
									<?php endif; ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			<?php endif; ?>

			<?php if (! empty($note_attachments)) : ?>
				<div style="margin:0 0 16px;">
					<?php if (! empty($note_attachments_label)) : ?>
						<p style="margin:0 0 8px; font-size:11px; font-weight:600; letter-spacing:0.06em; text-transform:uppercase; color:#666666;"><?php echo esc_html($note_attachments_label); ?></p>
					<?php endif; ?>
					<ul style="margin:0; padding:0; list-style:none;">
						<?php foreach ($note_attachments as $note_attachment) : ?>
							<li style="margin:0 0 6px;">
								<a href="<?php echo esc_url((string) ($note_attachment['url'] ?? '')); ?>" style="color:#7f54b3; text-decoration:underline;">
									<?php echo esc_html((string) ($note_attachment['name'] ?? '')); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if (! empty($detail_rows)) : ?>
				<div style="margin:16px 0 0; padding:16px 0 0; border-top:1px solid #e5e5e5;">
					<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%; font-size:13px;">
						<?php foreach ($detail_rows as $detail_row) :
							$label = trim((string) ($detail_row['label'] ?? ''));
							$value = trim((string) ($detail_row['value'] ?? ''));
							if ('' === $label && '' === $value) {
								continue;
							}
							$display_label = $label;
							$display_value = $value;
							if ('' !== $label && '' !== $value && 0 === stripos($value, $label)) {
								$display_value = trim(substr($value, strlen($label)));
								$display_value = ltrim($display_value, " \t\n\r\0\x0B-:");
							}
							?>
							<tr>
								<td style="padding:6px 16px 6px 0; color:#666666; vertical-align:top; width:120px; white-space:nowrap;">
									<?php echo esc_html($display_label); ?>
								</td>
								<td style="padding:6px 0; color:#101517; vertical-align:top;">
									<strong style="font-weight:600; color:#101517;"><?php echo esc_html($display_value); ?></strong>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				</div>
			<?php endif; ?>

		</td>
	</tr>
</table>
<?php endif; ?>

<?php if (! empty($action_url) && ! empty($action_label)) : ?>
	<p style="margin:0 0 28px;">
		<a href="<?php echo esc_url($action_url); ?>" style="display:inline-block; padding:11px 22px; background:#7f54b3; color:#ffffff; text-decoration:none; border-radius:4px; font-weight:600; font-size:14px;">
			<?php echo esc_html($action_label); ?> &rarr;
		</a>
	</p>
<?php endif; ?>

<?php do_action('order_updates_for_woo_email_action'); ?>

<?php if ($order instanceof WC_Order) : ?>
	<p style="font-size:13px; color:#666666;"><?php esc_html_e( 'Order details are included below.', 'order-updates-for-woo' ); ?></p>

	<?php
	do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);
	?>
<?php endif; ?>

<?php if (! empty($additional_content)) : ?>
	<?php echo wp_kses_post(wpautop(wptexturize($additional_content))); ?>
<?php endif; ?>

<?php
do_action('woocommerce_email_footer', $email);
