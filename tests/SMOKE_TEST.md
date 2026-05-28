# Pre-WP.org Smoke Test

Manual checklist for the 12 endpoints whose `handle()` bodies are not unit-tested. Run end-to-end before submission. Target time: ~30 minutes.

## Setup

- WP install with WooCommerce active
- Order Updates plugin active, settings reviewed
- Two staff users: **Admin** (`manage_woocommerce`) and **Subscriber** (no shop caps)
- One real order with billing email; capture the `order_key` (visible in DB or via `?key=...` URL on the customer view)
- Browser dev tools open, watch the Network tab

## Convention for "hostile-actor check"

For every endpoint: with the Subscriber logged in (or a guest with no `order_key`), hit the route and confirm a `403`/`401` and no DB write. The `AuthorizationMatrix` unit test already proves the trait is wired — this confirms it actually fires under WP REST.

---

## Endpoints

| # | Route | Hostile-actor check | Happy path | Expected response shape |
|---|---|---|---|---|
| 19 | `POST /updates` | Subscriber → 403; no row inserted | Admin creates an update with title + color | `{ cardHtml, updateId, isEdit:false, message, noteId }` (no `update` key) |
| 20a | `POST /customer-submit` (logged-in customer) | Other customer's order_id → 403 | Order owner submits a note | `{ noteId, ... }`, note appears in admin card within 30 s poll |
| 20b | `POST /customer-submit` (guest via `order_key`) | Wrong/missing key → 403 | 6 submits in 60 s | 6th returns `429`, transient `awts_guest_rate_{order_id}` set |
| 21 | `POST /updates/{id}/rating` | Stars=0 or 6 → 400; non-resolved update → 409 | Customer rates a resolved update 5★ + comment | `{ message, updateId, stars, comment }` |
| 21b | duplicate rating | Same customer rates again → 409 (`order_updates_for_woo_rating_exists`) | — | — |
| 22a | `POST /attachments/upload` (PDF) | Subscriber → 403 | Admin uploads `invoice.pdf` to a note | `{ id, name, mime, size, url, is_image:false }` |
| 22b | `POST /attachments/upload` (image) | — | Admin uploads `photo.png` | Same shape, `is_image:true` |
| 22c | `POST /attachments/upload` (.php) | Server rejects with 415 (`order_updates_for_woo_attachment_unsupported_type`) | — | — |
| 22d | `POST /attachments/upload` (oversize) | File above `wp_max_upload_size()` → 413 | — | — |
| 23 | `GET /attachments/{id}/serve` | Subscriber → 403; expired/invalid signed URL → 403 | Admin opens admin URL with valid nonce; customer opens signed URL within TTL | File bytes, correct `Content-Type`, no directory listing exposed |
| 24 | `DELETE /attachments/{id}` | Other user's attachment → 403; non-existent ID → 404 | Owner deletes their attachment | `{ deleted:true }`, file removed from disk, DB row gone |
| 25a | `POST /updates/{id}/solve` | Already-solved update → 409 (`order_updates_for_woo_already_solved`) | Admin solves an open update | `{ updateId, isResolved:true, ... }`, `is_resolved=1` in DB |
| 25b | `POST /updates/{id}/reopen` | Rated update → 409; already-open → 409 | Admin reopens a solved-but-unrated update | `{ updateId, isResolved:false, ... }` |
| 26a | `GET /analytics/summary?from=&to=` | Subscriber → 403; bad date format → 400; from > to → 400 | Admin fetches a 30-day range with seeded data | `{ total, solved, pending, avg_rating, ... }`, totals match a hand-counted set |
| 26b | `GET /analytics/by-date` | Same | Same range | Array of `{ date, total, solved }` rows |
| 26c | `GET /analytics/assignees` | Same | Same range | Array of `{ user_id, name, total, solved }` rows |
| 26d | `GET /analytics/products` | Same | Same range | Array of `{ product_id, name, total }` rows; product names link to edit screen |

## Block 6 deferrals (security spot-checks)

| # | Check | How |
|---|---|---|
| 29 | Double-extension upload `evil.php.png` | Try uploading; expect 415 (mime check rejects). Inspect stored filename — must be UUID-hashed, not original. |
| 30 | Output escaping | View an update with `<script>alert(1)</script>` in title and note body on admin and customer sides. No alert fires; tags rendered as text. |
| 31 | Guest rate limit | (Same as 20b above.) Confirm transient is per-`order_id`: hitting a different order's submit endpoint should NOT count toward the first order's bucket. |
| 32 | Redirect safety | On settings save, append `&redirect_to=https://evil.test`. WordPress should bounce back to the local settings page, not the external host. |

## Pass criteria

- Every row above marked ✓ in your run notes
- No PHP errors in `debug.log` during the run
- No browser console errors during the customer flow

If anything fails: file a v1.0 blocker, fix, re-run the failing row only.
