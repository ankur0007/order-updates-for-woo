<?php
/**
 * Plugin email directory.
 *
 * @var array $view_data {
 *     @type array<int, array{id:string, title:string, description:string, enabled:bool, edit_url:string, toggle_url:string}> $emails
 *     @type string $wc_emails_url Absolute URL to WC's full email settings tab.
 * }
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$emails        = isset( $view_data['emails'] ) && is_array( $view_data['emails'] ) ? $view_data['emails'] : array();
$wc_emails_url = (string) ( $view_data['wc_emails_url'] ?? '' );
?>
<h2 style="margin-top:1em;"><?php esc_html_e( 'Plugin emails', 'order-updates-for-woo' ); ?></h2>

<p class="description" style="margin:0 0 12px;">
	<?php esc_html_e( 'Each email\'s subject, recipient, content, and template settings live on its own page under WooCommerce → Settings → Emails. Click "Edit" on any row below to jump straight to that email\'s page. Use the toggle to enable or disable an email without leaving this tab.', 'order-updates-for-woo' ); ?>
</p>

<?php if ( empty( $emails ) ) : ?>
	<p>
		<?php esc_html_e( 'Plugin emails are not registered yet — load any admin page once after activation, then return here.', 'order-updates-for-woo' ); ?>
	</p>
<?php else : ?>
	<table class="widefat striped" style="max-width:1000px;">
		<thead>
			<tr>
				<th style="width:30%;"><?php esc_html_e( 'Email', 'order-updates-for-woo' ); ?></th>
				<th><?php esc_html_e( 'Description', 'order-updates-for-woo' ); ?></th>
				<th style="width:90px;"><?php esc_html_e( 'Status', 'order-updates-for-woo' ); ?></th>
				<th style="width:180px;"><?php esc_html_e( 'Actions', 'order-updates-for-woo' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $emails as $email ) : ?>
				<tr>
					<td>
						<strong><?php echo esc_html( (string) ( $email['title'] ?? '' ) ); ?></strong>
						<br>
						<code style="font-size:11px; color:#6b7280;"><?php echo esc_html( (string) ( $email['id'] ?? '' ) ); ?></code>
					</td>
					<td>
						<?php echo esc_html( (string) ( $email['description'] ?? '' ) ); ?>
					</td>
					<td>
						<?php if ( ! empty( $email['enabled'] ) ) : ?>
							<span style="display:inline-block; padding:2px 8px; background:#dcfce7; color:#166534; border-radius:10px; font-size:12px; font-weight:500;">
								<?php esc_html_e( 'Enabled', 'order-updates-for-woo' ); ?>
							</span>
						<?php else : ?>
							<span style="display:inline-block; padding:2px 8px; background:#fee2e2; color:#991b1b; border-radius:10px; font-size:12px; font-weight:500;">
								<?php esc_html_e( 'Disabled', 'order-updates-for-woo' ); ?>
							</span>
						<?php endif; ?>
					</td>
					<td>
						<a href="<?php echo esc_url( (string) ( $email['edit_url'] ?? '' ) ); ?>" class="button button-secondary" style="margin-right:4px;">
							<?php esc_html_e( 'Edit', 'order-updates-for-woo' ); ?>
						</a>
						<a href="<?php echo esc_url( (string) ( $email['toggle_url'] ?? '' ) ); ?>" class="button">
							<?php echo $email['enabled'] ? esc_html__( 'Disable', 'order-updates-for-woo' ) : esc_html__( 'Enable', 'order-updates-for-woo' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php if ( '' !== $wc_emails_url ) : ?>
	<p style="margin-top:14px;">
		<a href="<?php echo esc_url( $wc_emails_url ); ?>" class="button">
			<?php esc_html_e( 'Open all WooCommerce emails', 'order-updates-for-woo' ); ?>
		</a>
	</p>
<?php endif; ?>
