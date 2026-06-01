<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\API\Endpoints\AddCustomerNoteEndpoint;
use OrderUpdatesForWoo\API\Endpoints\AddUpdateNoteEndpoint;
use OrderUpdatesForWoo\API\Endpoints\AssigneeSearchEndpoint;
use OrderUpdatesForWoo\API\Endpoints\ChangeUpdateStatusEndpoint;
use OrderUpdatesForWoo\API\Endpoints\DeleteAttachmentEndpoint;
use OrderUpdatesForWoo\API\Endpoints\DeleteUpdateNoteEndpoint;
use OrderUpdatesForWoo\API\Endpoints\DeleteUpdateEndpoint;
use OrderUpdatesForWoo\API\Endpoints\GetCustomerNoteHistoryEndpoint;
use OrderUpdatesForWoo\API\Endpoints\GetPreviousCustomerNotesEndpoint;
use OrderUpdatesForWoo\API\Endpoints\PollCustomerThreadEndpoint;
use OrderUpdatesForWoo\API\Endpoints\GetCustomerNotesEndpoint;
use OrderUpdatesForWoo\API\Endpoints\GetUpdateNotesEndpoint;
use OrderUpdatesForWoo\API\Endpoints\MarkSolvedEndpoint;
use OrderUpdatesForWoo\API\Endpoints\NotifyCustomerEndpoint;
use OrderUpdatesForWoo\API\Endpoints\OrderUpdatesListEndpoint;
use OrderUpdatesForWoo\API\Endpoints\ReopenUpdateEndpoint;
use OrderUpdatesForWoo\API\Endpoints\SaveEmailPreferenceEndpoint;
use OrderUpdatesForWoo\API\Endpoints\SaveStaffEmailPreferenceEndpoint;
use OrderUpdatesForWoo\API\Endpoints\SaveUpdateEndpoint;
use OrderUpdatesForWoo\API\Endpoints\ServeAttachmentEndpoint;
use OrderUpdatesForWoo\API\Endpoints\SharedLinkEndpoint;
use OrderUpdatesForWoo\API\Endpoints\SingleOrderUpdateEndpoint;
use OrderUpdatesForWoo\API\Endpoints\SubmitCustomerUpdateEndpoint;
use OrderUpdatesForWoo\API\Endpoints\SubmitRatingEndpoint;
use OrderUpdatesForWoo\API\Endpoints\UpdateCustomerNoteEndpoint;
use OrderUpdatesForWoo\API\Endpoints\UpdateUpdateNoteEndpoint;
use OrderUpdatesForWoo\API\Endpoints\UpdateActionHistoryEndpoint;
use OrderUpdatesForWoo\API\Endpoints\UploadAttachmentEndpoint;
use OrderUpdatesForWoo\API\Endpoints\Analytics\GetAnalyticsSummaryEndpoint;
use OrderUpdatesForWoo\API\Endpoints\Analytics\GetAnalyticsByDateEndpoint;
use OrderUpdatesForWoo\API\Endpoints\Analytics\GetAnalyticsAssigneesEndpoint;
use OrderUpdatesForWoo\API\Endpoints\Analytics\GetAnalyticsProductsEndpoint;
use OrderUpdatesForWoo\API\RestApiRegistrar;
use OrderUpdatesForWoo\Shared\Analytics\AnalyticsLookupDb;
use OrderUpdatesForWoo\Shared\Analytics\AnalyticsLookupTable;
use OrderUpdatesForWoo\Admin\AdminBar\AdminBarNotifications;
use OrderUpdatesForWoo\Admin\Analytics\AnalyticsController;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Admin\Notifications\AdminNotifications;
use OrderUpdatesForWoo\Welcome\Controllers\OnboardingController;
use OrderUpdatesForWoo\Welcome\Controllers\WelcomeController;
use OrderUpdatesForWoo\Admin\Orders\OrderTableUpdateFiltersController;
use OrderUpdatesForWoo\Admin\Orders\OrderUpdatesPanelController;
use OrderUpdatesForWoo\Admin\Orders\Services\OrderEditorPanelService;
use OrderUpdatesForWoo\Admin\Orders\Services\OrderTableFiltersService;
use OrderUpdatesForWoo\Welcome\Controllers\NewsletterController;
use OrderUpdatesForWoo\Admin\Settings\Controllers\ApiSettingsController;
use OrderUpdatesForWoo\Admin\Settings\Controllers\AttachmentsSettingsController;
use OrderUpdatesForWoo\Admin\Settings\Controllers\CacheSettingsController;
use OrderUpdatesForWoo\Admin\Settings\Controllers\EmailsSettingsController;
use OrderUpdatesForWoo\Admin\Settings\Controllers\GeneralSettingsController;
use OrderUpdatesForWoo\Admin\Settings\Controllers\MembersSettingsController;
use OrderUpdatesForWoo\Admin\Settings\Controllers\ShortcodesSettingsController;
use OrderUpdatesForWoo\Admin\Settings\OrderUpdatesSettingsController;
use OrderUpdatesForWoo\Admin\Settings\Services\ApiSettingsService;
use OrderUpdatesForWoo\Admin\Settings\Services\AttachmentsSettingsService;
use OrderUpdatesForWoo\Admin\Settings\Services\CacheSettingsService;
use OrderUpdatesForWoo\Admin\Settings\Services\EmailsSettingsService;
use OrderUpdatesForWoo\Admin\Settings\Services\GeneralSettingsService;
use OrderUpdatesForWoo\Admin\Settings\Services\MembersSettingsService;
use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\Admin\Settings\Services\ShortcodesSettingsService;
use OrderUpdatesForWoo\Frontend\Notifications\FrontendNotifications;
use OrderUpdatesForWoo\Frontend\OrderUpdates\CheckoutEmailInjector;
use OrderUpdatesForWoo\Frontend\OrderUpdates\CustomerOrderUpdatesController;
use OrderUpdatesForWoo\Frontend\OrderUpdates\OrderUpdatesShortcode;
use OrderUpdatesForWoo\Frontend\OrderUpdates\Services\CustomerOrderUpdatesService;
use OrderUpdatesForWoo\Helpers\AsyncHealth;
use OrderUpdatesForWoo\Helpers\AsyncJob;
use OrderUpdatesForWoo\Helpers\StaffEmailPreference;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentService;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsTable;
use OrderUpdatesForWoo\Shared\Notifications\NotificationDispatcher;
use OrderUpdatesForWoo\Shared\Team\TeamRosterService;
use OrderUpdatesForWoo\Shared\Notifications\NotificationScheduler;
use OrderUpdatesForWoo\Shared\Notifications\NotificationStatusUpdater;
use OrderUpdatesForWoo\Shared\Notifications\RatingFollowupScheduler;
use OrderUpdatesForWoo\Shared\Notifications\RatingRequestScheduler;
use OrderUpdatesForWoo\Admin\Orders\AdminHeartbeatHandler;
use OrderUpdatesForWoo\Admin\Orders\OrderDeletionController;
use OrderUpdatesForWoo\Admin\Orders\OrderLockBanner;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Updates\NoteActionPolicy;
use OrderUpdatesForWoo\Shared\Updates\UpdateCardVariableParser;
use OrderUpdatesForWoo\Shared\Updates\UpdateNoteService;
use OrderUpdatesForWoo\Shared\Updates\UpdatesTable;
use OrderUpdatesForWoo\Shared\Validation\Validator;

