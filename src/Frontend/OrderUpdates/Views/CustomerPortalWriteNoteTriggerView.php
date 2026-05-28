<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="awts_cou_page__actions">
	<p class="awts_cou_write_note_prompt">
		<?php
		printf(
			/* translators: %s: linked text "Write it here" that opens the write-note modal */
			esc_html__( 'Have a question, query or problem? %s', 'order-updates-for-woo' ),
			'<a href="#" class="awts_cou_write_note_trigger" data-awts-cou-open>' . esc_html__( 'Write it here', 'order-updates-for-woo' ) . '</a>'
		);
		?>
	</p>
</div>
