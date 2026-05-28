# Test Suite — Status & Handoff

## Stack

| Layer | Tool |
|---|---|
| PHP unit | PHPUnit 10 + Brain\Monkey (no real WP/DB needed) |
| PHP integration | PHPUnit 10 + WP_UnitTestCase (needs real WP test DB — not yet wired) |
| JS unit | Jest 29 + jsdom + @wordpress/jest-preset-default |

## Run commands

```bash
composer install && npm install   # first time only

composer test                     # all PHP
composer test:unit                # unit only (fast, no DB)
composer test:int                 # integration only
composer test:regression          # @group regression only
npm test                          # all JS
```

## Directory layout

```
tests/
  php/
    Unit/
      Helpers/          ← AdminBarNotificationStoreTest, AnalyticsCacheTest, etc.
      Validation/       ← ValidatorTest ✅, ValidatesAnalyticsRequestTest
      Analytics/        ← AnalyticsSummaryTest, AnalyticsByDateTest, etc.
    Integration/
      Endpoints/        ← MarkSolvedEndpointTest, GetAnalyticsSummaryEndpointTest, etc.
      DB/               ← OrderUpdatesDbTest, AdminBarNotificationStoreIntegrationTest
      Notifications/    ← NotificationSchedulerTest, RatingRequestSchedulerTest
    Regression/
      README.md         ← instructions for adding regression tests
      CustomerEdgeCasesTest.php ← (not yet written)
  js/
    analytics.test.js   ✅  (computeRange, fmt, esc — 9 tests)
    admin-bar.test.js   ← (not yet written)
  bootstrap.php         ← autoloader + WP_Error stub (unit tests only)
STATUS.md               ← this file
```

## What was changed in production code

`assets/Admin/js/analytics.js`:
- `esc()` refactored from `$('<span>').text().html()` to `document.createElement('span')` — no jQuery dependency
- Export hook added at top of IIFE so Jest can import `{ computeRange, fmt, esc }` without running jQuery-dependent code
- IIFE call changed from `jQuery` to `window.jQuery` — safe in browser, no ReferenceError in Jest

## Phase 1 — Complete ✅

- `composer.json`, `phpunit.xml`, `tests/bootstrap.php`, `package.json`, `jest.config.js`
- `tests/php/Unit/Validation/ValidatorTest.php` — 3 tests for `Validator::sanitize_note`
- `tests/js/analytics.test.js` — 9 tests for `computeRange`, `fmt`, `esc`
- `tests/php/Regression/README.md`
- Full directory scaffold

## Phase 2 — Pre-launch unit coverage (Roadmap v2)

Source of truth: `memory/project_testing_roadmap_v2.md`. Run order is strict — Block 1 → 6, full suite green after every file. Roadmap v2 supersedes the earlier 5-file Phase 2 list (those items are now folded into Blocks 1, 3, and 5).

### Block 1 — DB & cache

1. `tests/php/Unit/Shared/Updates/OrderUpdatesDbTest.php` ✅ — write paths: update lifecycle, customer-note lifecycle, queued/notified, history events (`mark_as_solved/unsolved`, `create_assignee`, `sync_assignee`). 36 tests / 39 assertions.
2. `tests/php/Unit/Shared/Updates/OrderUpdatesDbCacheTest.php` ✅ — narrow vs full bust, per-note key, customer-notes version increment, mark-as-solved fan-out. 11 tests / 28 assertions.
3. `tests/php/Unit/Helpers/AnalyticsCacheTest.php` ✅ — bust_summary / bust_assignee / bust_products / bust_all option + cache writes, autoload=false, increment-from-existing, on_update_solved with/without assignee. 8 tests / 20 assertions.
4. `tests/php/Unit/Helpers/AdminBarNotificationStoreTest.php` ✅ — add/get/dismiss/dismiss_for_update, cap=50 keeps tail, get_active cache hit, add_staff_reply (whitespace name rejected). 12 tests / 30 assertions.

### Block 2 — Notes & updates

5. `tests/php/Unit/Shared/Updates/UpdateNoteServiceTest.php` ✅ — author resolution chain, guest billing-name fallbacks, mention email queue, self-mention skip. 9 tests / 14 assertions.
6. `tests/php/Unit/Shared/Updates/NoteActionPolicyTest.php` ✅ — edit-window clamping, internal/customer note edit gates, guest vs logged-in author paths, queued/notified lock. 14 tests / 14 assertions.
7. `tests/php/Unit/Helpers/UpdateStateTest.php` ✅ — predicate matrix, can_edit with explicit and current-user IDs. 6 tests / 14 assertions.
8. `tests/php/Unit/Helpers/UpdateResolverTest.php` ✅ — static cache, normalize() polymorphism. 6 tests / 6 assertions.
9. `tests/php/Unit/Helpers/NoteAuthorTest.php` ✅ — customer detection across cap permutations. 5 tests / 5 assertions.
10. `tests/php/Unit/Helpers/RoundRobinAssigneeTest.php` ✅ — empty pool, single member, full cycle + wrap, pointer increment, missing user, pool-shrunk-mid-rotation. 6 tests / 11 assertions.

