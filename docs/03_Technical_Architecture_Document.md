# Fayadhowr Technical Architecture Document

## 1. Project Overview

Fayadhowr is a mobile-first business system supported by a Laravel backend and Flutter client applications. This document is the official technical architecture reference and defines the approved architectural boundaries for the project.

## 2. Technology Stack

| Area | Technology |
| --- | --- |
| Backend framework | Laravel 12 |
| Backend language | PHP 8.x |
| Database | PostgreSQL |
| Mobile application | Flutter |
| Application interface | REST API |
| Authentication | Laravel Sanctum |
| Source control | Git and GitHub |

## 3. High-Level System Architecture

The system consists of Flutter clients communicating with a versioned Laravel REST API over HTTPS. Laravel contains application logic, authorization, validation, and integrations. PostgreSQL is the system of record. File storage, notifications, logging, backups, and monitoring are supporting infrastructure concerns.

## 4. Backend Architecture

### Folder Structure

The backend shall use Laravel's standard structure and organize application-specific code by responsibility under `app`, including controllers, services, models, policies, requests, resources, middleware, jobs, events, and notifications.

### Layers

- **Presentation layer:** Routes, controllers, API resources, and middleware.
- **Application layer:** Services and use-case orchestration.
- **Domain and data layer:** Models, relationships, policies, and persistence rules.
- **Infrastructure layer:** Storage, notifications, queues, logging, and external integrations.

### Controllers

Controllers shall remain thin: accept requests, invoke services, and return API resources or standardized responses.

### Services

Services shall contain reusable business workflows and coordinate domain operations. Business logic shall not be duplicated across controllers.

### Models

Eloquent models shall represent persisted entities, relationships, casts, and narrowly scoped domain behavior.

### Policies

Policies shall enforce authorization for protected resources and actions.

### Requests

Form Requests shall define validation and request-level authorization for input-bearing endpoints.

### Resources

API Resources shall provide stable, consistent JSON transformations and prevent unintended data exposure.

### Middleware

Middleware shall handle cross-cutting concerns, including authentication, authorization context, rate limiting, request handling, and security headers.

## 5. Flutter Architecture

- Use a feature-first project structure.
- Select and document a state-management solution before implementation; no state-management package is mandated by this document.
- Use repositories to isolate data access from presentation and state logic.
- Use services for API communication, secure storage, notifications, and platform integrations.
- Centralize routing and guard protected customer routes according to the authenticated `users` principal and linked `customer_profiles` record. Admin routes use a separate `admins` guard and RBAC.

## 6. Database Architecture

### Schema Strategy

PostgreSQL is the authoritative data store. The schema shall be normalized where practical and designed around approved business entities and workflows.

### Relationships

Relationships shall be explicitly modeled with foreign keys and Laravel Eloquent relationships.

### Identity & Persistence Model

- `users` is the sole authentication identity for mobile customers. It owns credentials, verification state, provider linkage, account eligibility, and Sanctum token ownership.
- `customer_profiles` is a one-to-one business/profile extension of `users` (`customer_profiles.user_id` is unique). It owns the public Customer Reference (`CUS-YYYY-######`), display/profile fields, classification, and customer business context.
- There is no standalone `customers` authentication identity table.
- Authentication-adjacent records use `user_id`; approved customer business records, including payments, use `customer_profile_id`.
- `admins` and future staff identities are separate from `users`, with independent guards, authorization policies, and tokens.

### Migrations

All schema changes shall be introduced through version-controlled Laravel migrations. Existing schema changes require approval.

### Constraints

Use primary keys, foreign keys, unique constraints, check constraints where appropriate, and non-null requirements to protect data integrity.

### Indexes

Indexes shall support primary and foreign keys, frequent filters, sorting, joins, and approved performance requirements. Indexes shall be reviewed for query benefit and write cost.

## 7. API Architecture

### REST Standards

The API shall use RESTful resources, HTTP methods, consistent naming, JSON payloads, and appropriate HTTP status codes.

### Versioning Strategy

All public API routes shall be versioned under a path prefix, beginning with `/api/v1`.

### Response Format

Successful responses shall use a consistent JSON envelope containing the relevant `data` and, when needed, `meta` or `message` fields.

### Error Response Format

Error responses shall use a consistent JSON envelope containing an error message, a stable error identifier when applicable, and field-level validation details when applicable.

## 8. Authentication & Authorization