final class Plugin {
	public function powerOn(): void {
		$table = new UpdatesTable();
		$db = new OrderUpdatesDb( $table );

		$analytics_lookup_table = new AnalyticsLookupTable();
		$analytics_lookup_db    = new AnalyticsLookupDb( $analytics_lookup_table, $table );
		$participant_resolver = new \OrderUpdatesForWoo\Helpers\ParticipantResolver( $db );
		$update_card_parser = new UpdateCardVariableParser( $db, $participant_resolver );
		$team_roster = new TeamRosterService();
		$settings = new OrderUpdatesSettingsService();
		$async_health = new AsyncHealth();
		$async = new AsyncJob( $async_health );
		$note_service = new UpdateNoteService( $db, $async, $participant_resolver );
		$note_action_policy = new NoteActionPolicy( $settings );

		$attachments_table = new AttachmentsTable();
		$attachments_db = new AttachmentsDb( $attachments_table );
		$attachment_service = new AttachmentService( $attachments_db );

		$customer_updates_service = new CustomerOrderUpdatesService( $db, $attachments_db, $settings, $note_action_policy );

		$rest = new RestApiRegistrar(
			new AssigneeSearchEndpoint( $team_roster ),
			new OrderUpdatesListEndpoint( $db, $settings, $update_card_parser ),
			new SaveUpdateEndpoint( $db, new Validator(), $settings, $update_card_parser, $note_service ),
			new SingleOrderUpdateEndpoint( $db ),
			new MarkSolvedEndpoint( $db, $settings, $update_card_parser, $async ),
			new ReopenUpdateEndpoint( $db, $settings, $update_card_parser, $customer_updates_service ),
			new ChangeUpdateStatusEndpoint( $db, $settings, $update_card_parser, $async ),
			new DeleteUpdateEndpoint( $db, $attachment_service ),
			new UpdateActionHistoryEndpoint( $db ),
			new NotifyCustomerEndpoint( $db, $async ),
			new GetUpdateNotesEndpoint( $db, $attachments_db, $note_action_policy ),
			new AddUpdateNoteEndpoint( $db, $note_service, $note_action_policy, new Validator(), $async ),
			new UpdateUpdateNoteEndpoint( $db, $note_action_policy, new Validator(), $settings ),
			new DeleteUpdateNoteEndpoint( $db, $note_action_policy, $settings ),
			new GetCustomerNotesEndpoint( $db, $attachments_db, $note_action_policy ),
			new AddCustomerNoteEndpoint( $db, $note_service, $note_action_policy, new Validator(), $async ),
			new UpdateCustomerNoteEndpoint( $db, $customer_updates_service, $note_action_policy, new Validator(), $settings ),
			new GetCustomerNoteHistoryEndpoint( $db, $customer_updates_service ),
			new GetPreviousCustomerNotesEndpoint( $db, $customer_updates_service ),
			new PollCustomerThreadEndpoint( $db, $customer_updates_service ),
			new SaveEmailPreferenceEndpoint( $customer_updates_service ),
			new SaveStaffEmailPreferenceEndpoint( $db ),
			new UploadAttachmentEndpoint( $attachment_service, $db, new Validator() ),
			new DeleteAttachmentEndpoint( $attachment_service, $attachments_db ),
			new ServeAttachmentEndpoint( $attachment_service, $attachments_db ),
			new SubmitCustomerUpdateEndpoint( $db, $note_service, $customer_updates_service, $settings, $attachment_service, $async, new Validator() ),
			new SubmitRatingEndpoint( $db, $customer_updates_service, $settings, $async ),
			new SharedLinkEndpoint( $async ),
			new GetAnalyticsSummaryEndpoint( $analytics_lookup_db ),
			new GetAnalyticsByDateEndpoint( $analytics_lookup_db ),
			new GetAnalyticsAssigneesEndpoint( $analytics_lookup_db ),
			new GetAnalyticsProductsEndpoint( $analytics_lookup_db ),
		);

		$table->init();
		$attachments_table->init();
		$analytics_lookup_table->init();
		$analytics_lookup_db->init();
		$async_health->init();
		$team_roster->init();
		$rest->init();
		( new OrderDeletionController( $db, $attachment_service ) )->init();
		( new \OrderUpdatesForWoo\Admin\Orders\DeletedUpdatesMetaBox() )->init();
		( new AdminHeartbeatHandler( $db, $note_action_policy, $attachments_db ) )->init();
		( new NotificationScheduler( $async ) )->init();
		( new NotificationDispatcher() )->init();
		( new NotificationStatusUpdater( $db ) )->init();
		( new RatingRequestScheduler( $db, $settings, $async ) )->init();
		( new RatingFollowupScheduler( $settings, $async ) )->init();
		$onboarding = new OnboardingController();
		$onboarding->init();
		// Build the section controllers in display order. The first entry is
		// the default (empty section id) that loads when no `?section=` is set.
		// The orchestrator calls init() on each one so per-section hooks
		// (admin-init handlers, AJAX, etc.) auto-register.
		$section_controllers = array(
			new GeneralSettingsController( new GeneralSettingsService( $settings ) ),
			new \OrderUpdatesForWoo\Admin\Settings\Controllers\CustomersSettingsController(
				new \OrderUpdatesForWoo\Admin\Settings\Services\CustomersSettingsService()
			),
			new MembersSettingsController( new MembersSettingsService() ),
			new EmailsSettingsController( new EmailsSettingsService() ),
			new \OrderUpdatesForWoo\Admin\Settings\Controllers\AdminOnlySettingsController(
				new \OrderUpdatesForWoo\Admin\Settings\Services\AdminOnlySettingsService()
			),
			new CacheSettingsController( new CacheSettingsService( $team_roster, $analytics_lookup_db ) ),
			new AttachmentsSettingsController( new AttachmentsSettingsService() ),
			new ShortcodesSettingsController( new ShortcodesSettingsService() ),
			new ApiSettingsController( new ApiSettingsService() ),
		);

		( new OrderUpdatesSettingsController( $section_controllers, $team_roster ) )->init();
		( new \OrderUpdatesForWoo\Admin\Settings\Fields\AssigneeRotationField( $team_roster, new Validator( $team_roster ) ) )->init();
		( new \OrderUpdatesForWoo\Admin\Settings\Fields\StatusListField() )->init();
		$this->migrate_assignment_settings();
		( new NewsletterController() )->init();
		( new WelcomeController() )->init();
		( new OrderUpdatesPanelController( new OrderEditorPanelService( $team_roster ), $settings, $update_card_parser, $db, $onboarding ) )->init();
		( new OrderLockBanner() )->init();
		( new OrderTableUpdateFiltersController( $db, new OrderTableFiltersService() ) )->init();
		( new AdminNotifications( $db, $attachments_db ) )->init();
		( new \OrderUpdatesForWoo\Admin\Notices\ReviewRequestNotice( $db ) )->init();
		( new FrontendNotifications( $db, $attachments_db ) )->init();
		( new CustomerOrderUpdatesController( $customer_updates_service, $settings ) )->init();
		( new OrderUpdatesShortcode( $customer_updates_service, $settings ) )->init();
		( new CheckoutEmailInjector() )->init();
		( new AdminBarNotifications( $db ) )->init();
		( new AnalyticsController( $analytics_lookup_db ) )->init();

		add_action( 'order_updates_for_woo_after_delete_update', function ( int $update_id ) {
			StaffEmailPreference::delete_all_for_update( $update_id );
		} );

		// Analytics cache invalidation is handled by AnalyticsLookupDb,
		// which listens to `order_updates_for_woo_update_changed` / `_deleted`.
	}

