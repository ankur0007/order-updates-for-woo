<?php
/**
 * Customer-facing list of updates for a single order.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

use OrderUpdatesForWoo\Helpers\UpdateState;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$view_data      = isset( $view_data ) && is_array( $view_data ) ? $view_data : array();
$updates        = isset( $view_data['updates'] ) && is_array( $view_data['updates'] ) ? $view_data['updates'] : array();
$rating_config  = isset( $view_data['rating'] ) && is_array( $view_data['rating'] ) ? $view_data['rating'] : array( 'enabled' => false, 'comment_enabled' => false );
$show_assignee       = ! empty( $view_data['show_assignee'] );
$email_notifications = isset( $view_data['email_notifications'] ) ? (bool) $view_data['email_notifications'] : true;

if ( empty( $updates ) ) {
	return;
}

$rating_enabled         = ! empty( $rating_config['enabled'] );
$rating_comment_enabled = ! empty( $rating_config['comment_enabled'] );
$rating_heading         = __( 'How did we do?', 'order-updates-for-woo' );
$rating_intro           = __( 'Your feedback helps us improve. Rate this update and leave a comment if you like.', 'order-updates-for-woo' );
$rating_comment_label   = __( 'Comment (optional)', 'order-updates-for-woo' );
$rating_comment_ph      = __( 'Tell us what worked or what could be better…', 'order-updates-for-woo' );
$rating_submit_label    = __( 'Submit rating', 'order-updates-for-woo' );
/* translators: %s: rating value out of 5. */
$rating_thanks_template = __( 'You rated this %s/5. Thanks for your feedback!', 'order-updates-for-woo' );
/* translators: %d: number of stars. */
$rating_star_template   = __( '%d stars', 'order-updates-for-woo' );
$rating_star1_label     = __( '1 star', 'order-updates-for-woo' );