### Block 3 — Attachments

11. `tests/php/Unit/Shared/Attachments/AttachmentStorageTest.php` ✅ — boundary checks, traversal rejection, path helper composition. 7 tests / 9 assertions.
12. `tests/php/Unit/Shared/Attachments/AttachmentsTableTest.php` ✅ — migration runs on version mismatch OR missing table. 4 tests / 4 assertions.
13. `tests/php/Unit/Helpers/AttachmentPresenterTest.php` ✅ — canonical key set, value pass-through, is_image flag, format_many. 7 tests / 14 assertions.
- Bonus: `tests/php/Unit/Shared/Attachments/AttachmentServiceTest.php` ✅ — mime allowlist contract + executable mime exclusion. 2 tests / 7 assertions.

### Block 4 — Notifications

14. `tests/php/Unit/Shared/Notifications/NotificationSchedulerTest.php` ✅ — admin context, creator/admin email dedup, assignee change → reassigned + unassigned, mute respect. 6 tests / 11 assertions.
15. `tests/php/Unit/Shared/Notifications/EmailClassesTest.php` ✅ — six email classes consolidated into one file (per-class assertion is identical: `$id` wired to `Constants::EMAIL_ID_*`). 6 tests / 6 assertions.
16. `tests/php/Unit/Admin/AdminBar/AdminBarNotificationsTest.php` ✅ — on_assigned, on_mention, on_customer_submit (staff short-circuit, dedup), on_staff_reply (sender skip, name in title). 7 tests / 11 assertions.

### Block 5 — Endpoints

17. `tests/php/Unit/Shared/Validation/ValidatorExtendedTest.php` ✅ — attachment-payload validation matrix, sanitize_note over-length, mention-list filter. 8 tests / 11 assertions.
18. `tests/php/Unit/API/ValidatesAnalyticsRequestTest.php` ✅ — anonymous-class trait host, nonce/cap/date-range across 9 cases. 9 tests / 13 assertions.
19–26. **Deferred to integration tier** — per-endpoint `handle()` tests require full WP REST + request/response wiring. Integration tier with WP_UnitTestCase will test those properly. Security-critical surface (nonce on every endpoint) covered structurally in file 27.

### Block 6 — Security

27. `tests/php/Unit/API/AuthorizationMatrixTest.php` ✅ — data-provider walks every endpoint class, asserts each uses VerifiesAccess (or ValidatesAnalyticsRequest, which bundles it). Trait-of-trait recursion. 27 dataset / 27 assertions.
28. `tests/php/Unit/Security/SqlPreparationTest.php` ✅ — regex scan of every `$wpdb->{query|get_*}` call site in `src/`. Each must use `prepare()`, a string literal, or carry `phpcs:ignore`. 1 test / 1 assertion.
29. **Partly covered** by `AttachmentServiceTest::test_executable_mime_types_are_not_in_allowlist` (exec mime allowlist pin). Double-ext / null-byte / randomized stored name are integration-level.
30. **Partly covered** by `AttachmentPresenterTest` (presenter returns raw values, no HTML pass-through verified via the canonical-key shape test).
31. **Deferred to integration tier** — guest rate-limit logic depends on WP transients + REST request flow.
32. **Deferred to integration tier** — `wp_safe_redirect` interaction with WP request handling.

**Suite total: 202 tests, 346 assertions, all green.** Run `composer test:unit`.

## Pre-launch coverage gaps

12 roadmap items deferred from the unit tier. Each has compensating coverage from another file in this suite or from the manual smoke pass before submission. Full WP-REST integration tests for these are tracked as a v1.1 backlog item in `memory/project_v1_pending_tasks.md`.

