# Website CMS B/C/D implementation contract

Local implementation approved by root after foundation A. No production CMS or preview deployment is implied. Backend owns PHP/routes/config/migrations/tests and this contract. Frontend owns Vue/Blade/CSS/JS/UI translations. Existing public design and individual physical `vehicle_id` inventory remain unchanged.

## Boundaries

- `website.cms.admin_enabled=false` controls authoring routes. Existing `website.cms.enabled=false` controls published presentation. Flags are independent. Feature-off does not query CMS tables.
- Admin URI `/website`, route names `admin.website.*`; existing customer routes retain `website.*`. Read/write authoring requires session auth, active verified full_admin, with service authorization as well as HTTP checks. No new roles/RBAC.
- Customer preview remains exact-host, site-only, no active admins/outbound, noindex/no-store. Do not expose admin/auth routes on that instance. A local admin session uses the ordinary admin application, not the customer preview instance.
- Prices/occupancy/bookings stay in existing domain services. CMS never stores prices, availability, customers, identity/provider secrets, arbitrary HTML/CSS/JS, canonical origins or indexing/real-request enable switches.
- Real contacts are unknown and remain null. Existing approved static branding images are the only page-builder image choices. Vehicle website media remains explicitly owned by that physical vehicle; orphan media is never global page media.

## Authoring transport

GET pages use Inertia. Successful form mutations redirect303 to their editor with a toast. Validation uses existing error bags; revision conflicts use `errors.revision` and preserve unsaved input, never silently overwrite. Optional JSON callers get422 validation responses. No HTTP409 Inertia external-location convention is introduced.

Web validation always redirects to the owning card/page/settings editor, not Referer/session previous URL (private image GETs or another tab can change that URL). Normal Laravel old input/errors remain intact. Clients omit blank en/ru/uk locale entries before submission because Laravel converts empty strings to null; scalar/null localized text is not accepted as approved copy.

The same narrow protection applies to existing `website.booking.store`: HTML validation/domain/pricing failures redirect to `website.booking(locale,vehicle)` without query/contact data, preserving normal session old input. JSON callers still receive422. Quote/availability GETs must not become the error destination. BookingService rules and owner-session status access are unchanged.

| Method and URI | Name suffix | Result |
|---|---|---|
| GET `/website/cards` | cards.index | `website-admin/cards/Index` |
| GET `/website/cards/{vehicle}` | cards.edit | `website-admin/cards/Edit` |
| POST `/website/cards/{vehicle}/draft` | cards.draft | Save draft |
| POST `/website/cards/{vehicle}/publish` | cards.publish | Publish expected revision |
| POST `/website/cards/{vehicle}/unpublish` | cards.unpublish | Unpublish expected revision |
| GET `/website/cards/{vehicle}/preview/{locale}` | cards.preview | Authenticated Blade `website.cms-preview` |
| POST `/website/cards/{vehicle}/media` | media.store | Multipart upload; no autoassign/publish |
| DELETE `/website/media/{asset}` | media.destroy | Refuse referenced files |
| GET `/website/media/{asset}/{variant}` | media.preview | Private derivative only |
| GET `/website/pages` | pages.index | `website-admin/pages/Index` |
| GET `/website/pages/{key}` | pages.edit | `website-admin/pages/Edit` |
| POST `/website/pages/{key}/draft` | pages.draft | Save expected revision |
| POST `/website/pages/{key}/publish` | pages.publish | Publish |
| POST `/website/pages/{key}/unpublish` | pages.unpublish | Unpublish |
| POST `/website/pages/{key}/restore/{revision}` | pages.restore | Copy historic publication to draft only |
| GET `/website/pages/{key}/preview/{locale}` | pages.preview | Authenticated Blade `website.cms-preview` |
| GET `/website/settings` | settings.edit | `website-admin/settings/Edit` |
| POST `/website/settings/draft` | settings.draft | Save expected revision |
| POST `/website/settings/publish` | settings.publish | Publish |
| POST `/website/settings/unpublish` | settings.unpublish | Unpublish |

`key` is one of `home,cars,scooters,contact,terms,privacy`; locale is en/ru/uk; variant is card/card_2x/detail. Original downloads are not part of this slice. Private previews always no-store/noindex/nosniff and never expose private filesystem paths. Card/page previews suppress booking submissions.

## Card props and requests

Index props: `filters:{search,type,status}`, `vehicles` paginator containing `{id,name,inventory_code,type,year,fleet_eligible,profile_status,revision,published_revision,cover_preview_url,edit_url}`, plus `locales`, `limits`.

Edit props:

```text
vehicle: {id,name,inventory_code,type,year,fleet_eligible,fleet_url}
profile: {revision,published_revision,published_at,has_unpublished_changes,
          draft:{schema_version,title,summary,meta_title,meta_description,image_alt,cover_asset_id},
          published:null|same_content_shape}
media: [{id,kind,width,height,alt_text,referenced_by_draft,referenced_by_published,
         variants:{card:{url,width,height},card_2x:{url,width,height},detail:{url,width,height}}}]
locales: [en,ru,uk]
limits: {max_bytes,max_pixels,max_dimension,content:<per-field limits>}
capabilities: {edit,publish}
actions: {draft,publish,unpublish,upload,preview:{en,ru,uk}}
```