$heading_label  = __( 'Order updates', 'order-updates-for-woo' );
$resolved_label = __( 'Resolved', 'order-updates-for-woo' );
$open_label     = __( 'Open', 'order-updates-for-woo' );
$created_label  = __( 'Opened', 'order-updates-for-woo' );
$solved_label   = __( 'Resolved on', 'order-updates-for-woo' );
$no_notes_label = __( 'No messages yet.', 'order-updates-for-woo' );
$reply_label          = __( 'Write a reply', 'order-updates-for-woo' );
$reply_placeholder    = __( 'Type your reply…', 'order-updates-for-woo' );
$reply_submit_label   = __( 'Send reply', 'order-updates-for-woo' );
$reply_attach_label   = __( 'Attach files (optional)', 'order-updates-for-woo' );
$reopen_button_label  = __( 'Still has issue?', 'order-updates-for-woo' );
?>
<section class="awts_cou" aria-label="<?php echo esc_attr( $heading_label ); ?>">
	<div class="awts_cou_email_pref">
		<label class="awts_cou_email_pref__label">
			<input type="checkbox" class="awts_cou_email_pref__checkbox" data-awts-cou-email-pref<?php echo $email_notifications ? ' checked' : ''; ?>>
			<?php esc_html_e( 'Notify me via email for new updates', 'order-updates-for-woo' ); ?>
		</label>
	</div>
	<ul class="awts_cou__list">
		<?php foreach ( $updates as $index => $update ) :
			$color       = (string) ( $update['color'] ?? '' );
			$is_resolved = UpdateState::is_resolved( $update );
			$notes       = isset( $update['notes'] ) && is_array( $update['notes'] ) ? $update['notes'] : array();
			$is_open_by_default = ! $is_resolved && 0 === $index;
			$latest_note_id = 0;
			foreach ( $notes as $n ) {
				$nid = (int) ( $n['id'] ?? 0 );
				if ( $nid > $latest_note_id ) {
					$latest_note_id = $nid;
				}
			}
			?>
			<?php
			$assignee_since = (int) ( $update['assignee_since_note_id'] ?? 0 );
			$assignee_fname = (string) ( $update['assignee_first_name'] ?? '' );
			?>
			<li id="awts-update-<?php echo esc_attr( (string) ( $update['id'] ?? 0 ) ); ?>"
				class="awts_cou_item<?php echo $is_resolved ? ' awts_cou_item--resolved' : ''; ?>"
				data-awts-update-id="<?php echo esc_attr( (string) ( $update['id'] ?? 0 ) ); ?>"
				data-awts-latest-note-id="<?php echo esc_attr( (string) $latest_note_id ); ?>"
				<?php if ( $assignee_since > 0 && '' !== $assignee_fname ) : ?>
					data-awts-assignee-since="<?php echo esc_attr( (string) $assignee_since ); ?>"
					data-awts-assignee-name="<?php echo esc_attr( $assignee_fname ); ?>"
				<?php endif; ?>
			>
				<details class="awts_cou_item__details"<?php echo $is_open_by_default ? ' open' : ''; ?>>
					<summary class="awts_cou_item__summary">
						<span class="awts_cou_item__header">
							<?php if ( '' !== $color ) : ?>
								<span class="awts_cou_item__dot" style="background:<?php echo esc_attr( $color ); ?>;" aria-hidden="true"></span>
							<?php endif; ?>

							<span class="awts_cou_item__title"><?php echo esc_html( (string) ( $update['title'] ?? '' ) ); ?></span>

							<span class="awts_cou_unread_badge" data-awts-cou-unread-badge hidden></span>

							<span class="awts_cou_badge awts_cou_badge--<?php echo $is_resolved ? 'resolved' : 'open'; ?>">
								<?php echo esc_html( $is_resolved ? $resolved_label : $open_label ); ?>
							</span>

							<span class="awts_cou_item__chevron" aria-hidden="true">
								<svg width="14" height="14" viewBox="0 0 12 12" fill="none"><path d="M3 4.5 6 7.5 9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</span>
						</span>

						<?php $created_at_display = (string) ( $update['created_at'] ?? '' ); ?>
						<?php if ( '' !== $created_at_display ) : ?>
							<p class="awts_cou_item__opened">
								<?php echo esc_html( $created_label ); ?>
								<time><?php echo esc_html( $created_at_display ); ?></time>
							</p>
						<?php endif; ?>

						<?php if ( $show_assignee && ! empty( $update['assignee_first_name'] ) ) : ?>
							<p class="awts_cou_item__assignee">
								<?php echo esc_html( sprintf( /* translators: %s: assignee first name */ __( '%s is assisting you with this order update.', 'order-updates-for-woo' ), $update['assignee_first_name'] ) ); ?>
							</p>
						<?php endif; ?>
					</summary>

					<?php
					$update_id_attr = (int) ( $update['id'] ?? 0 );
					$rating         = isset( $update['rating'] ) && is_array( $update['rating'] ) ? $update['rating'] : array();
					$has_response   = ! empty( $rating['created_at'] );
					// Closed only when resolved AND rated. Resolved-but-unrated
					// still renders the reply form (hidden by default; the
					// "Still has issue?" button just un-hides it).
					$thread_closed   = $is_resolved && $has_response;
					$can_reopen      = $is_resolved && ! $has_response;
					$reply_hidden    = $can_reopen;
					?>
					<?php $has_more_notes = ! empty( $update['has_more_notes'] ); ?>
					<div class="awts_cou_notes"<?php if ( $has_more_notes && ! empty( $notes ) ) : ?> data-awts-cou-earliest-note-id="<?php echo esc_attr( (string) ( $notes[0]['id'] ?? 0 ) ); ?>"<?php endif; ?>>
						<?php if ( empty( $notes ) ) : ?>
							<p class="awts_cou_notes__empty"><?php echo esc_html( $no_notes_label ); ?></p>
						<?php else : ?>
						<?php if ( $has_more_notes ) : ?>
							<button type="button" class="awts_cou_load_prev" data-awts-cou-load-prev>
								<?php esc_html_e( 'Load previous', 'order-updates-for-woo' ); ?>
							</button>
						<?php endif; ?>
						<?php foreach ( $notes as $note ) :
								// System events (status_change, assignee_change, etc.) render
								// as a compact centered marker, not a chat bubble. Mirrors
								// the JS path (createSystemNoteElement) for the initial paint.
								if ( ! empty( $note['is_system'] ) ) :
									$system_note_text = (string) ( $note['note'] ?? '' );
									$system_when      = (string) ( $note['created_at'] ?? '' );
									?>
									<article class="awts_cou_note awts_cou_note--system" data-awts-note-id="<?php echo esc_attr( (string) ( $note['id'] ?? 0 ) ); ?>">
										<span class="awts_cou_system__dot" aria-hidden="true"></span>
										<span class="awts_cou_system__text"><?php echo esc_html( $system_note_text ); ?></span>
										<?php if ( '' !== $system_when ) : ?>
											<span class="awts_cou_system__meta"><?php echo esc_html( $system_when ); ?></span>
										<?php endif; ?>
									</article>
									<?php
									continue;
								endif;

								$attachments = isset( $note['attachments'] ) && is_array( $note['attachments'] ) ? $note['attachments'] : array();
								$is_staff    = ! empty( $note['is_staff'] );
								$author_text = (string) ( $note['author_display'] ?? $note['created_by_name'] ?? '' );
								$can_edit    = ! empty( $note['can_edit'] ) && ! $thread_closed;
								$edited_at   = (string) ( $note['edited_at'] ?? '' );
								?>
								<?php $avatar_url = (string) ( $note['avatar_url'] ?? '' ); ?>
								<article class="awts_cou_note awts_cou_note--<?php echo $is_staff ? 'staff' : 'customer'; ?>" data-awts-note-id="<?php echo esc_attr( (string) ( $note['id'] ?? 0 ) ); ?>" data-awts-note-text="<?php echo esc_attr( (string) ( $note['note'] ?? '' ) ); ?>">
									<?php if ( $is_staff && '' !== $avatar_url ) : ?>
										<img class="awts_cou_note__avatar" src="<?php echo esc_url( $avatar_url ); ?>" alt="" width="28" height="28" loading="lazy" />
									<?php endif; ?>
									<div class="awts_cou_note__bubble">
									<header class="awts_cou_note__header">
										<div class="awts_cou_note__meta">
											<span class="awts_cou_note__author"><?php echo esc_html( $author_text ); ?></span>
											<time class="awts_cou_note__time"><?php echo esc_html( (string) ( $note['created_at'] ?? '' ) ); ?></time>
											<?php if ( '' !== $edited_at ) : ?>
												<button type="button" class="awts_cou_note__edited" data-awts-cou-note-history data-awts-update-id="<?php echo esc_attr( (string) $update_id_attr ); ?>" aria-expanded="false">
													<?php
													printf(
														/* translators: %s: edit timestamp */
														esc_html__( 'Edited %s · view history', 'order-updates-for-woo' ),
														esc_html( $edited_at )
													);
													?>
												</button>
											<?php endif; ?>
										</div>
										<?php if ( $can_edit ) : ?>
											<div class="awts_cou_note__actions">
												<button type="button" class="awts_cou_note__action awts_cou_note__action--icon" data-awts-cou-note-edit data-awts-update-id="<?php echo esc_attr( (string) $update_id_attr ); ?>" aria-label="<?php echo esc_attr__( 'Edit note', 'order-updates-for-woo' ); ?>" title="<?php echo esc_attr__( 'Edit note', 'order-updates-for-woo' ); ?>">
													<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M13.8 2.9a2.1 2.1 0 0 1 3 3L7.3 15.4 3 17l1.6-4.3 9.2-9.8Zm1 1-8.9 9.5-.7 1.8 1.8-.7 8.9-9.5a.7.7 0 0 0-1-1Z" fill="currentColor"/></svg>
												</button>
											</div>
										<?php endif; ?>
									</header>

									<div class="awts_cou_note__body">
										<?php echo wp_kses_post( wpautop( wptexturize( (string) ( $note['note'] ?? '' ) ) ) ); ?>
									</div>

									<?php if ( ! empty( $attachments ) ) : ?>
										<ul class="awts_cou_attachments">
											<?php foreach ( $attachments as $attachment ) :
												$is_image = ! empty( $attachment['is_image'] );
												?>
												<li>
													<a href="<?php echo esc_url( (string) ( $attachment['url'] ?? '' ) ); ?>" target="_blank" rel="noopener noreferrer" class="awts_cou_attachment__link">
														<?php if ( $is_image ) : ?>
															<img class="awts_cou_attachment__thumb" src="<?php echo esc_url( (string) ( $attachment['url'] ?? '' ) ); ?>" alt="<?php echo esc_attr( (string) ( $attachment['name'] ?? '' ) ); ?>" loading="lazy" />
														<?php else : ?>
															<span class="awts_cou_attachment__icon" aria-hidden="true">
																<svg width="18" height="18" viewBox="0 0 16 16" fill="none"><path d="M9 1H3.5A1.5 1.5 0 0 0 2 2.5v11A1.5 1.5 0 0 0 3.5 15h9a1.5 1.5 0 0 0 1.5-1.5V6L9 1Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M9 1v5h5" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>
															</span>
														<?php endif; ?>
														<span class="awts_cou_attachment__name"><?php echo esc_html( (string) ( $attachment['name'] ?? '' ) ); ?></span>
													</a>
												</li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>
									</div>
								</article>
							<?php endforeach; ?>
						<?php endif; ?>

						<?php if ( $update_id_attr > 0 && ! $thread_closed ) : ?>
							<form class="awts_cou_reply"<?php echo $reply_hidden ? ' hidden' : ''; ?> data-awts-cou-reply-form data-awts-cou-update-id="<?php echo esc_attr( (string) $update_id_attr ); ?>" onsubmit="return false;" novalidate>
								<div class="awts_drop_overlay" aria-hidden="true"><span><?php esc_html_e( 'Drop files to attach', 'order-updates-for-woo' ); ?></span></div>
								<label class="awts_cou_reply__label">
									<span class="awts_cou_field__label"><?php echo esc_html( $reply_label ); ?></span>
									<textarea rows="3" maxlength="500" required
										placeholder="<?php echo esc_attr( $reply_placeholder ); ?>"
										data-awts-cou-reply-message></textarea>
								</label>

								<div class="awts_cou_reply__toolbar">
									<label class="awts_cou_reply__attach">
										<span class="awts_cou_reply__attach_label"><?php echo esc_html( $reply_attach_label ); ?></span>
										<input type="file" multiple data-awts-cou-reply-files />
									</label>
									<button type="button" class="awts_cou_reply__emoji_trigger" data-awts-cou-emoji-trigger aria-label="<?php echo esc_attr__( 'Add emoji', 'order-updates-for-woo' ); ?>">
										<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.2"/><circle cx="5.5" cy="6.5" r="1" fill="currentColor"/><circle cx="10.5" cy="6.5" r="1" fill="currentColor"/><path d="M5 10c.8 1.2 2 1.8 3 1.8s2.2-.6 3-1.8" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
									</button>
									<span class="awts_cou_reply__drop_hint"><?php esc_html_e( 'Drag and drop files supported', 'order-updates-for-woo' ); ?></span>
									<label class="awts_cou_enter_to_send" title="<?php echo esc_attr__( 'Press Enter to send. Use Shift+Enter for a new line.', 'order-updates-for-woo' ); ?>">
										<input type="checkbox" data-awts-enter-to-send>
										<?php esc_html_e( 'Enter = Send', 'order-updates-for-woo' ); ?>
									</label>
									<button type="submit" class="awts_cou_btn awts_cou_btn--primary" data-awts-cou-reply-submit>
										<?php echo esc_html( $reply_submit_label ); ?>
									</button>
								</div>

								<ul class="awts_cou_file_list" data-awts-cou-reply-file-list></ul>
								<div class="awts_cou_reply__feedback" data-awts-cou-reply-feedback aria-live="polite"></div>
							</form>
						<?php endif; ?>

						<?php if ( $can_reopen && $update_id_attr > 0 ) : ?>
							<button type="button"
								class="awts_cou_reopen_btn"
								data-awts-cou-reopen
								data-awts-cou-update-id="<?php echo esc_attr( (string) $update_id_attr ); ?>">
								<?php echo esc_html( $reopen_button_label ); ?>
							</button>
						<?php endif; ?>

						<?php $show_rating = $is_resolved && $update_id_attr > 0 && $rating_enabled; ?>
						<?php if ( $show_rating && $has_response ) :
							$stars = max( 0, min( 5, (int) ( $rating['stars'] ?? 0 ) ) );
							$thanks_text = sprintf( $rating_thanks_template, $stars );
							?>
							<div class="awts_cou_rating awts_cou_rating--submitted">
								<p class="awts_cou_rating__thanks"><?php echo esc_html( $thanks_text ); ?></p>
								<div class="awts_cou_rating__stars" aria-label="<?php echo esc_attr( sprintf( $rating_star_template, $stars ) ); ?>">
									<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
										<span class="awts_cou_rating__star<?php echo $i <= $stars ? ' awts_cou_rating__star--filled' : ''; ?>" aria-hidden="true">★</span>
									<?php endfor; ?>
								</div>
								<?php if ( ! empty( $rating['comment'] ) ) : ?>
									<blockquote class="awts_cou_rating__comment"><?php echo esc_html( (string) $rating['comment'] ); ?></blockquote>
								<?php endif; ?>
							</div>
						<?php elseif ( $show_rating ) : ?>
							<form class="awts_cou_rating awts_cou_rating--form" data-awts-cou-rating-form data-awts-cou-update-id="<?php echo esc_attr( (string) $update_id_attr ); ?>" novalidate>
								<h3 class="awts_cou_rating__heading"><?php echo esc_html( $rating_heading ); ?></h3>
								<p class="awts_cou_rating__intro"><?php echo esc_html( $rating_intro ); ?></p>

								<div class="awts_cou_rating__stars" role="radiogroup" aria-label="<?php echo esc_attr( $rating_heading ); ?>">
									<?php for ( $i = 1; $i <= 5; $i++ ) :
										$star_aria = 1 === $i ? $rating_star1_label : sprintf( $rating_star_template, $i );
										?>
										<button type="button" class="awts_cou_rating__star_btn"
											role="radio" aria-checked="false" tabindex="<?php echo 1 === $i ? '0' : '-1'; ?>"
											data-awts-cou-rating-star="<?php echo esc_attr( (string) $i ); ?>"
											aria-label="<?php echo esc_attr( $star_aria ); ?>">★</button>
									<?php endfor; ?>
								</div>
								<input type="hidden" name="stars" value="0" data-awts-cou-rating-value />

								<?php if ( $rating_comment_enabled ) : ?>
									<label class="awts_cou_rating__comment_label">
										<span class="awts_cou_field__label"><?php echo esc_html( $rating_comment_label ); ?></span>
										<textarea rows="3" maxlength="500"
											placeholder="<?php echo esc_attr( $rating_comment_ph ); ?>"
											data-awts-cou-rating-comment></textarea>
									</label>
								<?php endif; ?>

								<div class="awts_cou_rating__toolbar">
									<button type="submit" class="awts_cou_btn awts_cou_btn--primary" data-awts-cou-rating-submit>
										<?php echo esc_html( $rating_submit_label ); ?>
									</button>
								</div>

								<div class="awts_cou_rating__feedback" data-awts-cou-rating-feedback aria-live="polite"></div>
							</form>
						<?php endif; ?>
					</div>
				</details>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
