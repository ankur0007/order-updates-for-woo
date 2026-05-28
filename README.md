# Order Updates for WooCommerce

Minimal WordPress VIP-friendly plugin skeleton for customer-facing and internal WooCommerce order updates.

## Structure

- `order-updates-for-woo.php`: plugin bootstrap
- `src/Admin/Orders`: admin-side order update controllers, services, and views
- `src/Frontend/Orders`: customer-facing order update controllers, services, and views
- `src/Shared/Updates`: shared data and table logic
- `assets/Admin`: admin CSS and JS
- `composer.json`: autoloading and dev tooling

## Commands

```bash
composer install
composer lint
```
