# REST API Specification

## 1. API Conventions

- Base URL: /api/v1
- JSON responses with consistent envelope format
- Authenticated routes use Sanctum tokens
- Pagination for list endpoints
- Standard HTTP verbs: GET, POST, PUT, PATCH, DELETE

## 2. Authentication Endpoints

### POST /auth/register
Register a new user as shipper or carrier

### POST /auth/login
Login and issue a Sanctum token

### POST /auth/logout
Invalidate token

### POST /auth/forgot-password
Send password reset email

### POST /auth/reset-password
Reset password

### GET /auth/me
Return current authenticated user profile

## 3. Public Endpoints

### GET /public/featured-jobs
List recently published jobs

### GET /public/blog-posts
List published blog posts

### GET /public/industries
List supported industries

### GET /public/routes
List popular routes

## 4. Shipper Endpoints

### GET /shipper/jobs
List shipper jobs

### POST /shipper/jobs
Create a freight job

### GET /shipper/jobs/{id}
Get job details

### PUT /shipper/jobs/{id}
Update job

### DELETE /shipper/jobs/{id}
Delete or cancel job

### GET /shipper/jobs/{id}/quotes
List quotes for a job

### POST /shipper/jobs/{id}/accept-quote
Accept a selected quote

### POST /shipper/jobs/{id}/review
Submit a review after completion

## 5. Carrier Endpoints

### GET /carrier/matches
List matching jobs for the carrier

### GET /carrier/quotes
List carrier quotes

### POST /carrier/quotes
Submit a quote

### PUT /carrier/quotes/{id}
Update quote

### GET /carrier/fleet
List carrier fleet vehicles

### POST /carrier/verification-documents
Upload verification documents

### GET /carrier/jobs
List accepted and completed jobs

## 6. Messaging Endpoints

### GET /messages/conversations
List conversations

### GET /messages/conversations/{id}
Get conversation thread

### POST /messages/conversations/{id}/messages
Send a message

### POST /messages/conversations/{id}/read
Mark messages as read

## 7. Notification Endpoints

### GET /notifications
List notifications

### PATCH /notifications/{id}/read
Mark notification as read

### PATCH /notifications/preferences
Update notification preferences

## 8. Admin Endpoints

### GET /admin/users
List users

### GET /admin/carriers
List carriers

### POST /admin/carriers/{id}/approve
Approve carrier verification

### GET /admin/jobs
List all jobs

### GET /admin/quotes
List all quotes

### GET /admin/analytics
Get marketplace analytics

## 9. Response Examples

### Success Response
```json
{
  "success": true,
  "data": {},
  "message": "Operation completed successfully"
}
```

### Error Response
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {}
}
```
