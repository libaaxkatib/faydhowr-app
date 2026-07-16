# Fayadhowr System Design Document

## 1. System Overview

Fayadhowr is a Flutter-based client application supported by a Laravel REST API and PostgreSQL database. The system coordinates customer interactions, bookings or store purchases, quotations, discussions, orders, payments, notifications, reports, and administrative settings. Laravel is the authoritative application layer for validation, business rules, authorization, workflow transitions, and data persistence.

## 2. End-to-End Business Flow

```text
Customer
  ↓
Authentication (`users` + `customer_profiles`)
  ↓
Booking / Store Purchase
  ↓
Quotation
  ↓
Discussion
  ↓
Quotation Accepted
  ↓
Order
  ↓
Payment
  ↓
Reports
  ↓
Settings
```

Each transition is governed by approved business rules, authorization, validation, and recorded system state. The exact availability of each path depends on the approved workflow and user role.

## 3. Module Interaction Diagram

```text
Authentication (`users`) ── Customer Profiles ── Bookings ── Quotations ── Orders ── Payments
Settings ────────────────────────────────────────────│             │          │
                                                       └─ Discussions ┘          │
                                                                                │
Notifications ◀────────────────────────────────────────────────────────────────┘
Reports ◀──────────────────── Customer Profiles / Bookings / Quotations / Orders / Payments

Admin Authentication (`admins`) ── Admin Panel / Settings / Reports
```

- **Authentication (`users`)** validates customer credentials and issues mobile tokens; protected modules resolve the linked customer profile for business context.
- **Customer Profiles (`customer_profiles`)** provides the one-to-one customer business context used by bookings, quotations, orders, payments, notifications, and reports. It is not an authentication identity.
- **Admin Authentication (`admins`)** is separate from customer authentication and gates admin-panel modules, settings, and operational reports.
- **Bookings** captures approved booking activity and may initiate quotation processing.
- **Quotations** records proposed commercial terms and supports the approved discussion and acceptance lifecycle.
- **Orders** are created only from approved quotation acceptance or approved purchase workflows.
- **Payments** record payment activity associated with eligible orders.
- **Notifications** communicate approved workflow events to authorized recipients.
- **Reports** read authorized, aggregated operational data without becoming a source of transactional truth.
- **Settings** governs approved system configuration and authorized administrative controls.

## 4. Data Flow

```text
Flutter Client
  ↓ HTTPS JSON request
REST API
  ↓ routing, authentication, validation, authorization
Laravel Application
  ↓ approved business workflow and persistence operations
PostgreSQL Database
  ↓ committed workflow event
Notifications
  ↓ authorized operational data
Reports
```

Flutter submits requests and renders API responses. The REST API routes requests to Laravel, where customer or admin authentication is selected by endpoint realm, then validation, authorization, and approved business workflows are applied. Customer requests authenticate `users` and resolve linked `customer_profiles`; admin requests authenticate `admins`. Laravel persists authoritative data to PostgreSQL. Approved workflow events may produce notifications. Reporting reads authorized transactional data and presents aggregated information without bypassing the application’s access controls.

## 5. Authentication Flow

1. A user submits approved credentials through Flutter.
2. Flutter sends the request to the authentication API endpoint over HTTPS.
3. Laravel validates `users` credentials and account eligibility, then loads or creates the linked `customer_profiles` record when required.
4. Laravel Sanctum issues or manages a token bound to the authenticated `users` principal according to the approved session policy.
5. Flutter securely stores and sends the token for protected requests.
6. Laravel authenticates each protected customer request as `users`, applies owner-scoped authorization, and loads customer business context from `customer_profiles`.

## 6. Booking Flow

1. An authorized customer or staff member submits booking information.
2. Laravel validates the request and confirms authorization.
3. The booking is stored with its approved status and authenticated `user_id` ownership; the linked customer profile supplies business context.
4. Authorized staff review or process the booking according to approved business rules.
5. When applicable, the booking provides context for quotation creation.
6. Relevant workflow changes may produce notifications and become available to reporting.

## 7. Quotation Flow

1. An authorized user creates a quotation from an approved booking, purchase context, or approved workflow.
2. Laravel validates required details, pricing rules, and permitted state transitions.
3. The quotation is saved and associated with the authenticated `user_id`, linked customer profile, and source context.
4. Authorized participants conduct discussions through the approved quotation workflow.
5. Acceptance is recorded only by an authorized action and approved business rule.
6. An accepted quotation becomes eligible to create an order according to the approved process.

## 8. Order Flow

