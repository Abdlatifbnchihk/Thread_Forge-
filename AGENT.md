# AGENT.md — ThreadForge API

> **This file is the single source of truth for the ThreadForge project.**
> It describes the architecture, every database table, every endpoint, every file,
> and maps each piece of work to its Jira ticket and GitHub branch.
> Any developer (or AI agent) working on this codebase should read this file first.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Tech Stack](#2-tech-stack)
3. [Architecture](#3-architecture)
4. [Database Design](#4-database-design)
5. [Authentication Flow](#5-authentication-flow)
6. [API Endpoints Reference](#6-api-endpoints-reference)
7. [File Structure](#7-file-structure)
8. [Jira Epics & Tickets](#8-jira-epics--tickets)
9. [GitHub Branching Strategy](#9-github-branching-strategy)
10. [Environment & Setup](#10-environment--setup)
11. [Queue & Async Jobs](#11-queue--async-jobs)
12. [AI Layer](#12-ai-layer)
13. [Error Handling Contract](#13-error-handling-contract)
14. [Policies & Authorization](#14-policies--authorization)
15. [5-Day Sprint Plan](#15-5-day-sprint-plan)

---

## 1. Project Overview

**ThreadForge** is a headless REST API built with Laravel. Its purpose is to help tech content creators
transform raw developer notes, blog posts, and GitHub READMEs into optimized posts for X (Twitter).

The system is built around three core concepts:

| Concept | What it is |
|---|---|
| **Campaign Blueprint** | A reusable style configuration (tone, character limit, hashtag rules, forbidden words). Think of it as a writing rulebook. |
| **Raw Content** | The unformatted input submitted by the creator — notes, markdown, a brain dump. |
| **Generated Post** | The AI-processed output: a structured post with a hook, body points, hashtags, and a readability score. |

A fourth concept — the **Ghostwriter Agent** — lets the user have a contextual conversation
about any generated post, with the AI calling real PHP tools to fetch data rather than hallucinating.

### What this project is NOT
- It is not a frontend application. No Blade views, no sessions, no cookies.
- It does not post to X directly. It prepares the content; the creator posts manually.
- It does not use a third-party content scheduling SaaS (no Taplio, no Buffer).

---

## 2. Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.3+ |
| Framework | Laravel 11 |
| Authentication | Laravel Sanctum (Bearer tokens) |
| Database | MySQL 8+ |
| Queue driver | Database (dev) / Redis (production) |
| AI provider | xAI Grok API (`grok-3` model) |
| HTTP client | Laravel `Http` facade (Guzzle) |
| API format | JSON — all responses use Laravel API Resources |
| Validation | Laravel Form Requests (422 on failure) |
| Authorization | Laravel Policies |

---

## 3. Architecture

```
Client (Postman / Frontend / Mobile)
        │
        │  HTTP  Authorization: Bearer {token}
        ▼
┌─────────────────────────────────────┐
│           routes/api.php            │  All routes live here. No web.php used.
└─────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────┐
│       auth:sanctum middleware       │  Validates Bearer token on every protected route.
└─────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────┐
│           Controllers               │  Thin. Validate → Authorize → Delegate → Respond.
│  AuthController                     │
│  CampaignBlueprintController        │
│  GeneratedPostController            │
│  GhostwriterController              │
│  RawContentController               │
└─────────────────────────────────────┘
        │                   │
        ▼                   ▼
┌──────────────┐   ┌─────────────────────────────┐
│   Models /   │   │        Jobs / Services       │
│   Eloquent   │   │  GeneratePostJob (async)     │
│              │   │  GhostwriterService (agent)  │
└──────────────┘   └─────────────────────────────┘
        │                   │
        ▼                   ▼
┌─────────────────────────────────────┐
│              MySQL                  │
│  users                              │
│  personal_access_tokens             │
│  campaign_blueprints                │
│  raw_contents                       │
│  generated_posts                    │
│  chat_messages                      │
│  post_status_logs                   │
└─────────────────────────────────────┘
                    │
                    ▼
        ┌───────────────────────┐
        │    xAI Grok API       │
        │  Structured Output    │
        │  Function Calling     │
        └───────────────────────┘
```

### Key architectural rules
- **Pure API**: every controller returns `response()->json()`. No Blade, no redirects.
- **202 Accepted**: AI generation is always async. The HTTP response returns immediately; the Job does the work.
- **Eloquent Casts**: all JSON columns (`body_points`, `suggested_hashtags`, `forbidden_words`) are cast to native PHP arrays in the model. Never manipulate raw JSON strings in application code.
- **Form Requests**: every write operation is validated by a dedicated `FormRequest` class. Controllers never call `$request->validate()` directly.
- **Policies**: every resource access is authorized through a Laravel Policy. No raw `abort_if` checks in controllers.

---

## 4. Database Design

### Tables overview

```
users
personal_access_tokens
campaign_blueprints  ──────┐
raw_contents  ─────────────┘
generated_posts  ──────────┐
  ├── raw_contents         │ (raw_content_id FK)
  ├── chat_messages
  └── post_status_logs
```

---

### `users`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | auto-increment |
| name | string | |
| email | string UNIQUE | |
| password | string | stored as bcrypt hash |
| email_verified_at | timestamp | nullable |
| remember_token | string | nullable |
| created_at / updated_at | timestamps | |

---

### `personal_access_tokens`

Managed entirely by Laravel Sanctum. Do not modify manually.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tokenable_type | string | polymorphic — `App\Models\User` |
| tokenable_id | bigint | FK to users.id |
| name | string | label, e.g. `api-token` |
| token | string(64) UNIQUE | SHA-256 hash of the plain text token |
| abilities | text | JSON array, e.g. `["*"]` |
| last_used_at | timestamp | nullable |
| expires_at | timestamp | nullable |
| created_at / updated_at | timestamps | |

---

### `campaign_blueprints`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | bigint FK | → users.id, cascade delete |
| name | string | e.g. "Tech Community Posts" |
| target_audience | string | default: `tech community` |
| tone | string | default: `professional but casual` |
| max_characters | smallint | default: 280 |
| max_hashtags | tinyint | default: 1 |
| forbidden_words | json | cast to `array` in model |
| style_notes | text | nullable, free-text instructions |
| created_at / updated_at | timestamps | |

**Relationships:**
- `belongsTo` User
- `hasMany` RawContent
- `hasMany` GeneratedPost

---

### `raw_contents`

Stores the user's raw submission before it is processed by the AI.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | bigint FK | → users.id, cascade delete |
| campaign_blueprint_id | bigint FK | → campaign_blueprints.id, cascade delete |
| body | text | the actual raw content |
| source_type | enum | `manual`, `markdown`, `github`, `notes` |
| title | string | nullable, optional label |
| status | enum | `pending`, `processing`, `processed`, `failed` |
| word_count | smallint | auto-calculated on save |
| created_at / updated_at | timestamps | |

**Relationships:**
- `belongsTo` User
- `belongsTo` CampaignBlueprint
- `hasOne` GeneratedPost (reverse — FK `raw_content_id` on `generated_posts`)

**Status lifecycle:**
```
pending → processing → processed
                    └→ failed
```

---

### `generated_posts`

Stores the AI-structured output for each submission.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| campaign_blueprint_id | bigint FK | → campaign_blueprints.id, cascade delete |
| raw_content_id | bigint FK | nullable → raw_contents.id, null on delete |
| hook_proposed | string(280) | nullable — filled by AI |
| body_points | json | cast to `array` — list of key points |
| technical_readability_score | tinyint | nullable — 0 to 100 |
| suggested_hashtags | json | cast to `array` — e.g. `["#Laravel"]` |
| tone_compliance_justification | text | nullable — AI's explanation |
| status | enum | `pending`, `draft`, `posted`, `archived` |
| created_at / updated_at | timestamps | |

**Status lifecycle:**
```
pending → draft → posted
              └→ archived
```

**Relationships:**
- `belongsTo` CampaignBlueprint
- `belongsTo` RawContent (FK `raw_content_id`)
- `hasMany` ChatMessage
- `hasMany` PostStatusLog

---

### `chat_messages`

Stores every message in the Ghostwriter conversation — user messages, assistant replies, and tool call results. This is the memory layer.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| generated_post_id | bigint FK | → generated_posts.id, cascade delete |
| role | enum | `user`, `assistant`, `tool` |
| content | text | the message body or tool result JSON |
| tool_name | string | nullable — filled when role = `tool` |
| created_at / updated_at | timestamps | |

**Relationships:**
- `belongsTo` GeneratedPost

---

### `post_status_logs`

Audit trail for every status change on a generated post.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| generated_post_id | bigint FK | → generated_posts.id, cascade delete |
| from_status | string | previous status |
| to_status | string | new status |
| created_at / updated_at | timestamps | |

**Relationships:**
- `belongsTo` GeneratedPost

---

## 5. Authentication Flow

ThreadForge uses **Sanctum token-based authentication**. No sessions, no cookies.

```
1. Client sends POST /api/register or POST /api/login
        │
2. Laravel validates credentials
        │
        ├── FAIL → 422 Unprocessable Entity + field errors
        │
        └── PASS → createToken() is called
                        │
                3. A random 40-char string is generated
                   The SHA-256 hash is stored in personal_access_tokens
                   The plain text is returned ONCE in the response
                        │
                4. Client stores the token (e.g. "1|abc123xyz...")
                        │
                5. Every subsequent request sends:
                   Authorization: Bearer 1|abc123xyz...
                        │
                6. auth:sanctum middleware:
                   - splits on | → finds row by ID
                   - hashes the right side → compares to stored hash
                   - MATCH → injects User into $request->user()
                   - NO MATCH → 401 Unauthenticated
                        │
                7. POST /api/auth/logout
                   - currentAccessToken()->delete()
                   - token is dead immediately
```

### Token format
```
1  |  abc123xyz...randomstring
│        └── 40-char random secret (never stored in DB)
└── token row ID (used to look up the DB row fast)
```

---

## 6. API Endpoints Reference

All endpoints are prefixed with `/api`. All responses are JSON.

### Authentication — public routes

| Method | Endpoint | Description | Request Body |
|---|---|---|---|
| POST | `/api/register` | Create account + get token | `name`, `email`, `password`, `password_confirmation` |
| POST | `/api/login` | Login + get token | `email`, `password` |

### Authentication — protected routes
| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/auth/logout` | Revoke current token |

---

### Campaign Blueprints

All require `Authorization: Bearer {token}`

| Method | Endpoint | Description | Body / Params |
|---|---|---|---|
| GET | `/api/blueprints` | List all blueprints (with post count) | — |
| POST | `/api/blueprints` | Create a blueprint | `name`, `target_audience`, `tone`, `max_characters`, `max_hashtags`, `forbidden_words[]`, `style_notes` |
| GET | `/api/blueprints/{id}` | Get single blueprint | — |
| PUT | `/api/blueprints/{id}` | Update blueprint | same fields as POST, all optional |
| DELETE | `/api/blueprints/{id}` | Delete blueprint | — |

---

### Raw Content

| Method | Endpoint | Description | Body / Params |
|---|---|---|---|
| GET | `/api/blueprints/{blueprint}/raw-contents` | List raw submissions for a blueprint | `?status=pending\|processed\|failed` |
| POST | `/api/blueprints/{blueprint}/raw-contents` | Submit raw content → dispatches async job | `body`, `source_type`, `title` |
| GET | `/api/raw-contents/{id}` | Get single raw content with its generated post | — |
| PUT | `/api/raw-contents/{id}` | Edit raw content (only if status = pending) | `body`, `source_type`, `title` |
| DELETE | `/api/raw-contents/{id}` | Delete raw content | — |

---

### Generated Posts

| Method | Endpoint | Description | Body / Params |
|---|---|---|---|
| GET | `/api/posts` | List all posts | `?status=pending\|draft\|posted\|archived` |
| GET | `/api/posts/{id}` | Get single post with full AI output | — |
| PATCH | `/api/posts/{id}/status` | Update status | `status`: `draft`, `posted`, `archived` |

---

### Ghostwriter Chat

| Method | Endpoint | Description | Body |
|---|---|---|---|
| GET | `/api/posts/{id}/chat` | Get full conversation history | — |
| POST | `/api/posts/{id}/chat` | Send a message to the agent | `message` |

---

### Response shape conventions

**Success (single resource):**
```json
{
  "message": "Blueprint created.",
  "data": { ... }
}
```

**Success (collection):**
```json
{
  "data": [ ... ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 20,
    "total": 42
  }
}
```

**Async accepted (202):**
```json
{
  "message": "Content submitted. Generation is in progress.",
  "data": { "id": 12, "status": "pending" }
}
```

**Validation error (422):**
```json
{
  "message": "Validation failed.",
  "errors": {
    "email": ["The email field is required."],
    "max_hashtags": ["The max hashtags must be an integer."]
  }
}
```

**Auth error (401):**
```json
{ "message": "Unauthenticated. Please provide a valid Bearer token." }
```

**Forbidden (403):**
```json
{ "message": "Forbidden. You do not have access to this resource." }
```

---

## 7. File Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── AuthController.php                   # register, login, logout
│   │   ├── CampaignBlueprintController.php      # CRUD for blueprints
│   │   ├── RawContentController.php             # CRUD for raw submissions
│   │   ├── GeneratedPostController.php          # list, show, updateStatus
│   │   └── GhostwriterController.php            # chat history + send
│   │
│   ├── Requests/
│   │   ├── Auth/
│   │   │   ├── RegisterRequest.php
│   │   │   └── LoginRequest.php
│   │   ├── Blueprint/
│   │   │   └── StoreBlueprintRequest.php
│   │   ├── RawContent/
│   │   │   ├── StoreRawContentRequest.php
│   │   │   └── UpdateRawContentRequest.php
│   │   ├── Post/
│   │   │   └── UpdatePostStatusRequest.php
│   │   └── Chat/
│   │       └── SendChatMessageRequest.php
│   │
│   └── Resources/
│       ├── Blueprint/
│       │   ├── BlueprintResource.php
│       │   └── BlueprintCollection.php
│       ├── RawContent/
│       │   ├── RawContentResource.php
│       │   └── RawContentCollection.php
│       ├── Post/
│       │   ├── PostResource.php
│       │   └── PostCollection.php
│       └── Chat/
│           └── ChatMessageResource.php
│
├── Jobs/
│   └── GeneratePostJob.php                      # async AI structured output
│
├── Models/
│   ├── User.php
│   ├── CampaignBlueprint.php
│   ├── RawContent.php
│   ├── GeneratedPost.php
│   ├── ChatMessage.php
│   └── PostStatusLog.php
│
├── Policies/
│   ├── CampaignBlueprintPolicy.php
│   ├── RawContentPolicy.php
│   ├── GeneratedPostPolicy.php
│   └── ChatMessagePolicy.php
│
├── Providers/
│   └── AuthServiceProvider.php                  # policy registration
│
└── Services/
    └── GhostwriterService.php                   # agent loop + tools

bootstrap/
└── app.php                                       # global JSON error handler

config/
└── services.php                                  # Grok API credentials

database/
└── migrations/
    ├── 2024_01_01_000001_create_users_table.php
    ├── 2024_01_01_000002_create_personal_access_tokens_table.php
    ├── 2024_01_01_000003_create_campaign_blueprints_table.php
    ├── 2024_01_01_000004_create_raw_contents_table.php
    ├── 2024_01_01_000005_create_generated_posts_table.php
    ├── 2024_01_01_000006_create_chat_messages_table.php
    └── 2024_01_01_000007_create_post_status_logs_table.php

routes/
└── api.php                                       # all routes, no web.php

.env.example                                      # all required env variables
```

---

## 8. Jira Epics & Tickets

### Epic 1 — Foundation & Authentication

---

#### THREAD-1 · Project Bootstrap
**Branch:** `feature/project-bootstrap`
**Priority:** Highest
**Estimate:** 2h

**Description:**
Set up the Laravel project from scratch with all required configuration before any feature work begins.

**Tasks:**
- [ ] Create new Laravel 11 project (`laravel new threadforge`)
- [ ] Install Laravel Sanctum (`composer require laravel/sanctum`)
- [ ] Configure `.env` from `.env.example` (DB credentials, QUEUE_CONNECTION=database)
- [ ] Run `php artisan queue:table && php artisan migrate`
- [ ] Verify `routes/api.php` is the only active route file
- [ ] Copy `bootstrap/app.php` with global JSON error handler
- [ ] Copy `config/services.php` with Grok config block
- [ ] Test: `GET /api/unknown-route` must return `{"message":"Resource not found."}` not HTML

**Files touched:**
`.env` · `bootstrap/app.php` · `config/services.php` · `routes/api.php`

**Acceptance criteria:**
- All 404s and 500s return JSON, never HTML
- Queue worker runs: `php artisan queue:work`
- `php artisan migrate` runs clean with no errors

---

#### THREAD-2 · Authentication — Register / Login / Logout
**Branch:** `feature/auth-register-login-logout`
**Priority:** Highest
**Estimate:** 3h
**Depends on:** THREAD-1

**Description:**
Implement full Sanctum token-based authentication. Three endpoints, two Form Requests, one controller.

**Tasks:**
- [ ] Create `users` migration + run migrate
- [ ] Create `personal_access_tokens` migration + run migrate
- [ ] Add `HasApiTokens` trait to `User` model
- [ ] Add `'password' => 'hashed'` cast to `User` model
- [ ] Create `RegisterRequest` with rules: name required, email unique, password confirmed
- [ ] Create `LoginRequest` with rules: email required, password required
- [ ] Create `AuthController` with methods: `register`, `login`, `logout`
- [ ] Register routes in `routes/api.php`: `POST /register`, `POST /login`, `POST /logout`
- [ ] `logout` must be behind `auth:sanctum` middleware
- [ ] Test all three endpoints in Postman

**Files touched:**
`migrations/create_users_table.php` · `migrations/create_personal_access_tokens_table.php`
`Models/User.php` · `Requests/Auth/RegisterRequest.php` · `Requests/Auth/LoginRequest.php`
`Controllers/AuthController.php` · `routes/api.php`

**Acceptance criteria:**
- `POST /api/register` returns `201` with `token` field
- `POST /api/login` with wrong password returns `422`
- `POST /api/auth/logout` with no token returns `401`
- `POST /api/auth/logout` with valid token returns `200` and token is invalidated

---

### Epic 2 — Campaign Blueprints

---

#### THREAD-3 · Blueprint Migration & Model
**Branch:** `feature/blueprint-migration-model`
**Priority:** High
**Estimate:** 1.5h
**Depends on:** THREAD-2

**Description:**
Create the database layer for Campaign Blueprints. The `forbidden_words` column must be a JSON column cast to a PHP array.

**Tasks:**
- [ ] Create `campaign_blueprints` migration with all columns
- [ ] Set default values: `target_audience`, `tone`, `max_characters=280`, `max_hashtags=1`
- [ ] Create `CampaignBlueprint` model
- [ ] Add `forbidden_words => 'array'` cast
- [ ] Add `belongsTo User` relationship
- [ ] Add `hasMany GeneratedPost` relationship
- [ ] Add `hasMany RawContent` relationship
- [ ] Run migration and verify schema in DB

**Files touched:**
`migrations/create_campaign_blueprints_table.php` · `Models/CampaignBlueprint.php`

**Acceptance criteria:**
- Migration runs without errors
- `$blueprint->forbidden_words` returns a PHP array, not a JSON string
- `$user->campaignBlueprints` relationship works

---

#### THREAD-4 · Blueprint CRUD API
**Branch:** `feature/blueprint-crud-api`
**Priority:** High
**Estimate:** 3h
**Depends on:** THREAD-3

**Description:**
Full CRUD API for blueprints. Every response uses a Resource class. List endpoint includes the count of generated posts per blueprint. Users can only access their own blueprints.

**Tasks:**
- [ ] Create `StoreBlueprintRequest` with all validation rules
- [ ] Create `BlueprintResource` — expose all fields, include `generated_posts_count` via `whenCounted`
- [ ] Create `BlueprintCollection` — wrap array with `total` count
- [ ] Create `CampaignBlueprintController` with: `index`, `store`, `show`, `update`, `destroy`
- [ ] `index`: use `withCount('generatedPosts')` on the query
- [ ] `show`, `update`, `destroy`: use `$this->authorize()` with `CampaignBlueprintPolicy`
- [ ] Create `CampaignBlueprintPolicy` with: `view`, `update`, `delete` methods
- [ ] Register policy in `AuthServiceProvider`
- [ ] Register all 5 routes via `Route::apiResource('blueprints', ...)`
- [ ] Test: user A cannot access user B's blueprint (must return 403)

**Files touched:**
`Requests/Blueprint/StoreBlueprintRequest.php` · `Resources/Blueprint/BlueprintResource.php`
`Resources/Blueprint/BlueprintCollection.php` · `Controllers/CampaignBlueprintController.php`
`Policies/CampaignBlueprintPolicy.php` · `Providers/AuthServiceProvider.php` · `routes/api.php`

**Acceptance criteria:**
- `GET /api/blueprints` returns list with `generated_posts_count` field
- `POST /api/blueprints` with missing `name` returns `422` with `errors.name`
- `DELETE /api/blueprints/{id}` by a different user returns `403`
- `forbidden_words` is returned as a JSON array `[]`, never a string

---

### Epic 3 — Raw Content

---

#### THREAD-5 · Raw Content Migration & Model
**Branch:** `feature/raw-content-migration-model`
**Priority:** High
**Estimate:** 1.5h
**Depends on:** THREAD-3

**Description:**
Create the `raw_contents` table. This is the staging area for user submissions before the AI processes them. The model auto-calculates word count on save.

**Tasks:**
- [ ] Create `raw_contents` migration with all columns
- [ ] Add `source_type` enum: `manual`, `markdown`, `github`, `notes`
- [ ] Add `status` enum: `pending`, `processing`, `processed`, `failed`
- [ ] Add nullable FK to `generated_posts` (`nullOnDelete`)
- [ ] Create `RawContent` model
- [ ] Add `word_count => 'integer'` cast
- [ ] Add model `booted()` hook to auto-calculate `word_count` from `body` on create and update
- [ ] Add relationships: `belongsTo User`, `belongsTo CampaignBlueprint`, `belongsTo GeneratedPost`
- [ ] Add helper methods: `isPending()`, `isProcessed()`, `hasFailed()`

**Files touched:**
`migrations/create_raw_contents_table.php` · `Models/RawContent.php`

**Acceptance criteria:**
- Migration runs without errors
- Creating a RawContent with a 500-word body auto-fills `word_count = 500`
- Updating the `body` field recalculates `word_count`

---

#### THREAD-6 · Raw Content API
**Branch:** `feature/raw-content-api`
**Priority:** High
**Estimate:** 3h
**Depends on:** THREAD-5

**Description:**
CRUD API for raw content submissions. Submitting raw content dispatches the async `GeneratePostJob`. Users can only edit submissions that are still in `pending` status.

**Tasks:**
- [ ] Create `StoreRawContentRequest`: `body` required min 20, `source_type` optional enum, `title` optional
- [ ] Create `UpdateRawContentRequest`: all fields optional, same rules
- [ ] Create `RawContentResource` with: id, title, body, source_type, word_count, status, blueprint (summary), generated_post (summary if loaded)
- [ ] Create `RawContentCollection` with pagination meta
- [ ] Create `RawContentController` with: `index`, `store`, `show`, `update`, `destroy`
- [ ] `store`: create record with `status=pending`, dispatch `GeneratePostJob`, return `202`
- [ ] `update`: check `$rawContent->isPending()` — if not pending, return `422 Cannot edit content that is already being processed`
- [ ] Create `RawContentPolicy`: `view`, `update`, `delete` — check `user_id`
- [ ] Register policy in `AuthServiceProvider`
- [ ] Register routes nested under blueprints and as standalone

**Files touched:**
`Requests/RawContent/StoreRawContentRequest.php` · `Requests/RawContent/UpdateRawContentRequest.php`
`Resources/RawContent/RawContentResource.php` · `Resources/RawContent/RawContentCollection.php`
`Controllers/RawContentController.php` · `Policies/RawContentPolicy.php`
`Providers/AuthServiceProvider.php` · `routes/api.php`

**Acceptance criteria:**
- `POST /api/blueprints/{id}/raw-contents` returns `202` immediately
- `GET /api/raw-contents/{id}` shows the linked `generated_post` once processed
- `PUT /api/raw-contents/{id}` on a `processed` record returns `422`
- Word count is present in the response

---

### Epic 4 — Async AI Generation

---

#### THREAD-7 · GeneratePostJob — Async Structured Output
**Branch:** `feature/generate-post-async-job`
**Priority:** Highest
**Estimate:** 4h
**Depends on:** THREAD-6

**Description:**
The core AI job. It builds a system prompt from the Blueprint rules, calls the Grok API with a strict JSON schema (`response_format: json_object`), validates the structure of the response, and persists all 5 structured output fields to the `generated_posts` table. On failure it retries 3 times then marks the post as `archived`.

**Tasks:**
- [ ] Create `generated_posts` migration with all columns
- [ ] Add JSON casts for `body_points` and `suggested_hashtags` in `GeneratedPost` model
- [ ] Add relationships: `belongsTo CampaignBlueprint`, `hasMany ChatMessage`, `hasMany PostStatusLog`
- [ ] Add `isProcessed()` helper method to `GeneratedPost`
- [ ] Create `GeneratePostJob` implementing `ShouldQueue`
- [ ] Set `$tries = 3` and `$backoff = 10` on the job
- [ ] Build the system prompt dynamically from Blueprint fields
- [ ] Call Grok API via `Http::withToken()` with `response_format: json_object`
- [ ] Validate the 5 required fields in the response: `hook_proposed`, `body_points`, `technical_readability_score`, `suggested_hashtags`, `tone_compliance_justification`
- [ ] On valid response: update `generated_post` with all fields and `status=draft`
- [ ] On invalid response or API failure: call `$this->fail()` to trigger retry
- [ ] Implement `failed()` method: set `status=archived`, log the error
- [ ] Update `RawContent.status` to `processed` or `failed` accordingly
- [ ] Test with `php artisan queue:work` running in a separate terminal

**Files touched:**
`migrations/create_generated_posts_table.php` · `Models/GeneratedPost.php`
`Jobs/GeneratePostJob.php` · `config/services.php`

**Acceptance criteria:**
- Submitting raw content returns `202` immediately — the HTTP request does not wait for AI
- After the job runs, `GET /api/posts/{id}` returns all 5 AI fields populated
- If Grok API is down, the job retries 3 times then sets status to `archived`
- `body_points` and `suggested_hashtags` are returned as arrays, never strings

---

### Epic 5 — Post Lifecycle

---

#### THREAD-8 · Generated Posts API — List, Show, Status
**Branch:** `feature/post-lifecycle-api`
**Priority:** High
**Estimate:** 3h
**Depends on:** THREAD-7

**Description:**
Read and status-management endpoints for generated posts. Status changes are logged in `post_status_logs`. Users cannot change the status of a `pending` post.

**Tasks:**
- [ ] Create `post_status_logs` migration
- [ ] Create `PostStatusLog` model with `belongsTo GeneratedPost`
- [ ] Create `UpdatePostStatusRequest`: `status` required, must be one of `draft`, `posted`, `archived`
- [ ] Create `PostResource`: expose all AI fields, cast arrays, include blueprint summary
- [ ] Create `PostCollection`: paginated, 20 per page
- [ ] Create `GeneratedPostController` with: `index`, `show`, `updateStatus`
- [ ] `index`: filter by `?status=` query param, eager load `blueprint:id,name`, paginate 20
- [ ] `updateStatus`: check `status !== pending`, log transition in `post_status_logs`, use Policy
- [ ] Create `GeneratedPostPolicy`: `view`, `update`, `chat` — check via `blueprint.user_id`
- [ ] Register policy in `AuthServiceProvider`
- [ ] Register routes in `routes/api.php`

**Files touched:**
`migrations/create_post_status_logs_table.php` · `Models/PostStatusLog.php`
`Requests/Post/UpdatePostStatusRequest.php` · `Resources/Post/PostResource.php`
`Resources/Post/PostCollection.php` · `Controllers/GeneratedPostController.php`
`Policies/GeneratedPostPolicy.php` · `Providers/AuthServiceProvider.php` · `routes/api.php`

**Acceptance criteria:**
- `GET /api/posts?status=draft` returns only draft posts
- `PATCH /api/posts/{id}/status` with `status=posted` creates a row in `post_status_logs`
- `PATCH /api/posts/{id}/status` on a `pending` post returns `422`
- A user cannot access another user's post — returns `403`

---

### Epic 6 — Ghostwriter Agent

---

#### THREAD-9 · Ghostwriter Chat Migration & Model
**Branch:** `feature/chat-migration-model`
**Priority:** High
**Estimate:** 1h
**Depends on:** THREAD-8

**Description:**
Create the `chat_messages` table which is the memory layer for the Ghostwriter agent. Every message — user, assistant, and tool result — is stored here so the full conversation history can be reconstructed on every API call.

**Tasks:**
- [ ] Create `chat_messages` migration
- [ ] `role` enum must include: `user`, `assistant`, `tool`
- [ ] `tool_name` column nullable — only filled when role = `tool`
- [ ] Create `ChatMessage` model with `belongsTo GeneratedPost`
- [ ] Run migration and verify schema

**Files touched:**
`migrations/create_chat_messages_table.php` · `Models/ChatMessage.php`

**Acceptance criteria:**
- Migration runs without errors
- `role` column rejects any value outside the three enum values

---

#### THREAD-10 · Ghostwriter Agent Service — Memory & Tools
**Branch:** `feature/ghostwriter-agent-tools`
**Priority:** High
**Estimate:** 5h
**Depends on:** THREAD-9

**Description:**
The most complex piece of the project. `GhostwriterService` implements a full agentic loop:
build history from DB → call Grok with tools → if tool call requested, execute PHP function → feed result back → repeat until final text response.

**Tasks:**
- [ ] Create `GhostwriterService` class in `app/Services/`
- [ ] Implement `buildMessageHistory(GeneratedPost $post): array` — loads all `chat_messages` in order, reconstructs the conversation array including system prompt
- [ ] Implement `buildSystemPrompt(GeneratedPost $post): string` — uses blueprint name and post ID
- [ ] Define `TOOLS` constant with two tool definitions for the API:
  - `getCampaignRules(campaign_id: int)` — description, parameter schema
  - `getPostHistory(post_id: int)` — description, parameter schema
- [ ] Implement `runAgentLoop(GeneratedPost $post, array $messages): string`
  - Max 5 iterations (safety cap)
  - Call Grok API with `tools` and `tool_choice: auto`
  - If `finish_reason = stop` → return the text content
  - If `finish_reason = tool_calls` → iterate tool calls, execute each, persist result to `chat_messages`, add to conversation, loop again
- [ ] Implement `executeTool(string $name, array $args): array` dispatcher
- [ ] Implement `getCampaignRules(int $campaignId): array` — queries `CampaignBlueprint`
- [ ] Implement `getPostHistory(int $postId): array` — queries `GeneratedPost` with blueprint
- [ ] Both tool methods must handle "not found" gracefully with an `error` key in the return array

**Files touched:**
`Services/GhostwriterService.php`

**Acceptance criteria:**
- Asking "What are the campaign rules?" triggers a `getCampaignRules` tool call — not a hallucinated answer
- The tool result is saved to `chat_messages` with `role=tool`
- On the next message, the previous tool call result is included in the history
- If Grok makes 5 tool calls without a final response, the loop exits gracefully

---

#### THREAD-11 · Ghostwriter Chat API
**Branch:** `feature/ghostwriter-chat-api`
**Priority:** High
**Estimate:** 2h
**Depends on:** THREAD-10

**Description:**
Two endpoints: get conversation history and send a message. The controller delegates all AI logic to `GhostwriterService`.

**Tasks:**
- [ ] Create `SendChatMessageRequest`: `message` required, min 2, max 2000
- [ ] Create `ChatMessageResource`: id, role, content, tool_name, created_at
- [ ] Create `GhostwriterController` with: `history`, `send`
- [ ] `send`: check `$post->isProcessed()` before allowing chat — return `422` if still pending
- [ ] `send`: persist user message → call `$this->ghostwriter->chat($post)` → persist assistant reply → return resource
- [ ] Create `ChatMessagePolicy`: `view` — check via `post.blueprint.user_id`
- [ ] Register policy in `AuthServiceProvider`
- [ ] Register routes: `GET /api/posts/{post}/chat` and `POST /api/posts/{post}/chat`
- [ ] Bind `GhostwriterService` in controller via constructor injection

**Files touched:**
`Requests/Chat/SendChatMessageRequest.php` · `Resources/Chat/ChatMessageResource.php`
`Controllers/GhostwriterController.php` · `Policies/ChatMessagePolicy.php`
`Providers/AuthServiceProvider.php` · `routes/api.php`

**Acceptance criteria:**
- `POST /api/posts/{id}/chat` on a `pending` post returns `422`
- `GET /api/posts/{id}/chat` returns full conversation history in chronological order
- Each assistant reply is persisted and appears in the next `GET` response
- Tool result messages appear in history with `role: tool` and `tool_name` field

---

## 9. GitHub Branching Strategy

```
main
 ├── feature/project-bootstrap          (THREAD-1)
 ├── feature/auth-register-login-logout (THREAD-2)
 ├── feature/blueprint-migration-model  (THREAD-3)
 ├── feature/blueprint-crud-api         (THREAD-4)
 ├── feature/raw-content-migration-model(THREAD-5)
 ├── feature/raw-content-api            (THREAD-6)
 ├── feature/generate-post-async-job    (THREAD-7)
 ├── feature/post-lifecycle-api         (THREAD-8)
 ├── feature/chat-migration-model       (THREAD-9)
 ├── feature/ghostwriter-agent-tools    (THREAD-10)
 └── feature/ghostwriter-chat-api       (THREAD-11)
```

### Rules
- Every branch is cut from `main`
- Every branch maps to exactly one Jira ticket
- Merge via Pull Request — no direct pushes to `main`
- Branch name = `feature/{jira-ticket-slug}`
- Commit message format: `feat: description` / `fix: description` / `chore: description`

### Commit message examples per ticket
```
THREAD-2:
  feat: add users and personal_access_tokens migrations
  feat: add HasApiTokens trait and hashed password cast to User model
  feat: add RegisterRequest with email unique and password confirmed rules
  feat: add LoginRequest validation
  feat: implement AuthController register login logout
  feat: register auth routes in api.php

THREAD-7:
  feat: add generated_posts migration with json columns
  feat: add GeneratedPost model with array casts and relationships
  feat: scaffold GeneratePostJob with ShouldQueue
  feat: build dynamic system prompt from blueprint rules
  feat: integrate Grok API call with json_object response format
  feat: validate structured output schema before persisting
  feat: implement failed() handler with archived status fallback
```

---

## 10. Environment & Setup

### First-time setup

```bash
# 1. Clone the repo
git clone https://github.com/your-org/threadforge.git
cd threadforge

# 2. Install PHP dependencies
composer install

# 3. Copy and configure environment
cp .env.example .env
php artisan key:generate

# 4. Fill in .env
# DB_DATABASE, DB_USERNAME, DB_PASSWORD
# GROK_API_KEY (from https://console.x.ai)
# QUEUE_CONNECTION=database

# 5. Run all migrations
php artisan migrate

# 6. Start the queue worker (required for AI generation)
php artisan queue:work --tries=3

# 7. Start the dev server (in a separate terminal)
php artisan serve
```

### Required `.env` variables

| Variable | Required | Description |
|---|---|---|
| `APP_KEY` | Yes | Generated by `php artisan key:generate` |
| `DB_DATABASE` | Yes | MySQL database name |
| `DB_USERNAME` | Yes | MySQL username |
| `DB_PASSWORD` | Yes | MySQL password |
| `QUEUE_CONNECTION` | Yes | `database` for dev, `redis` for prod |
| `GROK_API_KEY` | Yes | xAI API key from console.x.ai |
| `GROK_BASE_URL` | Yes | `https://api.x.ai/v1` |

---

## 11. Queue & Async Jobs

### Why async?

Calling the Grok AI API can take 5–30 seconds. If this was synchronous, the HTTP request would time out.
The solution: `POST /api/blueprints/{id}/raw-contents` returns `202 Accepted` immediately and dispatches `GeneratePostJob` to the queue. The job runs in the background via `php artisan queue:work`.

### Job flow

```
POST /api/raw-contents  →  RawContent created (status=pending)
                        →  GeneratePostJob::dispatch($rawContent)
                        →  202 Accepted returned to client

[queue worker picks up the job]
        ↓
Build system prompt from Blueprint
        ↓
Call Grok API (grok-3, json_object mode)
        ↓
    ┌── Valid response ──→ Persist to generated_posts (status=draft)
    │                  ──→ Update raw_content (status=processed)
    │
    └── Invalid / Error ──→ $this->fail() ──→ retry (up to 3 times)
                        ──→ After 3 failures: failed() method called
                        ──→ generated_post.status = archived
                        ──→ raw_content.status = failed
```

### Running the worker

```bash
# Development
php artisan queue:work --tries=3

# Production (with supervisor recommended)
php artisan queue:work --tries=3 --sleep=3 --max-jobs=500
```

---

## 12. AI Layer

### Structured Output (GeneratePostJob)

The job enforces this exact schema via Grok's `response_format: json_object`:

```json
{
  "hook_proposed": "string — max 280 characters",
  "body_points": ["string", "string"],
  "technical_readability_score": 85,
  "suggested_hashtags": ["#Laravel"],
  "tone_compliance_justification": "string"
}
```

All 5 fields are required. If any are missing, the job fails and retries.

### Ghostwriter Agent (GhostwriterService)

The agent has access to two PHP tools:

| Tool | PHP method | When called |
|---|---|---|
| `getCampaignRules` | `getCampaignRules(int $campaignId)` | User asks about style rules, tone, limits |
| `getPostHistory` | `getPostHistory(int $postId)` | User asks to improve the hook or body points |

**Agentic loop (max 5 iterations):**
```
Build history from DB
        ↓
Call Grok with tools
        ↓
finish_reason = stop? ──→ return text to user
        ↓
finish_reason = tool_calls?
        ↓
Execute each PHP tool function
Save result to chat_messages (role=tool)
Add result to conversation
        ↓
Loop → Call Grok again with updated history
```

---

## 13. Error Handling Contract

All errors in the application return JSON. Defined in `bootstrap/app.php`.

| HTTP Status | When | Response shape |
|---|---|---|
| 401 | Missing or invalid Bearer token | `{"message": "Unauthenticated."}` |
| 403 | Valid token but wrong user for the resource | `{"message": "Forbidden."}` |
| 404 | Route or model not found | `{"message": "Resource not found."}` |
| 422 | Form Request validation failed | `{"message": "Validation failed.", "errors": {...}}` |
| 500 | Unexpected exception | `{"message": "An unexpected server error occurred."}` (+ debug info in local env) |

---

## 14. Policies & Authorization

| Policy | Model | Methods | Rule |
|---|---|---|---|
| `CampaignBlueprintPolicy` | `CampaignBlueprint` | `view`, `update`, `delete` | `$blueprint->user_id === $user->id` |
| `RawContentPolicy` | `RawContent` | `view`, `update`, `delete` | `$rawContent->user_id === $user->id` |
| `GeneratedPostPolicy` | `GeneratedPost` | `view`, `update`, `chat` | `$post->blueprint->user_id === $user->id` |
| `ChatMessagePolicy` | `ChatMessage` | `view` | `$message->generatedPost->blueprint->user_id === $user->id` |

All policies are registered in `AuthServiceProvider::$policies`.
Controllers call `$this->authorize('action', $model)` — never manual `abort_if`.

---

## 15. 5-Day Sprint Plan

| Day | Tickets | Goal |
|---|---|---|
| Day 1 | THREAD-1, THREAD-2 | Project runs, auth works end-to-end with Postman |
| Day 2 | THREAD-3, THREAD-4 | Full Blueprint CRUD with ownership protection |
| Day 3 | THREAD-5, THREAD-6, THREAD-7 | Raw content submitted → AI job runs → post generated in DB |
| Day 4 | THREAD-8, THREAD-9 | Post lifecycle API complete, status transitions logged |
| Day 5 | THREAD-10, THREAD-11 | Ghostwriter agent works with memory and tool calling |

---

*Last updated: June 2026 — ThreadForge v1.0*