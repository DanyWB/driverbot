# Client corrections 19 September 2026

## Confirmed rules

- Laravel calculates paid rental days as `max(1, return date - pickup date)`;
  time of day is not used. 20 August to 27 August is seven paid days.
- Same-date rentals retain the existing one-paid-day floor. Raising the minimum
  above one prevents them. This floor is an implementation assumption.
- The return date is excluded from seasonal price allocation for multi-day rentals.
- Availability still blocks both selected dates. Vehicle handover on the return
  date is not implicitly enabled by this pricing change.
- Catalog price tiers, rounding to 100 THB, manual overrides and statuses remain unchanged.
- Categories page offers a general minimum and optional category overrides.
  An empty category value inherits the general minimum; an explicit value replaces it.
  These settings apply to new quotes and explicit booking recalculations, not stored snapshots.
  Seasonal changes are manual: the administrator changes the minimum before high season.

## Implemented and deployed

- Original vehicle photos replace cropped thumbnails. All provided photos are
  sent in albums of at most ten; a single photo is also supported.
- Full descriptions are sent as escaped text, split safely when long, followed
  by the price summary and booking controls. Gallery messages are tracked as UI
  messages and cleaned up on navigation, not as transactional notifications.
- Invalid rental dates now lead to a localized recovery screen with draft/reset
  actions, instead of an unexplained save error. Minimum-duration rejection has
  a localized message and a return to date selection.
- Backend policy validation is shared by admin and bot pricing/booking operations.

## Server cleanup performed

On 19 September 2026 server logs confirmed `VALIDATION_FAILED` with a rental
start date in the past during confirmation. No delivery-address error occurred
in the inspected failures.

Removed, as expressly requested, nine old bookings (all terminal), twelve price
snapshots, twenty-two status-history rows, thirty-five booking audit rows and
thirty-two booking notification records. Reset rental fields in four Redis bot
sessions, preserving profile fields. No booking-linked documents existed.
No backup was created. Catalog, photos, customers and administrators were kept.
All four runtime services restarted and `/health/ready` passed afterward.

Code changes were deployed separately after cleanup. No repeat data reset was performed.

## Manual descriptions in three languages

- The vehicle editor has English, Russian and Ukrainian description fields with
  a fallback explanation. Existing description content remains in the English field.
- Bot API returns the three variants; the bot selects the customer language,
  falling back to English when a translation is empty. Ukrainian uk/ua is supported.
- Existing clients omitting the new fields do not erase saved translations;
  explicitly clearing a field is supported. Each field accepts up to 10,000 characters.
- No automatic translation, external provider, credentials or recurring charges.

## Verification

- Baseline: 85 bot tests and 37 PHP unit tests passed. Initial feature-suite file
  upload failures were due to the Windows temporary directory and disappeared
  when PHP was given the workspace testing directory.
- Updated bot suite: 93 tests passed, including language selection/fallback, albums of 1/3/4 images, full text,
  message cleanup and localized invalid-date/minimum-duration recovery.
- Updated PHPUnit suite: 260 tests, 250 passed, 10 environment-dependent skips,
  1,702 assertions. Translation persistence, clearing, API/admin presentation and
  validation are covered. Frontend tests: 19 passed.
- TypeScript check, PHPStan and production frontend build passed.
- Redis concurrency/session test passed on temporary loopback port 16379 with
  persistence disabled; it does not use production sessions.
- Unified precheck stopped on local Redis not running at port 6379; local Laravel API is also not running and
  historical SQL dump paths remain in Git. Current-tree secret checks and
  production dependency audits passed. Historical rewriting was not performed.

## Deployment 19 September 2026

- Active release: `/srv/drive-phangan/releases/20260919T113532Z`.
- Base revision: `83bd16a61a9cdb1608a93ba12954239e65a16152` plus the reviewed local
  source changes and production frontend build. No Git push or commit was made.
  The release manifest explicitly records SOURCE_DIRTY=true and patch SHA-256
  `c9b4cac6b401e7b570e79b18fc2a70b612d028b91110447089ea8e7e94cd0aeb`.
