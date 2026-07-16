# ADR-001: Identity Architecture

## Status

Approved

## Decision

`users` is the single authentication identity table for the customer mobile application.

`customer_profiles` is a separate one-to-one business/profile table:

```text
users
└── customer_profiles
```

There will be no standalone `customers` authentication identity table.

`admins` remains a separate identity table for the future Admin Panel. Future staff identities and roles remain in the admin realm and must not share customer credentials, guards, or tokens.

## Context

Authentication Core V1 already authenticates `App\Models\User` through Laravel Sanctum. The original database design also introduced a `customers` table containing credentials, contact data, account status, and customer profile data. Operating both `users` and `customers` as identities would create duplicate credentials, uncertain token ownership, and ambiguous foreign-key ownership for bookings, quotations, orders, payments, notifications, and reviews.

## Problem

The project needs one authoritative mobile-customer principal while retaining a dedicated place for customer business and profile data. The design must preserve the current working Laravel and Sanctum foundation, keep customer and admin authorization realms separate, and support future customer modules without a risky identity migration.

## Options Considered

### Option A — Extend `users` as the single identity

Keep `users` as the only mobile-customer authentication identity. Add identity-level fields required for approved customer authentication—such as phone, verification state, provider linkage, account eligibility, and last-login metadata—to `users` through future approved migrations.

Create `customer_profiles` as a one-to-one extension of `users`. It stores customer business/profile data, including the public Customer Reference (`CUS-YYYY-######`), display name, avatar, preferred language, classification, preferences, and profile-specific lifecycle data.

### Option B — Replace `users` with `customers`

Make `customers` the sole customer identity and migrate all current users, credentials, Sanctum token ownership, authentication providers, guards, routes, tests, and future foreign keys to that model.

## Why Option A Was Selected

- It preserves the working Laravel 13 `User` model and Sanctum token ownership.
- It avoids an immediate credential, token, and guard migration before customer business records exist.
- It keeps one mobile identity while still providing a clean customer business boundary through `customer_profiles`.
- It supports customer-owned business data without coupling Admin or Staff identity to customer credentials.
- It is additive and can be introduced through approved migrations with a substantially lower rollback and operational risk.

## Why Option B Was Rejected

- It would require a dedicated cutover project: user backfill, credential mapping, Sanctum token migration or forced re-authentication, guard/provider changes, and rollback validation.
- It risks failed logins, invalid sessions, duplicate identities, and orphaned records.
- It changes already-working authentication without a business requirement that outweighs the migration risk.
- It provides cleaner table naming but no material customer-facing capability that Option A cannot provide.

## Identity and Ownership Rules

- `users.id` is the authenticated customer principal and the owner key for customer transactional records.
- `customer_profiles.user_id` is unique and links one profile to one user.
- Customer profile-scoped records, including addresses, use `customer_profile_id`.
- Transactional records—including carts, bookings, quotation requests, orders, payments, notifications, and reviews—use `user_id`.
- Customer-facing authorization is owner-scoped through the authenticated `users.id`.
- `customer_profiles` is not independently authenticatable and cannot own Sanctum tokens.
- `admins` and future staff identities use separate guards and authorization policies. They must never receive customer tokens or authenticate as customer users.

## Long-Term Consequences

### Positive

- One authoritative customer credential store and one Sanctum tokenable model.
- Clear split between authentication identity and business/profile data.
- Lower migration and maintenance cost than replacing a live Laravel identity.
- Future Admin and Staff modules retain an explicit cross-realm boundary.

### Trade-offs

- Customer reads often join `users` and `customer_profiles`.
- The project must consistently distinguish `user_id` from `customer_profile_id`.
- Existing documentation and unexecuted customer schema artifacts that describe a `customers` identity are superseded and must not be used.

## ER Diagram

```text
users
└── customer_profiles (1:1)
    ├── customer_addresses
    ├── customer_payment_methods
    └── customer_notes

users
├── customer_devices
├── carts
├── bookings
├── quotation_requests
├── orders
├── payments
├── notifications
└── reviews

admins
└── admin_role ── roles ── role_permission ── permissions
```

## Implementation Guardrail

No migration, model, route, controller, or authentication change may introduce a second customer authentication identity. Any future implementation must follow this ADR unless a later approved ADR explicitly supersedes it.
