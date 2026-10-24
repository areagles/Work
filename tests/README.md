# Tests

This directory contains the PHPUnit bootstrap and the first testing structure.

## Goal

Create a safe automated testing foundation without touching the live runtime
database.

## Setup

1. Install development dependencies:

```bash
composer install
```

2. Create a dedicated test environment file:

```bash
cp .app_env.testing.example .app_env.testing
```

3. Fill the testing database credentials with an isolated database.

4. Run:

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

or:

```bash
composer test
```

For the baseline CI path, run:

```bash
composer ci
```

## Important Rule

Never point test credentials to the live production database.

## Current Covered Scenarios

- bootstrap / environment sanity
- isolated testing database connection
- payroll settlement with internal allocation and employee advance remainder
- client receipt allocation with full voucher preservation and FIFO invoice settlement
- supplier payment allocation with full voucher preservation and FIFO purchase settlement
- tax calculation engine for standard vs tax invoice, additive taxes, subtractive taxes, and inactive tax handling
- inventory audit reconciliation with stock variance application and adjustment transaction creation
- license lifecycle rules for trial, subscription grace, subscription expiry, and lifetime suspension
- quote and invoice tax persistence for shared source data, tax kind, law key, tax lines, and totals
- subscription registry save, auto-renew-on-reactivation, and trial-to-subscription extension rules
- approved quote conversion into one linked operational invoice without duplicate regeneration
- saas subscription payment reconciliation, payment log posting, and reversal on invoice reopen

## Next Suggested Scenarios

- saas tenant subscription cycle and renew date recalculation
- public approval flow end-to-end with redirect messaging