- Baseline source/lockfile hashes and all uploaded file hashes verified.
  Dependencies reused unchanged; Composer classmap regenerated.
- Both additive migrations applied: rental policies and description translations.
- Atomic release switch completed. Previous code release retained for rollback;
  no database backup created, no business data reset and no notifications sent.
- Server readiness, login and unauthorized API access checks passed. Database,
  Redis, scheduler heartbeat and actual default-queue probe passed. All four runtime
  services are active with zero restarts. Online bot check confirmed Laravel,
  Redis and Telegram @DrivePhangan_BookingsBot.
- Data after release: 33 vehicles, 6 photos, 5 customers, 2 administrators, 0 bookings.
- Authenticated live Bot API check returned 30 bookable vehicles and description
  keys en/ru/ua. A quote for 20–27 October 2026 returned seven paid days and
  1,200 THB for the selected vehicle, without creating a booking.
- This is the requested client-testing deployment, not a claim of passing the
  strict production launch gate. Historical Git dumps remain a separate security task.

## Manual acceptance after release

### Photo optimization follow-up

- Diagnosed Telegram `WEBPAGE_CURL_FAILED` on the fourth Scoopy image. Its original
  is 5,828,654 bytes, above Telegram's URL-photo limit. All four originals are
  3024 x 4032 pixels; they are available on the server.
- Laravel now prepares dedicated `telegram-v1/*.jpg` derivatives on upload.
  JPEG quality is selected from 88 down to 40 until the result is at most
  4,000,000 bytes. No resizing or cropping; EXIF orientation is applied before
  metadata removal, and transparency is flattened to white.
- Originals remain untouched. Existing images can be prepared with
  `php artisan vehicles:optimize-telegram-photos`, which is safe to repeat.
  Bot API photo `url` now points to the derivative; `original_url` retains the
  original reference. Admin original/thumbnail presentation remains unchanged.
- Photos incompatible with Telegram's dimensions/aspect ratio, or still too
  large at the quality floor, are rejected with translated validation messages
  rather than silently downsized. Absolute delivery cannot be guaranteed during
  external Telegram/network outages.
- On an explicit Telegram 400 album rejection the bot tries individual photos;
  it skips only rejected images. Uncertain network failures are not retried this
  way, to avoid duplicate messages. Gallery cleanup still tracks delivered IDs.
- Deployed photo follow-up release `20260919T123658Z`, parent `20260919T113532Z`;
  patch SHA-256 `a83f98ca942219a8d839322afc335c32d261da31145e9d7be0b2df0d28356887`.
  All six existing images prepared successfully; original SHA-256 values verified
  unchanged. No migrations, data reset, backups or Git push were performed.
- Scoopy copies remain 3024 x 4032 pixels. Sizes: 2,471,656; 2,875,734;
  3,343,957; 3,716,221 bytes. The previously failing fourth image decreased from
  5,828,654 to 3,716,221 bytes without changing its resolution.
- Verification: backend CI passed (264 PHPUnit tests: 254 pass, 10 environment
  skips, 1,720 assertions; PHPStan/Pint/ESLint/Prettier/TypeScript and 19 frontend
  tests passed). Bot tests: 96 pass; syntax and translation checks pass.
  Live readiness, default-queue probe and online Laravel/Redis/Telegram smoke pass.
  No unsolicited test messages were sent to customer chats; visual Telegram
  acceptance remains to reopen the vehicle card in the bot.

### Acceptance steps

1. In Vehicles edit one vehicle and save English/Russian/Ukrainian descriptions.
   Switch the bot language and reopen its card; clear one translation and check
   English fallback. Existing English descriptions should be unchanged.
2. In Categories set general minimum to 3; set one category to 5, leave another
   empty. Confirm 2-day requests fail, 3-day requests work for the inherited
   category and the overridden category requires 5 days.
3. Verify 20 to 27 of a future month shows seven days in the bot, quote and admin.
4. Open a vehicle with three photos and a long description; verify originals,
   complete text and booking buttons. Add a fourth photo and repeat.
5. Test a delivery booking and admin notification with an explicitly designated
   test customer. Reset and stale-date recovery must not delete confirmed bookings.
