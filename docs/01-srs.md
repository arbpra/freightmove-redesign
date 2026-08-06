# Software Requirement Specification (SRS)

## 1. Product Vision

FreightMove will evolve from a traditional marketplace website into a premium, modern freight operations platform that connects shippers and carriers through intelligent matching, instant quoting, transparent tracking, and professional communication workflows.

The platform should feel more like a modern logistics SaaS product than a static marketplace.

## 2. Business Goals

- Increase trust, speed, and transparency between shippers and carriers
- Reduce manual quote handling through intelligent matching and notifications
- Provide a polished dashboard experience for all user roles
- Create a foundation for future AI-driven logistics features
- Support multi-role operations: guest, shipper, carrier, and admin

## 3. Scope

### In Scope
- User registration and authentication
- Shipper job posting and management
- Carrier profile, verification, and manual quoting
- Matching engine for relevant loads
- Quote comparison and acceptance
- Job review and messaging
- Admin moderation and operations
- Notifications and analytics

### Out of Scope for v1
- Live shipment tracking
- Instant quoting
- Live GPS telematics integration
- Invoicing management
- Mobile app development
- Full ERP integration
- Autonomous dispatching
- Customs and compliance automation beyond basic document support
- Multi-tenant white-label SaaS features

## 4. User Roles

### Guest
Can browse the website, view services, read blogs, contact support, register, and log in.

### Shipper
Can create freight jobs, upload images/documents, receive quotes, compare carriers, accept quotes, review completed jobs, and manage account settings.

### Carrier
Can register, complete verification, receive matching loads, submit quotes, manage fleet and vehicle details, receive reviews, and manage settings.

### Admin
Can manage users, carriers, jobs, quotes, payments, CMS content, blogs, support tickets, reports, and analytics.

## 5. Functional Requirements

### 5.1 Core Marketplace Features
- Users can register as shipper or carrier
- Shippers can create freight jobs with pickup, delivery, category, weight, trailer, loading requirements, and budget preferences
- Carriers can submit manual quotes for relevant jobs
- Shippers can compare quotes and accept one carrier
- Jobs can transition through statuses: Draft, Published, Matched, Quoted, Accepted, Completed, Cancelled, Disputed
- Reviews can be submitted after job completion

### 5.2 Matching Engine
- Matching is based on pickup, delivery, vehicle type, trailer type, weight capacity, category, availability, carrier rating, historical performance, and preferences
- Only relevant carriers receive notifications

### 5.3 Messaging and Notifications
- In-app messages with images and documents
- Email, browser, and push-style notifications
- Notification preferences per user

### 5.4 Admin Module
- Approve or reject carrier verification documents
- Moderate jobs and quotes
- Manage support tickets and content
- Review analytics and transaction activity

## 6. Non-Functional Requirements

### Performance
- Initial page loads under 2.5 seconds on a standard broadband connection for public pages
- Dashboard interactions must feel immediate
- Lazy-load routes, images, and heavy components

### Security
- Secure authentication via Sanctum
- Role-based access control
- Input validation, rate limiting, encryption for sensitive fields, and audit logging

### Reliability
- Queue-based handling for notifications, emails, and file processing
- Graceful error handling and retry logic

### Usability
- Responsive across mobile, tablet, and desktop
- Accessible UI with keyboard support and readable contrast

## 7. Success Metrics

- Quote response time reduced by 60% compared with manual workflows
- Job posting to quote conversion improved significantly
- Carrier onboarding completion rate improved
- User retention on dashboard increased
- Admin moderation time reduced

## 8. Assumptions and Dependencies

- Angular 20 frontend will be deployed as static assets
- Laravel 12 backend will host the API and business logic
- MySQL will serve as the primary relational database
- SiteGround shared hosting will be used for deployment with a decoupled frontend/backend setup

## 9. Acceptance Criteria

The redesign is complete when:
- The homepage and core flows feel modern, premium, and mobile responsive
- Shipper and carrier onboarding are clear and conversion-focused
- Jobs can be posted, quoted, accepted, and reviewed end to end
- Admin can manage the marketplace effectively
- The architecture is modular and ready for future AI services
