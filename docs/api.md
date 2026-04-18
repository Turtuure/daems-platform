# REST API Reference

## Base URL

```
http://daems-platform.local/api/v1
```

All responses are `application/json; charset=utf-8`.

## Authentication

Session-based. The API is consumed exclusively by the `daem-society` front-end application running on the same origin. There is no token header scheme. The `login` endpoint validates credentials and the calling application manages the resulting session. Endpoints that require an authenticated user receive the user ID as a request body field (`user_id`).

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

| Code | Meaning                        |
| ---- | ------------------------------ |
| 200  | OK                             |
| 201  | Created                        |
| 400  | Bad Request (validation failed) |
| 401  | Unauthorized                   |
| 404  | Not Found                      |
| 409  | Conflict (duplicate)           |
| 422  | Unprocessable Entity           |
| 500  | Internal Server Error          |

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
| `name`          | string | yes      | Full display name        |
| `email`         | string | yes      | Must be a valid address  |
| `password`      | string | yes      | Minimum 8 characters     |
| `date_of_birth` | string | yes      | Format `YYYY-MM-DD`      |

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
| 400    | Password shorter than 8 characters       |
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
    "id": "01951234-abcd-7ef0-8abc-0123456789ab",
    "name": "Ada Lovelace",
    "email": "ada@example.com",
    "role": "registered",
    "membership_type": "individual",
    "membership_status": "active",
    "member_number": null
  }
}
```

**Errors**

| Status | Condition                      |
| ------ | ------------------------------ |
| 400    | Email or password is empty     |
| 401    | Invalid credentials            |

---

## Users

### GET /api/v1/users/{id}

Retrieve a user profile.

**Path parameters**

| Parameter | Type   | Description |
| --------- | ------ | ----------- |
| `id`      | UUID7  | User ID     |

**Response 200**

```json
{
  "data": {
    "id": "01951234-abcd-7ef0-8abc-0123456789ab",
    "name": "Ada Lovelace",
    "email": "ada@example.com",
    "date_of_birth": "1990-04-18",
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

**Errors**

| Status | Condition       |
| ------ | --------------- |
| 400    | ID is empty     |
| 404    | User not found  |

---

### POST /api/v1/users/{id}

Update a user's profile fields.

**Path parameters**

| Parameter | Type  | Description |
| --------- | ----- | ----------- |
| `id`      | UUID7 | User ID     |

**Request body** (all fields optional — send only those to change)

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

| Status | Condition                   |
| ------ | --------------------------- |
| 400    | Validation error (message varies) |
| 400    | ID is empty                 |

---

### POST /api/v1/users/{id}/password

Change a user's password.

**Path parameters**

| Parameter | Type  | Description |
| --------- | ----- | ----------- |
| `id`      | UUID7 | User ID     |

**Request body**

| Field              | Type   | Required |
| ------------------ | ------ | -------- |
| `current_password` | string | yes      |
| `new_password`     | string | yes      |
| `confirm_password` | string | yes      |

**Response 200**

```json
{ "data": { "updated": true } }
```

**Errors**

| Status | Condition                               |
| ------ | --------------------------------------- |
| 400    | `new_password` and `confirm_password` do not match |
| 422    | Current password is incorrect           |

---

### GET /api/v1/users/{id}/activity

Retrieve a user's recent activity (forum posts and event registrations).

**Path parameters**

| Parameter | Type  | Description |
| --------- | ----- | ----------- |
| `id`      | UUID7 | User ID     |

**Response 200**

```json
{
  "data": {
    "forum_posts": [...],
    "event_registrations": [...]
  }
}
```

---

### POST /api/v1/users/{id}/delete

Delete a user account and all associated data.

**Path parameters**

| Parameter | Type  | Description |
| --------- | ----- | ----------- |
| `id`      | UUID7 | User ID     |

**Response 200**

```json
{ "data": { "deleted": true } }
```

**Errors**

| Status | Condition      |
| ------ | -------------- |
| 404    | User not found |

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

Register a user for an event. Idempotent — registering twice is not an error.

**Path parameters**

| Parameter | Type   | Description |
| --------- | ------ | ----------- |
| `slug`    | string | Event slug  |

**Request body**

| Field     | Type  | Required |
| --------- | ----- | -------- |
| `user_id` | UUID7 | yes      |

**Response 200**

```json
{ "data": { "participant_count": 43 } }
```

**Errors**

| Status | Condition                      |
| ------ | ------------------------------ |
| 400    | `user_id` is empty             |
| 404    | Event not found                |

---

### POST /api/v1/events/{slug}/unregister

Remove a user's event registration.

**Path parameters**

| Parameter | Type   | Description |
| --------- | ------ | ----------- |
| `slug`    | string | Event slug  |

**Request body**

| Field     | Type  | Required |
| --------- | ----- | -------- |
| `user_id` | UUID7 | yes      |

**Response 200**

```json
{ "data": { "participant_count": 42 } }
```

**Errors**

| Status | Condition              |
| ------ | ---------------------- |
| 400    | `user_id` is empty     |
| 404    | Event or registration not found |

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

Create a new topic in a category.

**Path parameters**

| Parameter | Type   | Description   |
| --------- | ------ | ------------- |
| `slug`    | string | Category slug |

**Request body**

| Field             | Type   | Required | Notes                  |
| ----------------- | ------ | -------- | ---------------------- |
| `title`           | string | yes      |                        |
| `content`         | string | yes      | Body of the first post |
| `author_name`     | string | yes      |                        |
| `user_id`         | UUID7  | no       | Authenticated user     |
| `avatar_initials` | string | no       | Up to 4 characters     |
| `avatar_color`    | string | no       | CSS colour value       |
| `role`            | string | no       | Default: `Member`      |
| `role_class`      | string | no       | Default: `role-member` |
| `joined_text`     | string | no       |                        |

**Response 201**

```json
{ "data": { "slug": "welcome-thread" } }
```

**Errors**

| Status | Condition                            |
| ------ | ------------------------------------ |
| 400    | `title`, `content`, or `author_name` is empty |
| 404    | Category not found                   |

---

### POST /api/v1/forum/topics/{slug}/posts

Add a reply to a topic.

**Path parameters**

| Parameter | Type   | Description |
| --------- | ------ | ----------- |
| `slug`    | string | Topic slug  |

**Request body**

| Field             | Type   | Required | Notes              |
| ----------------- | ------ | -------- | ------------------ |
| `content`         | string | yes      |                    |
| `author_name`     | string | yes      |                    |
| `user_id`         | UUID7  | no       |                    |
| `avatar_initials` | string | no       |                    |
| `avatar_color`    | string | no       |                    |
| `role`            | string | no       | Default: `Member`  |
| `role_class`      | string | no       | Default: `role-member` |
| `joined_text`     | string | no       |                    |

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

| Status | Condition                       |
| ------ | ------------------------------- |
| 400    | `content` or `author_name` is empty |
| 404    | Topic not found                 |

---

### POST /api/v1/forum/posts/{id}/like

Increment the like counter on a post. No authentication required.

**Path parameters**

| Parameter | Type  | Description |
| --------- | ----- | ----------- |
| `id`      | UUID7 | Post ID     |

**Response 200**

```json
{ "data": { "ok": true } }
```

---

### POST /api/v1/forum/topics/{slug}/view

Increment the view counter on a topic.

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

Create a new project.

**Request body**

| Field         | Type   | Required | Notes                     |
| ------------- | ------ | -------- | ------------------------- |
| `title`       | string | yes      |                           |
| `category`    | string | yes      |                           |
| `icon`        | string | no       | Bootstrap Icons class; default `bi-folder` |
| `summary`     | string | yes      |                           |
| `description` | string | yes      |                           |
| `status`      | string | no       | Default `active`          |

**Response 201**

```json
{ "data": { "slug": "open-data-africa", "id": "..." } }
```

**Errors**

| Status | Condition           |
| ------ | ------------------- |
| 422    | Validation failed   |

---

### POST /api/v1/projects/{slug}

Update an existing project.

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

| Status | Condition         |
| ------ | ----------------- |
| 404    | Project not found |

---

### POST /api/v1/projects/{slug}/archive

Archive a project (sets status to `archived`).

**Path parameters**

| Parameter | Type   | Description  |
| --------- | ------ | ------------ |
| `slug`    | string | Project slug |

**Response 200**

```json
{ "data": { "ok": true } }
```

**Errors**

| Status | Condition         |
| ------ | ----------------- |
| 404    | Project not found |

---

### POST /api/v1/projects/{slug}/join

Add an authenticated user as a project participant.

**Path parameters**

| Parameter | Type   | Description  |
| --------- | ------ | ------------ |
| `slug`    | string | Project slug |

**Request body**

| Field     | Type  | Required |
| --------- | ----- | -------- |
| `user_id` | UUID7 | yes      |

**Response 200**

```json
{ "data": { "ok": true } }
```

**Errors**

| Status | Condition                          |
| ------ | ---------------------------------- |
| 422    | Project not found or already member |

---

### POST /api/v1/projects/{slug}/leave

Remove an authenticated user from a project.

**Path parameters**

| Parameter | Type   | Description  |
| --------- | ------ | ------------ |
| `slug`    | string | Project slug |

**Request body**

| Field     | Type  | Required |
| --------- | ----- | -------- |
| `user_id` | UUID7 | yes      |

**Response 200**

```json
{ "data": { "ok": true } }
```

**Errors**

| Status | Condition        |
| ------ | ---------------- |
| 422    | Not a member     |

---

### POST /api/v1/projects/{slug}/comments

Post a comment on a project.

**Path parameters**

| Parameter | Type   | Description  |
| --------- | ------ | ------------ |
| `slug`    | string | Project slug |

**Request body**

| Field             | Type   | Required |
| ----------------- | ------ | -------- |
| `user_id`         | UUID7  | yes      |
| `author_name`     | string | yes      |
| `avatar_initials` | string | no       |
| `avatar_color`    | string | no       |
| `content`         | string | yes      |

**Response 201**

```json
{
  "data": {
    "id": "...",
    "author_name": "Ada Lovelace",
    "content": "Great work!",
    "likes": 0,
    "created_at": "2025-04-18 12:00:00"
  }
}
```

**Errors**

| Status | Condition                    |
| ------ | ---------------------------- |
| 422    | Missing required fields      |

---

### POST /api/v1/project-comments/{id}/like

Increment the like counter on a project comment.

**Path parameters**

| Parameter | Type  | Description |
| --------- | ----- | ----------- |
| `id`      | UUID7 | Comment ID  |

**Response 200**

```json
{ "data": { "ok": true } }
```

---

### POST /api/v1/projects/{slug}/updates

Publish a project update (mini-blog entry).

**Path parameters**

| Parameter | Type   | Description  |
| --------- | ------ | ------------ |
| `slug`    | string | Project slug |

**Request body**

| Field         | Type   | Required |
| ------------- | ------ | -------- |
| `title`       | string | yes      |
| `content`     | string | yes      |
| `author_name` | string | yes      |

**Response 201**

```json
{ "data": { "ok": true } }
```

**Errors**

| Status | Condition               |
| ------ | ----------------------- |
| 422    | Missing required fields |

---

### POST /api/v1/project-proposals

Submit a community project proposal.

**Request body**

| Field         | Type   | Required |
| ------------- | ------ | -------- |
| `user_id`     | UUID7  | yes      |
| `author_name` | string | yes      |
| `author_email`| string | yes      |
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

Submit an individual membership application.

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
{ "data": { "ok": true } }
```

---

### POST /api/v1/applications/supporter

Submit an organisational supporter application.

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