Laravel Sanctum shall provide token-based authentication with separate customer and admin realms.

### 8.1 Customer Mobile Authentication

- Principal: `users`, linked one-to-one to `customer_profiles`.
- Sanctum customer tokens are issued to `users` only.
- Customer APIs authenticate through `users.id`; approved business records are owner-scoped through the linked `customer_profiles` record.

### 8.2 Admin and Staff Authentication

- Principal: `admins`, separate from `users`.
- Five roles: **Super Admin**, **Manager**, **Sales**, **Inventory**, **Accountant**.
- **Hybrid RBAC:** effective permissions = role permissions ∪ direct admin permissions; Super Admin has all permissions implicitly.
- **Dual Dashboard Architecture:** Super Admin Dashboard vs Operations Dashboard; module visibility from effective permissions; Dashboard Statistics cached per admin.
- Inactive admins are rejected by admin middleware on protected routes (including existing tokens).
- Sensitive admin mutations dispatch `AuditEvent` for event-driven audit log persistence.
- Future staff identities remain separate from customer identities and must not share customer guards or token scopes.

### 8.3 Cross-Realm Rules

- A `users` record must never be promoted to an admin role through the mobile API.
- An `admins` record must never authenticate as a customer on mobile endpoints.
- Policies, middleware, and service-level rules enforce permissions. Clients are never the authority for authorization decisions.

## 9. File Storage Architecture

Files shall be stored through Laravel's filesystem abstraction using an approved storage provider. Database records shall store file metadata and references rather than unmanaged file-system paths. Access to private files shall require authorization.

## 10. Payment Domain Architecture

### 10.1 Module Boundary

Payment V1 is one unified, gateway-independent module for Service Orders and Store Orders. A payment uses a polymorphic payable reference (`payable_type`, `payable_id`) to identify the originating payable domain record. The originating domain retains its commercial and workflow rules; Payment owns only the payment lifecycle, payment records, gateway integration, transactions, and receipt records.

Payments follow ADR-001 ownership:

```text
users
  ↓
customer_profiles
  ↓
payments
```

`users` remains the authentication principal. `payments.customer_profile_id` records the business owner; no payment authenticates through a customer profile.

### 10.2 Payment Lifecycle and Order Integration

The approved Payment V1 lifecycle is: Pending → Initialized → Processing → Paid, Failed, or Cancelled. Refunds are outside V1.

Orders begin in `pending_payment`. When a payment becomes Paid, the originating Order becomes `confirmed`. For Store Orders, Payment = Paid is also the sole trigger that decreases product stock and writes a Stock Ledger customer-sale entry; Failed or Cancelled payments do not change stock and do not automatically cancel an Order. Payment status transitions must be transactional and leave the originating domain responsible for its own allowed transitions.

### 10.3 Payment Persistence and Receipts

- `payments` stores the customer-profile-owned payable record and current payment lifecycle state.
- `payment_transactions` stores one or more gateway transaction attempts or updates for one Payment.
- Every successful payment produces exactly one receipt with public number `RCPT-YYYY-######`.
- Receipt PDF generation is explicitly outside the current scope.

### 10.4 Gateway Abstraction

The Payment domain shall define a provider-neutral gateway abstraction. Provider adapters may support EVC Plus, Zaad, Sahal, Stripe, PayPal, or future gateways without coupling Payment models or originating domains to a specific provider.

### 10.5 Events and Notifications

Payment does not send notifications directly. It publishes domain events such as `PaymentPaid` and `PaymentFailed`; the future Notification Module consumes those events according to approved notification rules.

## 10A. Store Domain Architecture

### 10A.1 Module Boundary

Store V1 is a physical-product commerce domain separate from Services and separate from Inventory purchasing. Store owns product catalog, categories, product images, cart, checkout, Store Orders, and Unified Payment integration. Store does not own suppliers, purchase orders, goods receipts, stock ledger maintenance, or stock adjustments.

V1 categories: Cleaning Chemicals, Cleaning Tools, Cleaning Accessories, Personal Protective Equipment (PPE), Air Fresheners. Heavy cleaning equipment and machines are outside V1.

### 10A.2 Product Entity

Products remain a single business entity shared across Store and Inventory. Product stores SKU, Name, Description, Selling Price, Cost Price, Currency, Current Stock, Low Stock Threshold, and Status. Inventory movements are stored separately in Stock Ledger. Changing Selling Price never changes Cost Price. Inventory costing methods are outside V1.