	/**
	 * One-time migration from the legacy assignment-mode + primary-assignee
	 * settings to the new ordered rotation list. Runs once per install — the
	 * `_done` flag prevents re-running on every page load.
	 *
	 * Behaviour:
	 *   - If a primary-assignee user was configured and the rotation list is
	 *     empty, prepend that user to the list so existing setups keep
	 *     routing customer updates to the same person.
	 *   - Delete the legacy options regardless, so the DB is clean.
	 */
	private function migrate_assignment_settings(): void {
		if ( '1' === (string) get_option( Constants::ASSIGNMENT_MIGRATION_V1_FLAG_OPTION, '' ) ) {
			return;
		}

		$legacy_primary = absint( get_option( Constants::LEGACY_PRIMARY_ASSIGNEE_OPTION, 0 ) );
		$current_pool   = get_option( Constants::ASSIGNEE_PRIORITY_LIST_OPTION, array() );
		$current_pool   = is_array( $current_pool ) ? array_values( array_filter( array_map( 'absint', $current_pool ) ) ) : array();

		if ( $legacy_primary > 0 && empty( $current_pool ) ) {
			update_option( Constants::ASSIGNEE_PRIORITY_LIST_OPTION, array( $legacy_primary ) );
		}

		delete_option( Constants::LEGACY_PRIMARY_ASSIGNEE_OPTION );
		delete_option( Constants::LEGACY_ASSIGNMENT_MODE_OPTION );

		update_option( Constants::ASSIGNMENT_MIGRATION_V1_FLAG_OPTION, '1', false );
	}
}