`title/summary/meta_title/meta_description/image_alt` are locale maps, exactly the foundation schema. Limits160/4000/160/500/255 respectively. Draft request `{expected_revision,content,cover_asset_id:null|uuid}`; publish/unpublish `{expected_revision}`. Media request multipart `{media,kind:photo|artwork,alt_text:<locale map>}`. Success flashes `uploaded_asset_id`, and reloads media props; frontend must explicitly select/save the cover. Delete carries a bounded `vehicle_id` for return navigation, never as authorization.

## Page document and props

Document: `{schema_version:1,title:<locale map>,meta_title:<locale map>,meta_description:<locale map>,blocks:[...]}`. Max30 blocks. Each has stable UUID `id`, enum `type`, typed `props`. Unknown keys/types rejected; all strings are bounded plain text.

| Type | Allowed props |
|---|---|
| hero | heading, body, image_ref, action(cars/scooters), action_label |
| catalog | heading, vehicle_type(car/scooter/all), limit(1..12) |
| text_image | heading, body, image_ref(nullable approved static ID) |
| steps | heading, items(max8): `{id,title,body}` |
| faq | heading, items(max20): `{id,question,answer}` |
| contact_cta | heading, body, channel(telegram/whatsapp/email), action_label |

Text fields are locale maps. Heading/title/question/action_label max160; body/answer max4000. `image_ref` is an ID from `static_assets`, not a URL/path. Empty drafts allowed. Publication needs at least one title locale and complete required block text for each published locale. Missing translations are not machine-filled. A contact action without a configured published contact produces no fake button. Catalog blocks use existing server vehicle data/shared pricing.

Page index props `{pages:[{key,revision,published_revision,published_at,status,edit_url}],locales}`. Editor props `{page:{key,revision,published_revision,published_at,has_unpublished_changes,draft,published},history:[{revision,published_at,restore_url}],block_types,static_assets,locales,limits,capabilities,actions}`. `static_assets` entries `{id,url,width,height,alt}`. Draft `{expected_revision,content}`; publish/unpublish/restore `{expected_revision}`. Restoration never publishes automatically. Policy publication requires a separate deployment-owned `cms.policy_publishing_enabled` flag, off by default; editing a draft never constitutes legal approval or enables real booking acceptance.

A new editor starts with an empty in-memory draft, without GET writes or automatic conversion of existing design content. Publishing requires at least one typed block, not only SEO/title fields. UI must clearly warn that publication replaces page content and disable an empty-body publish. Existing public content remains the fallback until an actual validated publication exists.

## Settings

Props `{settings:{revision,published_revision,published_at,draft,published},locales,navigation_options,capabilities,actions}`. Content whitelist:

```text
schema_version: 1
business: {name,legal_name,address,telephone}  # bounded nullable plain text
contacts: {telegram,whatsapp,email,maps}      # nullable typed contact URLs only
navigation: [home,cars,scooters,contact,terms,privacy] # unique allowed route keys
footer_note: <locale map>
```

Allowed contacts: concrete https t.me username, https wa.me international digits, mailto validated email, approved Google Maps host/path. No remote fetching. Unknown settings, arbitrary menu URLs, secrets, website origin/indexable/request switches rejected. Normal settings mutations carry expected_revision. Null contacts remain absent from rendered buttons. Deployment configuration remains the upper safety boundary.

## Published read and media delivery

