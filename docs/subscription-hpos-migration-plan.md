# Subscription HPOS Migration Plan

## Goal

Move subscription management away from the current custom post type editor (`hb_ucs_subscription` on `wp-admin/post.php`) to a WooCommerce-native order-type architecture that can participate in the WooCommerce order admin flow and align with the HPOS-style editor patterns.

This plan is intentionally pragmatic:

- preserve existing business logic where possible;
- stop investing in the legacy CPT editor as the long-term UI;
- introduce a new subscription domain model that is compatible with WooCommerce order APIs and admin screens;
- migrate data incrementally and safely.

## Current State

The current subscriptions module stores subscriptions as a plain custom post type:

- post type: `hb_ucs_subscription`
- edit screen: `wp-admin/post.php?post={id}&action=edit`
- rendering model: WordPress metaboxes on the classic post editor
- storage model: post meta (`SUB_META_*` keys)

Key consequence:

- even if the UI is styled like WooCommerce, it does **not** use the same screen controller and order editor lifecycle as WooCommerce HPOS orders on `wp-admin/admin.php?page=wc-orders&action=edit&id={id}`.

## Root Cause

WooCommerce HPOS order screens are not just templates.
They depend on:

- a registered WooCommerce order type via `wc_register_order_type()`;
- WooCommerce order classes (`WC_Abstract_Order` / `WC_Order` descendants);
- a WooCommerce order data store;
- WooCommerce admin order screen routing and `PageController`;
- order-item APIs, order notes APIs, status handling, and edit actions.

The current plugin bypasses that stack by using a normal post type and custom metaboxes.

## Target Architecture

### Recommended target

Introduce a dedicated WooCommerce order type for subscriptions, for example:

- order type: `shop_subscription_hb`
- class: `HB_UCS_Subscription_Order`
- data store: initially CPT-backed, later optionally custom-table aware

This type should be registered with `wc_register_order_type()` so it becomes a WooCommerce-recognized order type.

### Why this target

This gives us:

- WooCommerce order editor compatibility;
- WooCommerce item, address, note and action patterns;
- HPOS-aware screen detection via `OrderUtil::is_order_edit_screen()`;
- a migration path that keeps compatibility with current WooCommerce order-related code;
- less custom admin rendering over time.

## Architecture Principles

1. **Domain first**
   - subscriptions become a first-class order type, not a decorated post.
2. **Backward compatibility during migration**
   - existing `hb_ucs_subscription` data remains readable until migration is complete.
3. **Dual-read before dual-write**
   - phase in reads from the new store while still supporting legacy records.
4. **Business logic extraction**
   - renewal, scheduling, status transitions and payment linkage move out of the metabox/UI layer.
5. **UI on WooCommerce primitives**
   - rely on WooCommerce order screens, order items, notes, actions and address editing where possible.

## Proposed Module Structure

Recommended new structure inside `src/Modules/Subscriptions`:

- `SubscriptionsModule.php`
  - reduce to module bootstrap and wiring
- `Domain/SubscriptionStatus.php`
  - status constants and label helpers
- `Domain/SubscriptionRepository.php`
  - lookup / migration-aware repository
- `Domain/SubscriptionService.php`
  - orchestration for renewals, status transitions, scheduling
- `OrderTypes/SubscriptionOrderType.php`
  - `wc_register_order_type()` registration
- `Orders/HB_UCS_Subscription_Order.php`
  - custom WooCommerce order class
- `DataStores/SubscriptionDataStoreCPT.php`
  - first migration step: wrap existing post/meta storage
- `Admin/SubscriptionAdmin.php`
  - hooks for admin screen behavior, meta boxes, actions, columns
- `Admin/MetaBoxes/...`
  - only subscription-specific panels not already handled by WooCommerce
- `Migrations/LegacySubscriptionMigrator.php`
  - legacy CPT to order-type migration runner
- `Compatibility/LegacySubscriptionBridge.php`
  - map old IDs/URLs to new records during transition

## Data Model Mapping

### Legacy source

Legacy subscription data currently lives primarily in:

- post type: `hb_ucs_subscription`
- post title / status
- meta keys such as:
  - `_hb_ucs_sub_status`
  - `_hb_ucs_sub_user_id`
  - `_hb_ucs_sub_parent_order_id`
  - `_hb_ucs_sub_scheme`
  - `_hb_ucs_sub_interval`
  - `_hb_ucs_sub_period`
  - `_hb_ucs_sub_next_payment`
  - `_hb_ucs_sub_payment_method`
  - `_hb_ucs_sub_payment_method_title`
  - `_hb_ucs_sub_mollie_customer_id`
  - `_hb_ucs_sub_mollie_mandate_id`
  - `_hb_ucs_sub_last_payment_id`
  - `_hb_ucs_sub_items`
  - `_hb_ucs_sub_fee_lines`
  - `_hb_ucs_sub_shipping_lines`
  - `_hb_ucs_sub_billing`
  - `_hb_ucs_sub_shipping`
  - `_hb_ucs_sub_trial_end`
  - `_hb_ucs_sub_end_date`
  - `_hb_ucs_sub_last_order_id`
  - `_hb_ucs_sub_last_order_date`

