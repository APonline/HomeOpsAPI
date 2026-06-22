# HomeOps V0 Foundation Patch

This patch adds a first-pass V0 backend skeleton for Home Identity + Time Lens.

## Backend added
- `homes`, `rooms`, `home_assets`, `ownership_events`, and `home_photos` migration.
- Nullable V0 linking columns such as `home_id`, `room_id`, and `asset_id` on core tables when those tables exist.
- `HomeOpsHomeController` with home profile, rooms, assets and timeline endpoints.
- `HomeOpsV0` support helper for selected home resolution, period resolution, safe home filters and insert payload helpers.
- Existing dashboard/read/write controllers now respect `home_id` and time context where columns exist.
- Routes added under `/api/homeops/homes`.

## Validation
- PHP syntax check passed across app, routes, migrations and seeders.
- `php artisan route:list --path=api/homeops` resolves the new route set.

## Notes
- I could not run migrations in this container because PHP database drivers are not available here. The migration file itself passes PHP syntax checks.
- The migration guards with `Schema::hasTable` / `Schema::hasColumn` so it can run against the current evolving MVP schema without trying to rewrite everything.
