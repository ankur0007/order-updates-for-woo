<?php
/**
 * Customer rating follow-up email template.
 *
 * Branched body: promoters see share buttons; detractors see an empathetic
 * "we'll do better" note with a reply prompt and a link back to the update.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email );
$status_label = $status_label ?? '';
?>

<?php if ( '' !== $status_label ) : ?>
	<p style="margin:0 0 16px;">
		<span style="display:inline-block; padding:5px 12px; background:#f3eafd; color:#7f54b3; border-radius:999px; font-size:12px; font-weight:600;">
			<span style="display:inline-block; width:6px; height:6px; margin-right:6px; background:#7f54b3; border-radius:50%; vertical-align:middle;"></span><?php echo esc_html( $status_label ); ?>
		</span>
	</p>
<?php endif; ?>

<?php if ( ! empty( $greeting_name ) ) : ?>
	<p style="margin:0 0 20px; font-size:15px; color:#515151;">
		<?php
		printf(
			/* translators: 1: customer first name, 2: intro text. */
			esc_html__( 'Hi %1$s — %2$s', 'order-updates-for-woo' ),
			'<strong style="color:#101517;">' . esc_html( $greeting_name ) . '</strong>',
			esc_html( $intro_text )
		);
		?>
	</p>
<?php else : ?>
	<p style="margin:0 0 20px; font-size:15px; color:#515151;"><?php echo esc_html( $intro_text ); ?></p>
<?php endif; ?>

<?php if ( ! empty( $rating_comment ) ) : ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%; margin:0 0 24px;">
		<tr>
			<td style="border-left:3px solid #7f54b3; background:#ffffff; padding:12px 16px; border-radius:4px;">
				<p style="margin:0 0 4px; font-size:11px; font-weight:600; letter-spacing:0.06em; text-transform:uppercase; color:#666666;">
					<?php esc_html_e( 'Your comment', 'order-updates-for-woo' ); ?>
				</p>
				<div style="margin:0; font-size:14px; color:#515151; line-height:1.6;">
					<?php echo nl2br( esc_html( $rating_comment ) ); ?>
				</div>
			</td>
		</tr>
	</table>
<?php endif; ?>

<?php if ( ! empty( $is_promoter ) && ! empty( $share_links ) ) : ?>
	<p style="margin:0 0 12px; color:#515151;"><?php esc_html_e( 'If you have a moment, sharing your experience would help others find us:', 'order-updates-for-woo' ); ?></p>
	<p style="margin:0 0 24px;">
		<?php foreach ( $share_links as $share_link ) : ?>
			<a href="<?php echo esc_url( (string) ( $share_link['url'] ?? '' ) ); ?>"
			   style="display:inline-block; margin:0 8px 8px 0; padding:9px 16px; background:#7f54b3; color:#ffffff; text-decoration:none; border-radius:4px; font-weight:600; font-size:14px;">
				<?php echo esc_html( (string) ( $share_link['label'] ?? '' ) ); ?>
			</a>
		<?php endforeach; ?>
	</p>
<?php elseif ( empty( $is_promoter ) ) : ?>
	<p style="margin:0 0 24px; color:#515151;"><?php echo esc_html( (string) ( $detractor_text ?? '' ) ); ?></p>
<?php endif; ?>

<?php if ( ! empty( $action_url ) && ! empty( $action_label ) ) : ?>
	<p style="margin:0 0 24px;">
		<a href="<?php echo esc_url( $action_url ); ?>" style="display:inline-block; padding:11px 22px; background:#7f54b3; color:#ffffff; text-decoration:none; border-radius:4px; font-weight:600; font-size:14px;">
			<?php echo esc_html( $action_label ); ?> &rarr;
		</a>
	</p>
<?php endif; ?>

<?php do_action( 'order_updates_for_woo_rating_followup_email_action', $is_promoter, $stars, $order ); ?>

<?php if ( ! empty( $additional_content ) ) : ?>
	<?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
<?php endif; ?>

<?php
do_action( 'woocommerce_email_footer', $email );
