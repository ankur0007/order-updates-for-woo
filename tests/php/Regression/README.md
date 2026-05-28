# Regression Tests

When a customer reports a bug, add a test here **before** fixing it (proves the bug is real), fix the bug, then confirm the test passes. The test stays in the suite forever — it is now a regression guard.

## Naming convention

```php
/**
 * Customer reported: <brief description of the report>.
 *
 * @group regression
 * @group customer-reported
 */
public function test_<what_broke>(): void { ... }
```

## Running regression tests only

```bash
composer test:regression
```

## Rules

- Never delete a test from this file — if behaviour changes, update the assertion, not remove the test.
- Every method must include a comment explaining the original customer report.
- Tag every method `@group regression` so it is selectable by `--group regression`.
