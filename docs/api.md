# REST API Reference

## Base URL

```
http://daems-platform.local/api/v1
```

All responses are `application/json; charset=utf-8`.

## Authentication

**Opaque bearer tokens.** Login issues a 32-byte random token; the server stores only `SHA-256(token)`. Attach to every protected request:

```
Authorization: Bearer <token>
```

**Token lifetime.** 7-day sliding expiry with a 30-day hard cap. Every authenticated request advances `expires_at` to `LEAST(now + 7d, issued_at + 30d)`. Inactive sessions die in 7 days; active users stay logged in up to 30 days without re-authentication.

**Logout.** `POST /api/v1/auth/logout` (authenticated) revokes the caller's token.

**Login rate limit.** 5 failures per `(ip, email)` per 15 minutes triggers HTTP 429 + `Retry-After: 900`.

### X-Daems-Tenant header

Platform administrators can override the active tenant context on any authenticated request by sending `X-Daems-Tenant: <tenant-slug>`. The API then treats the request as if it came from the override tenant's domain.

- Sent by non-platform-admin: `403 Forbidden` with `{"error": "tenant_override_forbidden"}`
- Sent with an unknown slug: `404 Not Found` with `{"error": "unknown_tenant"}`
- Omitted: tenant is resolved from the `Host` header (normal case)

**Data scope:** every list/detail endpoint (`/projects`, `/events`, `/insights`, `/forum/*`, `/backstage/stats`, etc.) returns only rows where `tenant_id` matches the resolved tenant. Attempting to fetch a resource by slug from a tenant that doesn't own it returns `404` — this is enforced at the repository layer (`*ForTenant` methods) and verified by `tests/Isolation/*TenantIsolationTest.php`.

Example:
```http
GET /api/v1/backstage/stats HTTP/1.1
Host: daems.fi
Authorization: Bearer <gsa-token>
X-Daems-Tenant: sahegroup
```
Response contains `sahegroup`-scoped stats.

## Response envelope

**Success**

```json
{ "data": { ... } }
```

**Error**

```json
{ "error": "Human-readable message" }
```

## Status codes

| Code | Meaning                              |
| ---- | ------------------------------------ |
| 200  | OK                                   |
| 201  | Created                              |
| 204  | No Content (logout)                  |
| 400  | Bad Request (validation failed)      |
| 401  | Unauthorized (missing/invalid token) |
| 403  | Forbidden (policy violation)         |
| 404  | Not Found                            |
| 409  | Conflict (duplicate)                 |
| 422  | Unprocessable Entity                 |
| 429  | Too Many Requests (rate limit)       |
| 500  | Internal Server Error                |

## Error sanitisation

500-class responses always return `{"error":"Internal server error."}`. Runtime exception details are logged via `LoggerInterface` but never surface to the HTTP client. Set `APP_DEBUG=true` to enable verbose 500 bodies during local development.

---

## Health

### GET /api/v1/status

Returns the API version and a liveness indicator.

**Response 200**

```json
{
  "data": {
    "status": "ok",
    "version": "1.0.0"
  }
}
```

---

## Auth

### POST /api/v1/auth/register

Register a new user account.

**Request body**

| Field           | Type   | Required | Notes                    |
| --------------- | ------ | -------- | ------------------------ |
| `name`          | string | yes      | Full display name                    |
| `email`         | string | yes      | Must be a valid address              |
| `password`      | string | yes      | 8–72 bytes (bcrypt truncates beyond) |
| `date_of_birth` | string | yes      | Format `YYYY-MM-DD`                  |

```json
{
  "name": "Ada Lovelace",
  "email": "ada@example.com",
  "password": "securepass",
  "date_of_birth": "1990-04-18"
}
```

**Response 201**

```json
{
  "data": {
    "id": "01951234-abcd-7ef0-8abc-0123456789ab"
  }
}
```

**Errors**

