<?php
/**
 * API endpoints — read-only directory with copy-paste curl per endpoint.
 *
 * @var array $view_data {
 *     @type string $namespace REST namespace, e.g. 'order-updates-for-woo/v1'.
 *     @type string $base_url  Absolute base URL for the namespace.
 *     @type array<int, array{
 *         path:string,
 *         methods:string,
 *         method_list:string[],
 *         url:string,
 *         params:array<int, array{name:string, source:string, type:string, required:bool, description:string}>,
 *         curl:string
 *     }> $endpoints
 * }
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$namespace = (string) ( $view_data['namespace'] ?? '' );
$base_url  = (string) ( $view_data['base_url'] ?? '' );
$endpoints = isset( $view_data['endpoints'] ) && is_array( $view_data['endpoints'] ) ? $view_data['endpoints'] : array();
?>
<h2 style="margin-top:1em;"><?php esc_html_e( 'REST API endpoints', 'order-updates-for-woo' ); ?></h2>

<p class="description" style="margin:0 0 12px;">
	<?php
	printf(
		/* translators: 1: REST namespace, 2: base URL */
		esc_html__( 'All endpoints live under the namespace %1$s. Base URL: %2$s.', 'order-updates-for-woo' ),
		'<code>' . esc_html( $namespace ) . '</code>',
		'<code>' . esc_html( $base_url ) . '</code>'
	);
	?>
</p>

<div style="padding:10px 12px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; max-width:1000px; margin:0 0 16px;">
	<p style="margin:0; font-weight:600; color:#1d4ed8;">
		<?php esc_html_e( 'Authentication', 'order-updates-for-woo' ); ?>
	</p>
	<p style="margin:4px 0 0; font-size:13px; color:#1e40af;">
		<?php
		printf(
			wp_kses(
				/* translators: 1: app password URL, 2: docs URL */
				__( 'For Postman or any external tool, use an <a href="%1$s">Application Password</a> (Basic Auth: <code>USERNAME:APP_PASSWORD</code>). Each curl below is pre-filled — replace <code>USERNAME</code> and <code>APP_PASSWORD</code> with real values and paste path placeholders like <code>&lt;UPDATE_ID&gt;</code>. <a href="%2$s">More on the WP REST auth options</a>.', 'order-updates-for-woo' ),
				array(
					'a'    => array( 'href' => array() ),
					'code' => array(),
				)
			),
			esc_url( admin_url( 'profile.php#application-passwords-section' ) ),
			'https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/'
		);
		?>
	</p>
</div>

<?php if ( empty( $endpoints ) ) : ?>
	<p><?php esc_html_e( 'No endpoints have been registered yet — load the front end at least once after activation.', 'order-updates-for-woo' ); ?></p>
<?php else : ?>
	<?php foreach ( $endpoints as $index => $endpoint ) : ?>
		<?php $curl_id = 'awts-curl-' . $index; ?>
		<div style="border:1px solid #d1d5db; border-radius:6px; padding:12px 14px; margin:0 0 12px; max-width:1000px; background:#fff;">
			<p style="margin:0 0 4px;">
				<strong style="font-family:monospace; color:#1d4ed8;"><?php echo esc_html( (string) ( $endpoint['methods'] ?? '' ) ); ?></strong>
				<code style="margin-left:8px;"><?php echo esc_html( (string) ( $endpoint['path'] ?? '' ) ); ?></code>
			</p>

			<?php $summary = (string) ( $endpoint['summary'] ?? '' ); ?>
			<?php if ( '' !== $summary ) : ?>
				<p style="margin:0 0 8px; color:#374151; font-size:13px;">
					<?php echo esc_html( $summary ); ?>
				</p>
			<?php endif; ?>

			<?php $params = isset( $endpoint['params'] ) && is_array( $endpoint['params'] ) ? $endpoint['params'] : array(); ?>

			<?php if ( ! empty( $params ) ) : ?>
				<p style="margin:8px 0 4px; font-size:12px; font-weight:600; color:#374151;">
					<?php esc_html_e( 'Parameters', 'order-updates-for-woo' ); ?>
				</p>
				<table class="widefat" style="max-width:100%; margin-bottom:8px;">
					<thead>
						<tr>
							<th style="width:25%;"><?php esc_html_e( 'Name', 'order-updates-for-woo' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'In', 'order-updates-for-woo' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'Type', 'order-updates-for-woo' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'Required', 'order-updates-for-woo' ); ?></th>
							<th><?php esc_html_e( 'Description', 'order-updates-for-woo' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $params as $param ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) ( $param['name'] ?? '' ) ); ?></code></td>
								<td><?php echo esc_html( (string) ( $param['source'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $param['type'] ?? '' ) ); ?></td>
								<td><?php echo ! empty( $param['required'] ) ? esc_html__( 'yes', 'order-updates-for-woo' ) : esc_html__( 'no', 'order-updates-for-woo' ); ?></td>
								<td><?php echo esc_html( (string) ( $param['description'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin:6px 0 4px;">
				<span style="font-size:12px; font-weight:600; color:#374151;">
					<?php esc_html_e( 'Copy and paste into Postman or your shell', 'order-updates-for-woo' ); ?>
				</span>
				<button
					type="button"
					class="button button-secondary"
					data-awts-copy-target="<?php echo esc_attr( $curl_id ); ?>"
					data-awts-copied-label="<?php echo esc_attr__( 'Copied!', 'order-updates-for-woo' ); ?>"
				>
					<?php esc_html_e( 'Copy curl', 'order-updates-for-woo' ); ?>
				</button>
			</div>
			<pre id="<?php echo esc_attr( $curl_id ); ?>" style="background:#0f172a; color:#e2e8f0; padding:12px; border-radius:4px; font-size:12px; line-height:1.5; overflow-x:auto; margin:0;"><?php echo esc_html( (string) ( $endpoint['curl'] ?? '' ) ); ?></pre>
		</div>
	<?php endforeach; ?>

	<p class="description" style="margin-top:12px;">
		<?php esc_html_e( 'Body parameters can be documented per endpoint via the order_updates_for_woo_api_endpoint_params filter, or by passing an "args" map to register_rest_route().', 'order-updates-for-woo' ); ?>
	</p>
<?php endif; ?>