### Target order-type data

Recommended mapping:

#### Core order object fields
- customer id → `set_customer_id()`
- created date → order date created
- payment method → `set_payment_method()`
- payment method title → `set_payment_method_title()`
- billing address → `set_address( ..., 'billing' )`
- shipping address → `set_address( ..., 'shipping' )`

#### Order meta on the new order type
- subscription status → `_hb_ucs_subscription_status`
- parent order id → `_hb_ucs_subscription_parent_order_id`
- scheme → `_hb_ucs_subscription_scheme`
- interval → `_hb_ucs_subscription_interval`
- period → `_hb_ucs_subscription_period`
- next payment → `_hb_ucs_subscription_next_payment`
- trial end → `_hb_ucs_subscription_trial_end`
- end date → `_hb_ucs_subscription_end_date`
- last renewal order id → `_hb_ucs_subscription_last_order_id`
- last renewal timestamp → `_hb_ucs_subscription_last_order_date`
- Mollie customer id → `_hb_ucs_subscription_mollie_customer_id`
- Mollie mandate id → `_hb_ucs_subscription_mollie_mandate_id`
- Mollie last payment id → `_hb_ucs_subscription_last_payment_id`
- legacy CPT id → `_hb_ucs_legacy_subscription_post_id`
- source marker → `_hb_ucs_subscription_storage_version`

#### Order items
Current array-based line items should be converted to native WooCommerce order items:

- products → `WC_Order_Item_Product`
- fees → `WC_Order_Item_Fee`
- shipping → `WC_Order_Item_Shipping`
- notes → standard WooCommerce order notes

This is critical because once the subscription is a true order type, item editing should use native item APIs, not custom arrays stored in one meta blob.

## Status Strategy

Current statuses:

- `active`
- `pending_mandate`
- `payment_pending`
- `on-hold`
- `paused`
- `cancelled`
- `expired`

Recommendation:

- keep these as **subscription meta statuses**, not necessarily order post statuses;
- optionally map a subset to WC post statuses only if WooCommerce screen behavior requires it;
- expose them in the admin through a subscription-specific status selector and badges.

This avoids fighting WooCommerce core assumptions around normal order lifecycle statuses.

## Admin Screen Strategy

### Phase target

Use WooCommerce order editor routing and screen detection for the new order type.

The desired end state is:

- list screen behaves like a WooCommerce order list for the subscription order type;
- edit screen is resolved through WooCommerce order screen logic rather than plain `post.php` metabox page;
- only subscription-specific panels remain custom.

### UI components that should become native

Use WooCommerce-native behavior for:

- addresses
- order items
- order item notes
- order actions
- totals
- customer selector
- order notes/comments

Keep custom panels only for subscription-specific concepts:

- schedule / next payment / trial / end date
- renewal controls
- linked renewal orders
- mandate state / payment profile state

## Migration Phases

## Phase 0 — Freeze legacy editor growth

Objective:

- stop building more complexity into the CPT editor.

Actions:

- keep current editor operational;
- treat it as legacy maintenance only;
- route all new subscription-admin work toward the new order-type architecture.

Acceptance:

- no new core features added only to the legacy CPT UI.

## Phase 1 — Introduce new domain and order type scaffolding

Objective:

- make subscriptions a WooCommerce-recognized order type without immediately migrating all records.

Actions:

- add `SubscriptionOrderType.php` registering `shop_subscription_hb` via `wc_register_order_type()`;
- add `HB_UCS_Subscription_Order` class;
- add `SubscriptionRepository` interface and implementation capable of reading legacy CPT records;
- add `SubscriptionService` for status transitions / renewals / schedule logic;
- add admin screen detection for the new type using WooCommerce APIs.

Acceptance:

- the plugin can instantiate and load a subscription order object for the new type;
- no legacy data migration required yet.

## Phase 2 — CPT-backed data store adapter

Objective:

- make the new subscription order type read/write the current legacy data model.

Actions:

- add `SubscriptionDataStoreCPT` to hydrate `HB_UCS_Subscription_Order` from existing subscription posts/meta;
- map legacy `SUB_META_*` data into order object fields and meta accessors;
- normalize items, fees and shipping into native Woo order items on load/save;
- add write support back into legacy storage for safe compatibility.