1. Laravel verifies that the source quotation or purchase workflow is eligible for order creation.
2. The system creates the order with the required `user_id`, customer-profile reference, and commercial references.
3. Authorized users process order status changes through approved transitions.
4. Order events may initiate payment handling, notifications, and reporting updates.
5. The order history remains traceable to its approved originating workflow.

## 9. Payment Flow

1. An authorized payment action references an eligible order.
2. Laravel validates the payment request, authorization, amount, and order state.
3. Payment activity is recorded using the approved payment workflow.
4. The related order is updated only when the approved payment conditions are met.
5. Authorized notifications and reports reflect the resulting payment state.

## 10. Notification Flow

1. A supported workflow event occurs, such as a booking, quotation, acceptance, order, or payment change.
2. Laravel determines whether the event requires notification under approved rules.
3. The system identifies authorized recipients and permitted delivery channels.
4. The notification is delivered synchronously or through an approved queue.
5. Delivery outcomes and failures are logged without exposing sensitive information.

## 11. Reporting Flow

1. An authorized user requests a report.
2. Laravel verifies the `admins` role, reporting scope, and requested filters. Customer-scoped reports aggregate through `users` and `customer_profiles`.
3. The reporting layer reads the required approved transactional data.
4. Laravel returns aggregated, authorized report data through the REST API.
5. Flutter displays the returned information without modifying source records.

## 12. Admin Workflow

1. An administrator authenticates against the separate `admins` identity using the approved admin guard/session policy.
2. Role-based authorization grants access only to approved administrative modules.
3. The administrator manages approved settings, operational records, and reports.
4. Every administrative action is validated, authorized, logged where required, and limited by approved business rules.
5. Administrative access does not permit unapproved changes to business rules, schema, or UI/UX.

## 13. Error Flow

1. Flutter submits a request to the REST API.
2. Laravel validates authentication, authorization, input, and workflow state.
3. If processing fails, Laravel returns a consistent JSON error response with an appropriate HTTP status code.
4. Flutter displays an approved, user-safe error state.
5. Laravel logs actionable technical details without logging sensitive data.
6. Unexpected errors are handled centrally and do not expose internal implementation details.

## 14. File Upload Flow

1. An authorized user selects a permitted file through Flutter.
2. Flutter sends the file and required metadata to the REST API over HTTPS.
3. Laravel validates authorization, file type, size, and other approved restrictions.
4. Laravel stores the file through the approved filesystem abstraction.
5. The database stores authorized file metadata and references.
6. File retrieval requires authorization and uses the approved access mechanism.

## 15. Security Flow

1. All client-to-server communication uses HTTPS.
2. Laravel Sanctum authenticates protected API requests.
3. Middleware, policies, and request validation enforce role and resource authorization.
4. Laravel validates all input before business processing.
5. API Resources limit responses to approved data.
6. Sensitive configuration and credentials remain in protected environment configuration.
7. Security-relevant failures are logged safely and monitored through approved operational processes.

## 16. Sequence Diagrams (Text-Based)

### Authentication

```text
Flutter → REST API: Submit credentials
REST API → Laravel: Route authentication request
Laravel → Database: Verify `users` credentials; load/create linked `customer_profiles`
Database → Laravel: User and customer-profile result
Laravel → REST API: Sanctum token bound to `users` and profile context
REST API → Flutter: Authenticated JSON response
```

### Quotation to Order

```text
Flutter → REST API: Accept quotation
REST API → Laravel: Validate request and authorization
Laravel → Database: Verify quotation state
Laravel → Database: Record acceptance and create eligible order
Laravel → Notifications: Dispatch approved event
Laravel → REST API: Return quotation and order state
REST API → Flutter: Updated JSON response
```

### Payment

```text
Flutter → REST API: Submit payment action
REST API → Laravel: Validate request and authorization
Laravel → Database: Validate order and record payment
Laravel → Database: Update order when approved conditions are met
Laravel → Notifications: Dispatch approved payment event
Laravel → REST API: Return payment and order state
REST API → Flutter: Updated JSON response
```

## 17. Design Principles

- Preserve approved business rules and workflow states.
- Maintain separation between Flutter presentation, REST API transport, Laravel application logic, and database persistence.
- Keep modules cohesive, reusable, and independently maintainable.
- Enforce validation and authorization at system boundaries.
- Use consistent API contracts and traceable state changes.
- Avoid duplicate logic and undocumented behavior.
- Preserve backward compatibility whenever possible.

## 18. Future Scalability

The system shall scale through modular Laravel services, feature-first Flutter organization, API versioning, database indexing, pagination, caching of approved read-heavy data, queued background work, managed file storage, and horizontally scalable API infrastructure. Future expansion must be approved, documented, secure, and compatible with the architecture defined in the Technical Architecture Document.