### 10A.3 Store Order and Stock Rules

Store Orders reuse the Unified Payment Module and follow `pending_payment` → `confirmed` → `processing` → `completed` / `cancelled`. Creating a Store Order never decreases stock. Stock decreases only after Payment = Paid. Failed or cancelled payments leave stock unchanged. Negative stock and overselling are not allowed.

## 10B. Inventory Domain Architecture

### 10B.1 Module Boundary

Inventory is a separate business domain that manages Suppliers, Purchase Orders, Goods Receipts, Stock Ledger, Stock Adjustments, Stock Quantity, and Low Stock Alerts.

### 10B.2 Stock Flow

```text
Supplier → Purchase Order → Goods Receipt → Inventory Increase → Store Product
→ Customer Purchase → Payment Paid → Inventory Decrease → Stock Ledger Entry
```

Purchase Order alone never changes stock. Goods Receipt is allowed only after Purchase Order approval (`approved` or `partially_received`) and increases stock while creating Stock Ledger entries in `stock_ledgers`. Manual adjustments require quantity and reason (`Damaged`, `Lost`, `Correction`, `Physical Count`) and create Stock Ledger entries.

### 10B.3 Purchase Order Lifecycle

`Draft` → `Submitted` → `Approved` → `Partially Received` → `Completed` / `Cancelled`

Submitted Purchase Orders must not receive inventory. Approval is required before Goods Receipt.

### 10B.4 Stock Ledger

Every stock movement is recorded in `stock_ledgers` with quantity, movement type, polymorphic reference, and timestamp. Movement types include Purchase Receipt, Customer Sale, Adjustment, Correction, Damage, and Loss.

### 10B.5 Low Stock

Each product defines Current Stock and Low Stock Threshold. Dashboard displays Low Stock alerts. Email/SMS low-stock notifications are outside V1.

## 11. Notification Architecture

Notifications shall use Laravel notification channels and queued delivery where applicable. Notification templates, preferences, delivery status, and failure handling shall be designed around approved business requirements.

## 12. Logging & Error Handling

The backend shall use structured application logging with appropriate severity levels and correlation context where available. Exceptions shall be handled centrally, reported safely, and translated into consistent API errors. Sensitive data shall not be logged.

## 13. Security Architecture

- Enforce HTTPS in production.
- Validate and authorize every protected request.
- Use Laravel Sanctum for authenticated API access.
- Apply least-privilege access through roles and policies.
- Enforce strict separation between customer (`users`) and admin/staff authentication guards, token issuers, and authorization policies.
- Protect secrets through environment configuration.
- Prevent sensitive data exposure through API Resources and secure storage.
- Apply rate limiting and security headers where appropriate.
- Keep dependencies updated according to approved maintenance practices.

## 14. Performance Strategy

Performance shall be supported by efficient database queries, appropriate indexes, pagination, eager loading where needed, caching of approved read-heavy data, background jobs for long-running work, and measured optimization based on observed bottlenecks.

## 15. Scalability Strategy

The application shall remain stateless at the API layer where practical, support horizontal scaling, move long-running work to queues, use managed storage for files, and isolate integrations behind services. Database growth and cache strategies shall be reviewed as usage grows.

## 16. Backup & Recovery Strategy

Production databases and critical files shall have scheduled backups, retention policies, access controls, and documented recovery procedures. Backup restoration shall be tested periodically in a safe environment.

## 17. Development Environment

Development shall use version-controlled configuration templates, local environment variables, isolated databases, migrations, seed data only when approved, automated tests, code formatting, static analysis where adopted, and Git-based collaboration.

## 18. Production Environment

Production shall use secure environment configuration, HTTPS, managed PostgreSQL backups, queue workers where required, centralized logging and monitoring, controlled deployments, rollback capability, and restricted operational access.

## 19. Architecture Principles

- Follow the approved architecture.
- Keep modules cohesive and loosely coupled.
- Separate presentation, application, domain, and infrastructure concerns.
- Prefer reusable, testable, and maintainable components.
- Protect data integrity, security, and backward compatibility.
- Make changes traceable to approved documentation.

## 20. Future Expansion Strategy

Future features, integrations, roles, channels, and services shall be added through approved scope and architecture review. New capabilities shall preserve modular boundaries, API versioning commitments, data integrity, security controls, and documented traceability.
