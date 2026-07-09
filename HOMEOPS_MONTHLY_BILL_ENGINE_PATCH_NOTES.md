# HomeOps Monthly Bill Engine Patch

## Purpose
This patch turns Bills into a proper monthly operating system instead of a flat list.

The model is now explicit:

```text
bills = recurring bill schedules/templates
bill_instances = selected-month paid/unpaid/skip state
```

So Mortgage / HOA / Internet should be created once as bill schedules, then every selected month gets its own instance and status.

## Backend changes

### New support service

```text
homeops-api/app/Support/HomeOpsBillEngine.php
```

Adds a reusable engine that:

- looks at all active bills for the selected property
- checks the selected month from the V0 Time Lens
- creates missing `bill_instances` for that month
- calculates the due date from `due_day` / `next_due_date`
- respects common frequencies: monthly, once, quarterly, semiannual, annual, weekly, biweekly
- does not overwrite paid, cleared, or skipped months

### Read/dashboard integration

Updated:

```text
HomeOpsReadController.php
HomeOpsDashboardController.php
```

Now `/api/homeops/bills` and `/api/homeops/dashboard` call the bill engine before loading bill rows. That means simply visiting July/August/etc. creates/finds that month’s bill instances.

### New bill instance actions

Updated:

```text
HomeOpsWriteController.php
routes/api.php
```

New routes:

```text
PATCH /api/homeops/bill-instances/{instanceId}
PATCH /api/homeops/bills/{billId}/mark-unpaid
PATCH /api/homeops/bills/{billId}/skip-month
```

Existing route still works:

```text
PATCH /api/homeops/bills/{billId}/mark-paid
```

## Frontend changes

Updated:

```text
homeops-app/src/pages/BillsPage.jsx
homeops-app/src/components/BillsTable.jsx
homeops-app/src/lib/homeopsApi.js
homeops-app/src/styles/_v0-foundation.scss
```

Bills page now shows:

- Expected this month
- Paid
- Still due
- Open items

Each bill row now separates:

- Schedule editing: the recurring bill template
- This month editing: selected month instance amount/due date
- Mark paid
- Mark unpaid
- Skip this month

## Migration note

No new migration is required for this patch. It uses existing `bills` and `bill_instances` columns.

Prereqs remain:

```bash
php artisan migrate --path=database/migrations/2026_06_21_000000_create_homeops_v0_foundation.php
php artisan migrate --path=database/migrations/2026_07_09_010000_add_core_bill_source_fields.php
```

Budget Compass migration only matters if you are using Budget Lens:

```bash
php artisan migrate --path=database/migrations/2026_07_09_000000_create_budget_profiles_table.php
```

## Validation performed

```bash
php -l homeops-api/app/Support/HomeOpsBillEngine.php
php -l homeops-api/app/Http/Controllers/HomeOpsReadController.php
php -l homeops-api/app/Http/Controllers/HomeOpsDashboardController.php
php -l homeops-api/app/Http/Controllers/HomeOpsWriteController.php
php -l homeops-api/routes/api.php
php artisan route:list --path=homeops

cd homeops-app
npm run build
npm run lint -- --quiet
```

## Product result

The huge leap is that HomeOps can now answer:

```text
What bills exist as permanent schedules?
What is due in this selected month?
What did I pay this month only?
What did I skip this month only?
What remains open for this period?
```

This is the bill-state foundation needed before a monthly closeout/report screen.