Acceptance:

- editing a migrated-in-place subscription through the new model changes the same underlying business data;
- old cron and renewal logic can be redirected to repository/service without breaking live shops.

## Phase 3 — New admin list/edit screen for subscriptions

Objective:

- switch subscription administration to the new WooCommerce order-type flow.

Actions:

- add admin menu entry pointing to the new order-type list screen;
- add custom columns, filters and bulk actions for subscription type;
- move notes/actions/related orders/schedule into the new screen;
- de-emphasize or redirect the legacy CPT edit screen.

Acceptance:

- administrators manage subscriptions from a WooCommerce-native list/edit experience;
- the old `post.php?post={id}&action=edit` path is no longer the primary UI.

## Phase 4 — Record migration from legacy CPT to native subscription order records

Objective:

- create real subscription order-type records instead of relying on CPT-backed adaptation forever.

Actions:

- build `LegacySubscriptionMigrator`;
- create new `shop_subscription_hb` records;
- migrate addresses, items, notes, schedule and payment references;
- add legacy ID mapping meta;
- provide admin migration tool with dry-run and resumable batches.

Acceptance:

- migrated records open directly in the new editor;
- legacy-to-new mapping is stored and queryable;
- renewal orders and related order linking continue to work.

## Phase 5 — Optional HPOS-native storage

Objective:

- make the subscription order type align with HPOS-backed storage rather than CPT persistence.

Actions:

- evaluate whether the new type can use WooCommerce custom order tables cleanly;
- if needed, implement a dedicated order data store strategy for the subscription order type;
- ensure list/edit screens remain WooCommerce-native under HPOS.

Acceptance:

- subscription records behave consistently in HPOS environments.

## Routing and Backward Compatibility

### Legacy edit URL handling

During transition:

- keep `hb_ucs_subscription` records accessible;
- add admin notice on legacy edit screen pointing to the new editor;
- eventually redirect legacy edit requests to the mapped new subscription order record if available.

### Legacy business logic compatibility

All business logic currently calling `get_post_meta( $subId, ... )` should move behind repository/service methods such as:

- `get_subscription( $id )`
- `update_status( $id, $status )`
- `update_schedule( $id, ... )`
- `create_renewal_order( $id )`
- `get_related_orders( $id )`

This is required before storage can change safely.

## Risks

### 1. Tight coupling to post meta
The current module reads subscription data directly from post meta everywhere.

Mitigation:

- introduce repository/service layer before large migration steps.

### 2. Item model mismatch
Current items are partially stored as normalized arrays, not always native order items.

Mitigation:

- convert to `WC_Order_Item_*` objects at the new domain boundary.

### 3. Renewal logic depends on legacy IDs
Renewal orders currently link to the CPT subscription ID.

Mitigation:

- store both legacy and new IDs during transition;
- update lookup logic to resolve either identifier.

### 4. Admin screen parity expectations
The WooCommerce order screen still needs subscription-specific controls.

Mitigation:

- keep custom panels only for subscription schedule and related-order domain features.

### 5. Live-store migration safety
Shops may have active subscriptions and scheduled renewals while migration runs.

Mitigation:

- support dry run;
- use idempotent migration batches;
- maintain legacy lookup fallback until migration is fully verified.

## Recommended Implementation Order

1. Extract repository + service from `SubscriptionsModule`
2. Register new WooCommerce order type
3. Add custom subscription order class
4. Build CPT-backed adapter data store
5. Move renewal logic to service layer
6. Add new subscription admin list/edit flow
7. Add migration tooling for real record conversion
8. Add legacy URL redirect/bridge
9. Remove legacy editor from primary workflow

## Concrete Phase 1 Deliverables

For the next implementation step, build these files:

- `src/Modules/Subscriptions/Domain/SubscriptionRepository.php`
- `src/Modules/Subscriptions/Domain/SubscriptionService.php`
- `src/Modules/Subscriptions/OrderTypes/SubscriptionOrderType.php`
- `src/Modules/Subscriptions/Orders/HB_UCS_Subscription_Order.php`
- `src/Modules/Subscriptions/Admin/SubscriptionAdmin.php`

And refactor `SubscriptionsModule.php` so it:

- registers the new order type bootstrap;
- keeps the old CPT available temporarily;
- routes future admin behavior through the new admin/order-type classes.

## Decision Recommendation

**Recommended path:**

- do **not** keep iterating on the legacy CPT editor;
- implement a phased migration to a WooCommerce order type;
- use a CPT-backed adapter first to reduce migration risk;
- only then migrate records and UI fully.

This is the cleanest route to a WooCommerce-native subscription editor that can actually behave like the WooCommerce / WooCommerce Subscriptions admin experience.