| # | Deferred file | Why deferred | Compensating coverage |
|---|---|---|---|
| 19 | `SaveUpdateEndpointTest` | `handle()` body is orchestration over WP REST request/response wiring; mocked-`$wpdb` unit test would only restate the production code | AuthorizationMatrix (nonce wiring) · `tests/SMOKE_TEST.md` (response shape: `{cardHtml, updateId, isEdit, message, noteId}`) |
| 20 | `SubmitCustomerUpdateEndpointTest` | Same — plus guest auth via order_key and rate-limit transients are WP-state-dependent | AuthorizationMatrix · SMOKE_TEST (guest happy path + rate-limit 429) |
| 21 | `SubmitRatingEndpointTest` | Same — idempotency check reads/writes through `OrderUpdatesDb::submit_rating` already covered in Block 1 | AuthorizationMatrix · SMOKE_TEST (1–5 stars range, duplicate-submit 409) |
| 22 | `UploadAttachmentEndpointTest` | Multipart upload flow needs real `$_FILES` + `is_uploaded_file()`; not mockable as unit | AttachmentServiceTest (mime allowlist pin) · SqlPreparation · SMOKE_TEST (PDF/PNG happy path, .php rejected, oversize 413) |
| 23 | `ServeAttachmentEndpointTest` | Streams file bytes through PHP — file-system + header round-trip belongs in integration | AuthorizationMatrix · AttachmentStorageTest (path boundary) · SMOKE_TEST (signed URL works for customer, capability gate for admin) |
| 24 | `DeleteAttachmentEndpointTest` | Combines DB delete + filesystem delete + permission check — better verified end-to-end | AuthorizationMatrix · AttachmentStorageTest (delete_file boundary) · SMOKE_TEST (own attachment deletes, others' attachment 403) |
| 25a | `MarkSolvedEndpointTest` | Underlying `mark_as_solved` already covered in Block 1 file 1; endpoint wraps it with state guard | AuthorizationMatrix · OrderUpdatesDbTest (mark_as_solved contract) · SMOKE_TEST (already-solved → 409) |
| 25b | `ReopenUpdateEndpointTest` | Same pattern as Mark Solved | AuthorizationMatrix · OrderUpdatesDbTest (mark_as_unsolved contract) · SMOKE_TEST (rated update can't reopen → 409) |
| 26a | `GetAnalyticsSummaryEndpointTest` | Data-shape asserts over mocked `$wpdb` would just restate the SQL | AuthorizationMatrix · ValidatesAnalyticsRequest · SMOKE_TEST (date range, totals match a known seeded order set) |
| 26b | `GetAnalyticsByDateEndpointTest` | Same | Same |
| 26c | `GetAnalyticsAssigneesEndpointTest` | Same | Same |
| 26d | `GetAnalyticsProductsEndpointTest` | Same — plus joins WC order-items table which is real-DB territory | Same |

Block 6 deferrals (security):

| # | Deferred file | Why deferred | Compensating coverage |
|---|---|---|---|
| 29 | `UploadAttachmentSecurityTest` | Double-ext / null-byte / randomized-name behaviours sit in `AttachmentService::store_upload` and need real `is_uploaded_file()` + filesystem | AttachmentServiceTest (allowlist + executable-mime exclusion) · SMOKE_TEST (upload `.php.png`, expect 415) |
| 30 | `PresenterEscapingContractTest` | Other presenters (`CustomerNotePresenter`, `UpdatePresentationHelper`) aren't pure data shapers — they pull from helpers with WP-state dependencies | AttachmentPresenterTest (raw-value contract on the canonical presenter) · manual review of view files for `esc_*` at render |
| 31 | `RateLimitingTest` | Guest rate limit uses `set_transient`/`get_transient` with TTL — race-condition territory, not a unit | SMOKE_TEST (6 guest submits in 60s, expect 429 on the 6th) |
| 32 | `RedirectSafetyTest` | `wp_safe_redirect` behaviour depends on the WP host header allowlist | SMOKE_TEST (settings save with `?redirect=https://evil.test`, expect bounced to local) |

Run `tests/SMOKE_TEST.md` end-to-end before WP.org submission.

## Post-launch (can ship without)

- JS suite: `admin-bar.test.js`, `customer-page.test.js`
- Helpers polish: `DateHelperTest`, `CustomerEmailPreferenceTest`, `StaffEmailPreferenceTest`
- Integration tier: real WP test DB, endpoint round-trips, schema migration, Action Scheduler end-to-end
- Regression: one test per shipped bug fix in `change_log.md`

## Key rules for this test suite

1. Test namespace mirrors source: `OrderUpdatesForWoo\Tests\Unit\Helpers\...`
2. Class names end in `Test`, methods start with `test_`
3. One behaviour per test method — no multi-assertion tests that hide failures
4. Unit tests must not touch the DB — mock WP functions with Brain\Monkey
5. Integration tests extend `WP_UnitTestCase`, use setUp/tearDown to clean state
6. Regression tests always include a comment explaining the original customer report
7. Never delete a test — update the assertion if behaviour changes
8. No test depends on another test's state