| Status | Condition                                |
| ------ | ---------------------------------------- |
| 400    | Any required field is empty              |
| 400    | Email format is invalid                  |
| 400    | Password shorter than 8 bytes            |
| 400    | Password longer than 72 bytes            |
| 409    | Email address already registered         |

---

### POST /api/v1/auth/login

Authenticate an existing user.

**Request body**

| Field      | Type   | Required |
| ---------- | ------ | -------- |
| `email`    | string | yes      |
| `password` | string | yes      |

```json
{
  "email": "ada@example.com",
  "password": "securepass"
}
```

**Response 200**

```json
{
  "data": {
    "user": {
      "id": "01951234-abcd-7ef0-8abc-0123456789ab",
      "name": "Ada Lovelace",
      "email": "ada@example.com",
      "role": "registered",
      "membership_type": "individual",
      "membership_status": "active",
      "member_number": null
    },
    "token": "pGq7Kx-abc123...",
    "expires_at": "2026-04-26T12:00:00+00:00"
  }
}
```

**Errors**

| Status | Condition                                                             |
| ------ | --------------------------------------------------------------------- |
| 400    | Email or password is empty                                            |
| 401    | Invalid credentials                                                   |
| 429    | Rate-limited after 5 failures per `(ip, email)` in 15 minutes         |

---

### POST /api/v1/auth/logout

Revoke the caller's token. Requires `Authorization: Bearer <token>`.

**Response 204:** empty body.
**401** if the token is missing, malformed, revoked, or expired.

---

### GET /api/v1/auth/me

Returns the authenticated user's identity, their active tenant, their role in that tenant, and the bearer token's expiry. Used by frontend clients to validate tokens on load and to drive tenant-scoped UI.

**Headers:**
- `Authorization: Bearer <token>` (required)
- `X-Daems-Tenant: <slug>` (optional, platform admins only)

**Response 200**

```json
{
    "data": {
        "user": {
            "id": "01958000-...",
            "name": "Sam",
            "email": "sam@example.com",
            "is_platform_admin": false
        },
        "tenant": {
            "slug": "daems",
            "name": "Daems Society"
        },
        "role_in_tenant": "admin",
        "token_expires_at": "2026-04-26T10:00:00+00:00"
    }
}
```

**Errors**

| Status | Condition |
| ------ | --------- |
| 401    | Missing or invalid token |
| 404    | `Host` doesn't match any tenant |
| 403    | `X-Daems-Tenant` set by non-platform-admin |

---

## Users

### GET /api/v1/users/{id}

Retrieve a user profile. **Requires authentication.**

**Path parameters**

| Parameter | Type   | Description |
| --------- | ------ | ----------- |
| `id`      | UUID7  | User ID     |

**Response shape depends on caller identity.**