- `PublishedVehiclePresentation::forVehicles(ids,locale)` returns a whitelisted map keyed by physical vehicle ID. One SQL query joins cover metadata and applies current Fleet eligibility; `PublishedWebsiteDocuments` reads the page/settings snapshots in one UNION query. The complete CMS overlay therefore adds at most2 SQL queries, with no file reads/hashes on catalog rendering. The singleton seam delegates to batch; flag-off returns immediately with0 CMS queries.
- Full original+variant validation runs at upload/publish. The publication captures safe immutable cover manifest metadata. Listing never invokes full `assertUsable` in a loop.
- GET `/website-media/{asset}/{variant}/{sha256}.webp`, named `website.media`, serves ONLY a currently published referenced derivative whose owner vehicle remains eligible. UUID/variant/hash are exact allowlists, not filesystem paths. Draft-only/orphan/deleted/unpublished media404. Selected-variant hash/MIME/size is validated before response; no original or other variants are read. Preview no-store remains mandatory; do not introduce immutable caching that bypasses revocation checks.
- Public controller passes optional `presentation` to card/detail and `cms_page` with localized typed blocks to existing Blade pages. Existing output is unchanged if no published locale/content exists. Frontend reuses current design partials, no redesign.
- Vehicle `presentation` is `{vehicle_id,publication_revision,locale,title,summary,meta_title,meta_description,cover}`; localized text is plain strings. Cover includes asset_id/kind/dimensions/alt and card/card_2x/detail metadata `{url,width,height,mime,bytes,sha256}`. `cms_page` is `{key,revision,title,meta_title,meta_description,blocks}` with localized string props/items, preserved image_ref and resolved `image:null|static_asset`. Requested dynamic block lists are separate `cms_catalogs:{all:[],car:[],scooter:[]}`; no price copies in documents.
- Published settings overlay public `website.name/contacts/business`, plus `website.navigation_order` and localized `website.footer_note`. Null contacts are removed. Protected preview receives preview_kind card/page, locale, vehicle/presentation/cms_page/cms_catalogs and normal website/navigation/languages/seo; accepting_requests is always false. Public media may be indexable only when the existing trusted runtime indexability gate allows it; private/demo/preview/errors remain noindex.
- Metadata only from published locale and whitelist. Current vehicle URLs stay stable. Canonical origin/indexability remain trusted runtime configuration; preview/private/filter pages stay noindex. JSON-LD stays code-generated and safely encoded, no editor script/JSON-LD field or invented ratings/address.

CMS query budget is separate from current PricingService locked quote queries. Do not silently cache or duplicate price arithmetic to satisfy a broader query-count claim.

## Acceptance and release gates

Tests: direct HTTP/service authorization, feature-off CMS-query absence, CSRF, draft privacy, ownership, deletion/publication races, stale revisions, page restore-to-draft, unknown fields/XSS/URL rejection, public media revoked-reference404, 1-vs50 cards <=2CMS SQL/0media reads, unchanged pricing/Fleet/TG, SSR locales/metadata and frontend keyboard/reduced-motion/responsive behavior. Linux shared-storage symlink regression must run before release; Windows skip is not live evidence.

Future preview Nginx must pass the exact quoted `/website-media/{uuid}/{card|card_2x|detail}/{sha256}.webp` pattern to the existing PHP named location, never a private-storage alias. The source boundary test checks this narrow mapping; an actual Linux `nginx -t` plus trusted-TLS published-media200/revoked404 remains a release gate. Existing immutable deployed artifacts and configuration are not changed by the local template delta.

Customer decisions remain image-to-inventory mapping, grouping (unchanged physical inventory until explicitly approved), missing-cover/mobile composition, final block/page/language priorities, future editor roles, business contacts/terms/payment rules, recovery/notification channels, SEO geography and analytics consent. These do not prevent implementing empty/draft-safe UI and workflow. Main public live bookings/domain and production CMS remain outside current authorization; preview release needs separate root GO.

Explicit main-live release gate: a rendered CMS terms/privacy body must be bound to the exact accepted `terms_version` and rechecked transactionally at submission before real requests can be enabled. This slice deliberately leaves policy publishing off and does not alter BookingService legal semantics. Either keep deployment-approved environment policy content, or implement/review revision-bound CMS policy acceptance in that later release; the authoring flag alone is not legal-version readiness.

Local authoring QA uses workspace-private `docs/tools/local-website-cms-preview.php --serve` with explicit APP_ENV=local, loopback8092 and a fresh sibling public-only final-price dataset. This helper and its fixture dependency chain are excluded from the public code release; this paragraph records local evidence, not a shipped setup entrypoint. Synthetic credentials are clearly marked constants in that local-only helper, not a real administrator. Never copy its QA users/documents/uploads/bookings to customer preview. The customer preview keeps its site-only boundary and no active admins. A later production CMS release must reconcile the current pricing/fixed-dependency release; do not deploy the old main-checkout bot/dependencies.

Current acceptance checkpoint: targeted foundation/CMS/Nginx/PG suite43passed/3skipped/513assertions, followed by CMS owning-editor redirect1passed/26assertions and guest redirect/pricing/occupancy/status5passed/113assertions. Full PHPStan0 and full PintPASS. These overlapping targeted runs are not an integrated full-suite count.

After frontend source/build freeze, ONE main-checkout full PHP run completed487total/461passed/23skipped/3failed/5101assertions. New CMS tests passed. Two failures belong to the excluded workspace-private `ReviewedFinalPricePreviewTest` subprocess chain (the direct production-negative helper refusal still works). The other was an old exact404 wrong-host assertion: TrustHosts legitimately denies400 before preview middleware. Only that test was adjusted to secure400/404 denial, retaining no-runtime/DB-access expectation, and rerun1passed/22assertions/PintPASS. The full main suite was not repeated; do not label it fully green. A clean integration-branch full run/CI is still required. Real Linux shared-root and both PostgreSQL concurrency tests must pass before release; actual Nginx syntax/TLS delivery is a separate deployment gate. Customer-facing operation/status guide: `docs/WEBSITE_CMS_GUIDE_2026_10_31.md`.
