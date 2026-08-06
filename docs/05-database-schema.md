# Database Schema

## 1. Core Design Principles

- Use relational tables for users, jobs, quotes, messages, reviews, and payments
- Keep audit fields on core entities: created_at, updated_at, created_by, updated_by
- Support soft deletes where appropriate for moderation and recovery
- Use status enums for job and quote lifecycle tracking

## 2. Core Tables

### users
- id (PK)
- name
- email
- password_hash
- phone
- role (guest|shipper|carrier|admin)
- status (pending|active|suspended|blocked)
- email_verified_at
- avatar_url
- timezone
- locale
- created_at
- updated_at

### user_profiles
- id (PK)
- user_id (FK -> users.id)
- company_name
- abn_acn
- business_type
- address_line_1
- address_line_2
- city
- state
- postal_code
- country
- bio
- verification_status (unverified|pending|verified|rejected)
- rating
- completed_jobs_count
- created_at
- updated_at

### carriers
- id (PK)
- user_id (FK -> users.id)
- fleet_size
- service_radius_km
- preferred_regions
- insurance_provider
- insurance_policy_number
- operating_since
- created_at
- updated_at

### vehicle_types
- id (PK)
- carrier_id (FK -> carriers.id)
- name
- trailer_type
- max_weight_tons
- dimensions
- is_active
- created_at
- updated_at

### freight_jobs
- id (PK)
- shipper_id (FK -> users.id)
- title
- description
- pickup_location
- delivery_location
- pickup_date
- delivery_date
- load_category
- weight_tons
- vehicle_type_required
- trailer_type_required
- budget_min
- budget_max
- status
- visibility
- images_json
- documents_json
- created_at
- updated_at

### job_quotes
- id (PK)
- job_id (FK -> freight_jobs.id)
- carrier_id (FK -> users.id)
- amount
- currency
- estimated_delivery_date
- notes
- status (pending|accepted|rejected|expired)
- match_score
- created_at
- updated_at

### job_acceptances
- id (PK)
- job_id (FK -> freight_jobs.id)
- quote_id (FK -> job_quotes.id)
- carrier_id (FK -> users.id)
- shipper_id (FK -> users.id)
- accepted_at
- created_at
- updated_at

### job_tracking
- id (PK)
- job_id (FK -> freight_jobs.id)
- current_status
- last_location
- eta
- updated_at

### reviews
- id (PK)
- job_id (FK -> freight_jobs.id)
- reviewer_id (FK -> users.id)
- reviewed_user_id (FK -> users.id)
- rating
- comment
- created_at
- updated_at

### conversations
- id (PK)
- job_id (FK -> freight_jobs.id)
- participant_one_id (FK -> users.id)
- participant_two_id (FK -> users.id)
- created_at
- updated_at

### messages
- id (PK)
- conversation_id (FK -> conversations.id)
- sender_id (FK -> users.id)
- message_type (text|image|document)
- body
- attachment_path
- read_at
- created_at
- updated_at

### notifications
- id (PK)
- user_id (FK -> users.id)
- type
- title
- body
- is_read
- related_type
- related_id
- created_at
- updated_at

### verification_documents
- id (PK)
- user_id (FK -> users.id)
- document_type
- file_path
- status (pending|approved|rejected)
- reviewed_by
- review_note
- created_at
- updated_at

### payments
- id (PK)
- job_id (FK -> freight_jobs.id)
- payer_id (FK -> users.id)
- payee_id (FK -> users.id)
- amount
- currency
- status
- gateway_reference
- created_at
- updated_at

### blog_posts
- id (PK)
- title
- slug
- excerpt
- content
- featured_image
- author_id
- status
- published_at
- created_at
- updated_at

### support_tickets
- id (PK)
- user_id (FK -> users.id)
- subject
- message
- status
- priority
- created_at
- updated_at

## 3. Recommended Indexes

- freight_jobs: shipper_id, status, pickup_location, delivery_location, load_category
- job_quotes: job_id, carrier_id, status
- notifications: user_id, is_read
- messages: conversation_id, created_at
- users: email, role, status

## 4. Data Lifecycle Notes

- Jobs move through statuses as they progress
- Quotes expire if not accepted within a configured period
- Reviews are immutable after submission unless moderated
- Verification documents can be re-submitted after rejection