Self (`{id}` matches caller's user id) or caller with `role=admin` — full profile:

```json
{
  "data": {
    "id": "01951234-abcd-7ef0-8abc-0123456789ab",
    "name": "Ada Lovelace",
    "first_name": "Ada",
    "last_name": "Lovelace",
    "email": "ada@example.com",
    "dob": "1990-04-18",
    "role": "registered",
    "country": "FI",
    "address_street": "Mannerheimintie 1",
    "address_zip": "00100",
    "address_city": "Helsinki",
    "address_country": "Finland",
    "membership_type": "individual",
    "membership_status": "active",
    "member_number": "M-0042",
    "created_at": "2024-01-15 10:30:00"
  }
}
```

Any other authenticated caller — reduced public view (name only, no PII):

```json
{
  "data": {
    "id": "01951234-abcd-7ef0-8abc-0123456789ab",
    "name": "Ada Lovelace"
  }
}
```

**Errors**

| Status | Condition                            |
| ------ | ------------------------------------ |
| 400    | ID is empty                          |
| 401    | Missing / invalid `Authorization`    |
| 404    | User not found                       |

---

### POST /api/v1/users/{id}

Update a user's profile fields. **Requires authentication.** Only the user themselves or an admin can update.

**Path parameters**

| Parameter | Type  | Description |
| --------- | ----- | ----------- |
| `id`      | UUID7 | User ID     |

**Request body** — all fields optional. Omitted fields are left unchanged; an explicit empty string sets the field to empty.

| Field             | Type   |
| ----------------- | ------ |
| `first_name`      | string |
| `last_name`       | string |
| `email`           | string |
| `dob`             | string |
| `country`         | string |
| `address_street`  | string |
| `address_zip`     | string |
| `address_city`    | string |
| `address_country` | string |

**Response 200**

```json
{ "data": { "updated": true } }
```

**Errors**

| Status | Condition                                  |
| ------ | ------------------------------------------ |
| 400    | ID is empty, `first_name` empty, email invalid, or duplicate email (generic "Invalid email.") |
| 401    | Missing / invalid `Authorization`          |
| 403    | Caller is neither self nor admin           |

---

### POST /api/v1/users/{id}/password

Change a user's password. **Requires authentication. Self-only** — admins cannot reset another user's password through this endpoint.

**Path parameters**

| Parameter | Type  | Description |
| --------- | ----- | ----------- |
| `id`      | UUID7 | User ID     |

**Request body**

| Field              | Type   | Required | Notes                                 |
| ------------------ | ------ | -------- | ------------------------------------- |
| `current_password` | string | yes      |                                       |
| `new_password`     | string | yes      | 8–72 bytes (bcrypt truncates beyond)  |
| `confirm_password` | string | yes      |                                       |

**Response 200**

```json
{ "data": { "updated": true } }
```

**Errors**

| Status | Condition                                                |
| ------ | -------------------------------------------------------- |
| 400    | `new_password` and `confirm_password` do not match       |
| 401    | Missing / invalid `Authorization`                        |
| 403    | Caller is not the target user (admins cannot override)   |
| 422    | New password too short (<8 bytes) or too long (>72 bytes) |
| 422    | Current password is incorrect                            |

---

### GET /api/v1/users/{id}/activity

Retrieve a user's recent activity (forum posts and event registrations). **Requires authentication.** Self-or-admin only.

**Path parameters**

| Parameter | Type  | Description |
| --------- | ----- | ----------- |
| `id`      | UUID7 | User ID     |

**Response 200**

```json
{
  "data": {
    "forum_posts": 3,
    "recent_posts": [...],
    "events_attended": 1,
    "attended_events": [...]
  }
}
```

**Errors**

| Status | Condition                         |
| ------ | --------------------------------- |
| 400    | ID is empty                       |
| 401    | Missing / invalid `Authorization` |
| 403    | Caller is neither self nor admin  |

---

### POST /api/v1/users/{id}/delete

Delete a user account and all associated data. **Requires authentication.** Self or admin.

**Path parameters**

| Parameter | Type  | Description |
| --------- | ----- | ----------- |
| `id`      | UUID7 | User ID     |

**Response 200**

```json
{ "data": { "deleted": true } }
```

**Errors**

| Status | Condition                         |
| ------ | --------------------------------- |
| 400    | ID is empty                       |
| 401    | Missing / invalid `Authorization` |
| 403    | Caller is neither self nor admin  |
| 404    | User not found                    |

---

## Events

### GET /api/v1/events

List all events, optionally filtered by type.

**Query parameters**

| Parameter | Type   | Required | Values                        |
| --------- | ------ | -------- | ----------------------------- |
| `type`    | string | no       | `upcoming`, `past`, `online`  |

**Response 200**

```json
{
  "data": [
    {
      "id": "01951234-abcd-7ef0-8abc-0123456789ab",
      "slug": "annual-summit-2025",
      "title": "Annual Summit 2025",
      "type": "upcoming",
      "date": "2025-09-15",
      "time": "10:00",
      "location": "Helsinki",
      "online": false,
      "description": "...",
      "hero_image": "/images/summit.jpg",
      "gallery": []
    }
  ]
}
```

---

### GET /api/v1/events/{slug}

Retrieve a single event with registration status for a given user.

**Path parameters**

| Parameter | Type   | Description  |
| --------- | ------ | ------------ |
| `slug`    | string | Event slug   |

**Query parameters**

| Parameter | Type  | Required | Description                             |
| --------- | ----- | -------- | --------------------------------------- |
| `user_id` | UUID7 | no       | When provided, includes `is_registered` |

**Response 200**

```json
{
  "data": {
    "id": "01951234-abcd-7ef0-8abc-0123456789ab",
    "slug": "annual-summit-2025",
    "title": "Annual Summit 2025",
    "type": "upcoming",
    "date": "2025-09-15",
    "time": "10:00",
    "location": "Helsinki",
    "online": false,
    "description": "...",
    "hero_image": "/images/summit.jpg",
    "gallery": [],
    "participant_count": 42,
    "is_registered": false
  }
}
```

**Errors**

| Status | Condition        |
| ------ | ---------------- |
| 404    | Event not found  |

---

### POST /api/v1/events/{slug}/register

Register the authenticated user for an event. Idempotent — registering twice is not an error. **Requires authentication.** The registrant is always the authenticated user; there is no `user_id` body field.

**Path parameters**

| Parameter | Type   | Description |
| --------- | ------ | ----------- |
| `slug`    | string | Event slug  |

**Request body** — empty.

**Response 200**

```json
{ "data": { "participant_count": 43 } }
```

**Errors**

| Status | Condition                         |
| ------ | --------------------------------- |
| 401    | Missing / invalid `Authorization` |
| 404    | Event not found                   |

---

### POST /api/v1/events/{slug}/unregister

Remove the authenticated user's event registration. **Requires authentication.**

**Path parameters**

| Parameter | Type   | Description |
| --------- | ------ | ----------- |
| `slug`    | string | Event slug  |

**Request body** — empty.

**Response 200**

```json
{ "data": { "participant_count": 42 } }
```

**Errors**

| Status | Condition                         |
| ------ | --------------------------------- |
| 401    | Missing / invalid `Authorization` |
| 404    | Event or registration not found   |

---

## Forum

### GET /api/v1/forum/categories

List all forum categories.

**Response 200**

```json
{
  "data": [
    {
      "id": "01951234-abcd-7ef0-8abc-000000000001",
      "slug": "general",
      "name": "General Discussion",
      "icon": "bi-chat",
      "description": "Open conversations about the society.",
      "sort_order": 0,
      "topic_count": 12
    }
  ]
}
```

---

### GET /api/v1/forum/categories/{slug}

Retrieve a category with its list of topics.

**Path parameters**

| Parameter | Type   | Description    |
| --------- | ------ | -------------- |
| `slug`    | string | Category slug  |

**Response 200**

```json
{
  "data": {
    "category": { "slug": "general", "name": "General Discussion", "icon": "bi-chat", "description": "..." },
    "topics": [
      {
        "id": "...",
        "slug": "welcome-thread",
        "title": "Welcome thread",
        "author_name": "Ada Lovelace",
        "avatar_initials": "AL",
        "avatar_color": "#3b82f6",
        "pinned": false,
        "reply_count": 5,
        "view_count": 120,
        "last_activity_at": "2025-04-10 14:22:00",
        "last_activity_by": "Ada Lovelace"
      }
    ]
  }
}
```

**Errors**

| Status | Condition          |
| ------ | ------------------ |
| 404    | Category not found |

---

### GET /api/v1/forum/topics/{slug}

Retrieve a topic thread with all its posts.

**Path parameters**

| Parameter | Type   | Description |
| --------- | ------ | ----------- |
| `slug`    | string | Topic slug  |

**Response 200**

```json
{
  "data": {
    "topic": {
      "id": "...",
      "slug": "welcome-thread",
      "title": "Welcome thread",
      "author_name": "Ada Lovelace",
      "created_at": "2025-01-10 09:00:00",
      "reply_count": 2,
      "view_count": 121
    },
    "posts": [
      {
        "id": "...",
        "author_name": "Ada Lovelace",
        "avatar_initials": "AL",
        "avatar_color": "#3b82f6",
        "role": "Member",
        "role_class": "role-member",
        "joined_text": "Member since 2024",
        "content": "Hello everyone!",
        "likes": 3,
        "created_at": "2025-01-10 09:00:00"
      }
    ]
  }
}
```

**Errors**

| Status | Condition       |
| ------ | --------------- |
| 404    | Topic not found |

---

### POST /api/v1/forum/categories/{slug}/topics

Create a new topic in a category. **Requires authentication.** Author identity (user_id, author_name, avatar, role badge, join date) is derived server-side from the authenticated user's record — attempts to supply these fields in the body are silently ignored.

**Path parameters**

| Parameter | Type   | Description   |
| --------- | ------ | ------------- |
| `slug`    | string | Category slug |

**Request body**

| Field     | Type   | Required | Notes                  |
| --------- | ------ | -------- | ---------------------- |
| `title`   | string | yes      |                        |
| `content` | string | yes      | Body of the first post |

**Response 201**

```json
{ "data": { "slug": "welcome-thread" } }
```

**Errors**

| Status | Condition                         |
| ------ | --------------------------------- |
| 400    | `title` or `content` is empty     |
| 401    | Missing / invalid `Authorization` |
| 404    | Category not found                |

---

### POST /api/v1/forum/topics/{slug}/posts

Add a reply to a topic. **Requires authentication.** Author identity (user_id, author_name, avatar, role badge, join date) is derived server-side from the authenticated user's record — attempts to supply these fields in the body are silently ignored.

**Path parameters**

| Parameter | Type   | Description |
| --------- | ------ | ----------- |
| `slug`    | string | Topic slug  |

**Request body**

| Field     | Type   | Required |
| --------- | ------ | -------- |
| `content` | string | yes      |

**Response 201**

```json
{
  "data": {
    "id": "...",
    "author_name": "Ada Lovelace",
    "content": "Great thread!",
    "likes": 0,
    "created_at": "2025-04-18 12:00:00"
  }
}
```

**Errors**

| Status | Condition                         |
| ------ | --------------------------------- |
| 400    | `content` is empty                |
| 401    | Missing / invalid `Authorization` |
| 404    | Topic not found                   |

---

### POST /api/v1/forum/posts/{id}/like

Increment the like counter on a post. **Requires authentication.**

**Path parameters**

| Parameter | Type  | Description |
| --------- | ----- | ----------- |
| `id`      | UUID7 | Post ID     |

**Response 200**

```json
{ "data": { "ok": true } }
```

**Errors**

| Status | Condition                         |
| ------ | --------------------------------- |
| 401    | Missing / invalid `Authorization` |

---

### POST /api/v1/forum/topics/{slug}/view

Increment the view counter on a topic. Public — no authentication required (used for anonymous view tracking).

**Path parameters**

| Parameter | Type   | Description |
| --------- | ------ | ----------- |
| `slug`    | string | Topic slug  |

**Response 200**

```json
{ "data": { "ok": true } }
```

---

## Projects

### GET /api/v1/projects

List projects with optional filters.

**Query parameters**

| Parameter  | Type   | Required | Description                  |
| ---------- | ------ | -------- | ---------------------------- |
| `category` | string | no       | Filter by category slug      |
| `status`   | string | no       | `active`, `archived`, etc.   |
| `search`   | string | no       | Full-text search on title    |

**Response 200**

```json
{
  "data": [
    {
      "id": "...",
      "slug": "open-data-africa",
      "title": "Open Data Africa",
      "category": "technology",
      "icon": "bi-database",
      "summary": "Building open data pipelines across the continent.",
      "status": "active",
      "sort_order": 0,
      "created_at": "2024-06-01 08:00:00"
    }
  ]
}
```

---

### GET /api/v1/projects/{slug}

Retrieve a single project with participants, comments, and updates.

**Path parameters**

| Parameter | Type   | Description   |
| --------- | ------ | ------------- |
| `slug`    | string | Project slug  |

**Query parameters**

| Parameter | Type  | Required | Description                              |
| --------- | ----- | -------- | ---------------------------------------- |
| `user_id` | UUID7 | no       | When provided, includes `is_member` flag |

**Response 200**

```json
{
  "data": {
    "id": "...",
    "slug": "open-data-africa",
    "title": "Open Data Africa",
    "category": "technology",
    "icon": "bi-database",
    "summary": "...",
    "description": "...",
    "status": "active",
    "participants": [...],
    "comments": [...],
    "updates": [...],
    "is_member": false
  }
}
```

**Errors**

| Status | Condition         |
| ------ | ----------------- |
| 404    | Project not found |

---

### POST /api/v1/projects

Create a new project. **Requires authentication.** `owner_id` is set from the authenticated user.

**Request body**

| Field         | Type   | Required | Notes                                       |
| ------------- | ------ | -------- | ------------------------------------------- |
| `title`       | string | yes      |                                             |
| `category`    | string | yes      |                                             |
| `icon`        | string | no       | Bootstrap Icons class; default `bi-folder`  |
| `summary`     | string | yes      |                                             |
| `description` | string | yes      |                                             |
| `status`      | string | no       | Default `active`                            |

**Response 201**

```json
{ "data": { "slug": "open-data-africa", "id": "..." } }
```

**Errors**

| Status | Condition                         |
| ------ | --------------------------------- |
| 401    | Missing / invalid `Authorization` |
| 422    | Validation failed                 |

---

### POST /api/v1/projects/{slug}

Update an existing project. **Requires authentication.** Only the project owner or an admin may update. Legacy rows with `owner_id IS NULL` are admin-only.

**Path parameters**

| Parameter | Type   | Description  |
| --------- | ------ | ------------ |
| `slug`    | string | Project slug |

**Request body** — same fields as create.

**Response 200**

```json
{ "data": { "ok": true } }
```

**Errors**

| Status | Condition                                  |
| ------ | ------------------------------------------ |
| 401    | Missing / invalid `Authorization`          |
| 403    | Caller is neither project owner nor admin  |
| 404    | Project not found                          |

---

### POST /api/v1/projects/{slug}/archive

Archive a project (sets status to `archived`). **Requires authentication.** Same owner-or-admin policy as update.

**Path parameters**

| Parameter | Type   | Description  |
| --------- | ------ | ------------ |
| `slug`    | string | Project slug |

**Request body** — empty.

**Response 200**

```json
{ "data": { "ok": true } }
```

**Errors**

| Status | Condition                                  |
| ------ | ------------------------------------------ |
| 401    | Missing / invalid `Authorization`          |
| 403    | Caller is neither project owner nor admin  |
| 404    | Project not found                          |

---

### POST /api/v1/projects/{slug}/join

Add the authenticated user as a project participant. **Requires authentication.** Participant id is always the authenticated user.

**Path parameters**

| Parameter | Type   | Description  |
| --------- | ------ | ------------ |
| `slug`    | string | Project slug |

**Request body** — empty.

**Response 200**

```json
{ "data": { "ok": true } }
```

**Errors**

| Status | Condition                           |
| ------ | ----------------------------------- |
| 401    | Missing / invalid `Authorization`   |
| 422    | Project not found or already member |

---

### POST /api/v1/projects/{slug}/leave

Remove the authenticated user from a project. **Requires authentication.** Only the caller's own participation can be removed — attempts to pass another user's id are ignored.

**Path parameters**

| Parameter | Type   | Description  |
| --------- | ------ | ------------ |
| `slug`    | string | Project slug |

**Request body** — empty.

**Response 200**

```json
{ "data": { "ok": true } }
```

**Errors**

| Status | Condition                         |
| ------ | --------------------------------- |
| 401    | Missing / invalid `Authorization` |

---

### POST /api/v1/projects/{slug}/comments

Post a comment on a project. **Requires authentication.** Author identity is derived server-side — supplying `user_id` or `author_name` in the body is silently ignored.

**Path parameters**

| Parameter | Type   | Description  |
| --------- | ------ | ------------ |
| `slug`    | string | Project slug |

**Request body**

| Field     | Type   | Required |
| --------- | ------ | -------- |
| `content` | string | yes      |

**Response 201**

```json
{
  "data": {
    "id": "...",
    "author": "Ada Lovelace",
    "content": "Great work!",
    "likes": 0,
    "timestamp": "April 18, 2025, 12:00"
  }
}
```

**Errors**

| Status | Condition                         |
| ------ | --------------------------------- |
| 401    | Missing / invalid `Authorization` |
| 422    | Project not found                 |

---

### POST /api/v1/project-comments/{id}/like

Increment the like counter on a project comment. **Requires authentication.**

**Path parameters**

| Parameter | Type  | Description |
| --------- | ----- | ----------- |
| `id`      | UUID7 | Comment ID  |

**Response 200**

```json
{ "data": { "ok": true } }
```

**Errors**

| Status | Condition                         |
| ------ | --------------------------------- |
| 401    | Missing / invalid `Authorization` |

---

### POST /api/v1/projects/{slug}/updates

Publish a project update (mini-blog entry). **Requires authentication.** Only the project owner or an admin may post. `author_name` is derived server-side from the caller.

**Path parameters**

| Parameter | Type   | Description  |
| --------- | ------ | ------------ |
| `slug`    | string | Project slug |

**Request body**

| Field     | Type   | Required |
| --------- | ------ | -------- |
| `title`   | string | yes      |
| `content` | string | yes      |

**Response 201**

```json
{ "data": { "ok": true } }
```

**Errors**

| Status | Condition                                  |
| ------ | ------------------------------------------ |
| 401    | Missing / invalid `Authorization`          |
| 403    | Caller is neither project owner nor admin  |
| 422    | Project not found                          |

---

### POST /api/v1/project-proposals

Submit a community project proposal. **Requires authentication.** Proposer identity (user_id, author_name, author_email) is derived server-side — attempts to supply these in the body are silently ignored.

**Request body**

| Field         | Type   | Required |
| ------------- | ------ | -------- |
| `title`       | string | yes      |
| `category`    | string | yes      |
| `summary`     | string | yes      |
| `description` | string | yes      |

**Response 201**

```json
{ "data": { "ok": true } }
```

**Errors**

| Status | Condition               |
| ------ | ----------------------- |
| 422    | Missing required fields |

---

## Insights

### GET /api/v1/insights

List insight articles, optionally filtered by category.

**Query parameters**

| Parameter  | Type   | Required | Description          |
| ---------- | ------ | -------- | -------------------- |
| `category` | string | no       | Filter by category   |

**Response 200**

```json
{
  "data": [
    {
      "id": "...",
      "slug": "open-source-governance",
      "title": "Open Source Governance",
      "category": "technology",
      "category_label": "Technology",
      "featured": true,
      "published_date": "2025-03-01",
      "author": "Tuure Turunen",
      "reading_time": 5,
      "excerpt": "...",
      "hero_image": "/images/oss.jpg",
      "tags": ["open source", "governance"]
    }
  ]
}
```

---

### GET /api/v1/insights/{slug}

Retrieve a single insight article including full content.

**Path parameters**

| Parameter | Type   | Description   |
| --------- | ------ | ------------- |
| `slug`    | string | Insight slug  |

**Response 200**

```json
{
  "data": {
    "id": "...",
    "slug": "open-source-governance",
    "title": "Open Source Governance",
    "category": "technology",
    "category_label": "Technology",
    "featured": true,
    "published_date": "2025-03-01",
    "author": "Tuure Turunen",
    "reading_time": 5,
    "excerpt": "...",
    "hero_image": "/images/oss.jpg",
    "tags": ["open source", "governance"],
    "content": "<full HTML or markdown content>"
  }
}
```

**Errors**

| Status | Condition         |
| ------ | ----------------- |
| 404    | Insight not found |

---

## Applications

### POST /api/v1/applications/member

Submit an individual membership application. **Requires authentication.**

**Request body**

| Field           | Type   | Required | Notes                              |
| --------------- | ------ | -------- | ---------------------------------- |
| `name`          | string | yes      |                                    |
| `email`         | string | yes      |                                    |
| `date_of_birth` | string | yes      | Format `YYYY-MM-DD`                |
| `country`       | string | no       |                                    |
| `motivation`    | string | yes      |                                    |
| `how_heard`     | string | no       | e.g. `social`, `friend`, `event`   |

**Response 201**

```json
{ "data": { "id": "..." } }
```

**Errors**

| Status | Condition                                         |
| ------ | ------------------------------------------------- |
| 400    | Missing required fields or invalid email          |
| 401    | Missing / invalid `Authorization`                 |

---

### POST /api/v1/applications/supporter

Submit an organisational supporter application. **Requires authentication.**

**Request body**

| Field            | Type   | Required |
| ---------------- | ------ | -------- |
| `org_name`       | string | yes      |
| `contact_person` | string | yes      |
| `reg_no`         | string | no       |
| `email`          | string | yes      |
| `country`        | string | no       |
| `motivation`     | string | yes      |
| `how_heard`      | string | no       |

**Response 201**

```json
{ "data": { "ok": true } }
```

---

## Error codes

| HTTP | Error key | When |
| ---- | --------- | ---- |
| 404  | `unknown_tenant` | `Host` doesn't match a registered tenant, or `X-Daems-Tenant` names a slug that doesn't exist |
| 401  | `missing_token` | No `Authorization: Bearer ...` header |
| 401  | `invalid_token` | Token not found, expired, or revoked |
| 403  | `tenant_override_forbidden` | `X-Daems-Tenant` sent by a non-platform-admin |
| 403  | `not_a_member` | Authenticated user has no active `user_tenants` row for the current tenant (raised by tenant-scoped use cases when they require membership) |
| 403  | `insufficient_role` | Authenticated user is a member but lacks the specific role required (e.g. admin-only action as regular member) |

## Backstage — admin endpoints

All backstage endpoints require `TenantContextMiddleware` + `AuthMiddleware`. Use cases enforce authorization:
- List / read / decide applications: `ActingUser::isAdminIn(activeTenant)` required
- List / audit members: `ActingUser::isAdminIn(activeTenant)` required
- **Change member status:** `ActingUser::isPlatformAdmin` required (GSA only)

### GET /api/v1/backstage/applications/pending

Query params: `limit` (default 200, max 500).

Response 200:
```json
{"data": {"member": [...], "supporter": [...]}}
```

### POST /api/v1/backstage/applications/{type}/{id}/decision

`type` ∈ `{member, supporter}`. Body: `{"decision": "approved"|"rejected", "note": "..."}`.

Response 200: `{"data": {"success": true}}`
403 `forbidden`, 404 `not_found`, 422 `validation_failed` on invalid decision.

### GET /api/v1/backstage/members

Query params: `status`, `type`, `q`, `sort` (`member_number|name|joined_at|status`), `dir` (`ASC|DESC`), `page`, `per_page` (max 200), `export=csv`.

Response 200:
```json
{"data": [...], "meta": {"page": 1, "per_page": 50, "total": 127, "total_pages": 3}}
```

With `export=csv`: `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="members-<tenant>-<date>.csv"`.

### POST /api/v1/backstage/members/{id}/status (GSA only)

Body: `{"status": "active|inactive|suspended|cancelled", "reason": "..."}`.

Response 200: `{"data": {"success": true}}`
403 `forbidden` for non-GSA, 422 `validation_failed` on invalid status/empty reason.

### GET /api/v1/backstage/members/{id}/audit

Query: `limit` (default 25, max 500).

Response 200:
```json
{"data": [{"id": "...", "previousStatus": "active", "newStatus": "suspended", "reason": "...", "performedByName": "...", "createdAt": "..."}]}
```
