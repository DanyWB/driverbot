# Timeline, 29 October 2026

## Scope and source

Working copy: `C:/Users/user/Desktop/phangan/.tmp/release-20261018`, starting HEAD `2279ebb9810b15231b32d5facdef9b1044257195`. Pre-existing PROJECT_STATE.md preserved. No push, deploy, production reads/writes, bot execution or real notifications.

Read global/project AGENTS and `docs/IMPLEMENTATION_2026_10_29.md`. Read customer standalone Index.vue, UTF-16 patch and DOCX. The patch was not blindly applied: the reviewed standalone visual delta was ported with apply_patch and all seven supporting files adapted deliberately. Existing booking/selection/occupancy/visibility logic retained, including internal labels removed accidentally in customer standalone source.

Visual targets viewed: portrait `codex-clipboard-cd192008-02a0-49f0-ab30-426f925d52d3.png`, landscape `658fec42-914b-43c0-8650-64ad5c130242`, `5bc86fe1-f323-4ab8-baff-d1ffc9af98ce`, `3bc19657-80a2-4586-b36f-5fce141c952d` in user Temp. These show compact two-line and normal one-line modes, dark palette, exterior paint outline/glow and header toolbar.

## Implemented

- Customer compact toolbar, explicit narrow/two-line versus normal/one-line vehicle column, 50–150% continuous pinch and 5% buttons, customer weekend/row hover colors, Beige.
- Rajdhani Bold 700 bundled locally from google/fonts/ofl/rajdhani with SIL OFL license. No Google Fonts runtime import. Unused Saira Stencil One was not added.
- Exterior paint stroke uses existing two-layer glyph, retaining foreground fill for engines without paint-order support, plus customer glow. Vue still escapes all names.
- Name search and custom model select, responsive placement as customer source. Model extraction strips known manufacturers, engine sizes/ABS/CBS, retains meaningful multi-word models and hyphens; e.g. Honda HR-V becomes HR-V, not Honda. Case-insensitive options deduplicated.
- Reset clears both local fields. Local filters clear stale date selection. New server row sets clear unavailable model choice. Visible counts follow local filters and preserve server meaning: blocking ranges, not pending requests.
- Mouse drag pans the actual grid, excludes links/buttons/inputs, captures only after threshold and suppresses follow-on click. Existing native single-finger pan, scoped two-contact pinch, click guards, fullscreen/body lock and keyboard behavior retained.
- Low zoom has non-overlapping 32px info/header targets, 24px name/calendar/occupancy minimums; overlapping occupancy lanes keep at least 28px pitch. Only occupied rows grow for additional lanes. Ordinary rows remain dense, rather than 44px everywhere. Frozen column floor 184px fits toolbar targets. Pinch vertical anchor tracks actual vehicle row/fraction so target floors do not break its anchor.
- Empty fullscreen keeps an exit button, including when a range fetch produces no rows.
- Six new Timeline translation keys were added to useLocale, including explicit `Model: 'Модель'` for the accessible model filter name; existing text and pricing keys preserved.

## Verification in progress

Initial verification: 32 Timeline frontend tests passed; complete frontend suite passed all 45 tests (`node --experimental-strip-types --test --test-reporter=dot tests/Frontend/*.test.mjs`). Targeted eslint, Prettier check and `vue-tsc --noEmit` passed. Targeted `git diff --check` passed. A final locale regression adds one test (33 Timeline / 46 frontend); final rerun below. Full backend/build checks owned by root.

`php artisan test --compact --filter=AdminTimelineTest` could not start its subprocess because the configured OSPanel temp directory was not writable; this was an environment error before test assertions. Direct PHPunit then reached seven tests but lacked APP_KEY, since this checkout intentionally has no .env. A scoped synthetic test-only key was supplied for the next run without writing any .env or reading production configuration; result pending below.

Synthetic preview running at `http://127.0.0.1:5209/`, started with `node .tmp/timeline-20261029-preview/server.mjs` from this checkout. It mounts the actual Timeline component through real Inertia using a read-only synthetic fixture, actual CSS/fonts/components, no .env or database. All writes return 405, quotes are disabled, real navigation is blocked. Files live under ignored .tmp and do not enter production bundle. Root is performing browser visual/interaction QA.

Direct PHPunit run with the scoped synthetic key passed **7/7 AdminTimelineTest cases, 223 assertions**. No backend Timeline code changes were needed.

## Browser evidence and final corrections

Root checked actual-component synthetic preview through IAB at 390x844 portrait regular/fullscreen, compact 70%/50%, and 844x390 landscape 50%. Reported: no compact clipping; HR-V search changes vehicles 21 to 1, free 1, blocks 0; reset restores 21 / 18 / 5. The very small text at 50% is an intentional overview state.

Child IAB connection was unavailable, so the approved standing Playwright fallback used already installed Playwright and cached headless Chromium. No browser downloads or dependency installs. `node .tmp/timeline-20261029-preview/capture.mjs` passed with zero page errors and live Russian mobile aria-label `Модель`.

Saved fullscreen captures (all DPR 1; actual CSS viewport equals image pixels):

- `.tmp/timeline-20261029-preview/portrait-compact70.png`, 390x844.
- `.tmp/timeline-20261029-preview/portrait-normal100.png`, 390x844.
- `.tmp/timeline-20261029-preview/landscape-compact70.png`, 844x390.
- `.tmp/timeline-20261029-preview/landscape-normal100.png`, 844x390.
- `.tmp/timeline-20261029-preview/desktop-compact70.png`, 1280x800.
- `.tmp/timeline-20261029-preview/desktop-normal100.png`, 1280x800.

Source portrait/landscape and rendered captures were opened together for visual comparison. Source includes Safari chrome and unknown screenshot-to-CSS density; no pixel-exact claim. The 184px frozen-column and 80px header minimums at low zoom are intentional accessibility differences. Paint names, two-line/one-line modes, dark palette, local Rajdhani, weekend labels and dense grid follow the customer source. Synthetic row IDs/colors/content and occupancy fixtures differ from the customer fleet and are not design drift.

Capture iteration found inline source `minWidth` overriding part of the existing 32px toolbar floor. Corrected only `min-width: 32px !important`; final browser measurements confirm all ten toolbar targets are 32x32px at compact70 on all three viewports. A test protects this rule. No other layout changes followed root's visual acceptance.

Final rerun passed all **46 frontend tests** (33 Timeline subset), `vue-tsc --noEmit` and targeted `git diff --check`. Targeted eslint passed after the final correction. Final shared useLocale Prettier check reported formatting from the concurrent pricing insertion; root owns final shared-file formatting after both agents finish. Child touched only isolated Timeline keys.

## Limitations

Real iPhone/Safari pinch and typography are not verified by desktop/mobile emulation. Synthetic preview cannot verify server booking creation or real pricing integration; backend feature tests cover those separately. Visual source capture versus rendered comparison must be recorded by root before final acceptance. No claim of pixel-identical iOS rendering or completed release.
