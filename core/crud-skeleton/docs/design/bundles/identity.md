Identity Module Design
======================

Overview
--------
This document describes the Identity module (src/Identity). It implements:

- JWT access tokens (RS256) with a 7200s TTL
- server-stored refresh tokens (opaque, hashed) with 1 year TTL and rotation
- phone-based OTP login/verification, delivered via Alibaba Cloud SMS
- identifier-based login: identifier can be email, username or a verified phone
- **password-based user self-registration** (`POST /api/auth/register`)
- **user profile management** with password change and profile update
- **admin user CRUD** with managed password changes
- **profile management**: tiered membership (bronze/silver/gold/platinum/diamond), auto-created 1:1 with User, points delegated to Wallet (currency=POINTS). Also carries nickname/avatar/metadata as user profile data.

Goals
-----
- Keep existing identifier+password flow intact
- Add phone+OTP as an additional auth path
- **Add password registration** as a self-service onboarding path
- **Add user controllers** for profile management (App) and admin CRUD (Manage)
- Use Redis for OTP storage and rate-limiting
- Use RS256 for JWT signing and verify with public key
- Store refresh tokens hashed in MySQL (identity_refresh_token table)

Environment variables (.env.example)
-----------------------------------
See .env.example at repository root for all variables. Key ones:

- JWT_PRIVATE_KEY_PATH, JWT_PUBLIC_KEY_PATH, JWT_PASSPHRASE
- ACCESS_TOKEN_TTL (7200)
- REFRESH_TOKEN_TTL (31536000)
- REFRESH_TOKEN_SECRET
- OTP_TTL (300)
- OTP_REDIS_DSN
- ALIYUN_ACCESS_KEY_ID, ALIYUN_ACCESS_KEY_SECRET
- ALIYUN_SMS_SIGN_NAME
- ALIYUN_SMS_TEMPLATE_LOGIN_OTP
- ALIYUN_SMS_TEMPLATE_VERIFY_PHONE
- ALIYUN_SMS_DRY_RUN (development safe flag)

DB Schema (MySQL)
------------------
We will add the following table for refresh tokens (migration SQL will be provided):

CREATE TABLE identity_refresh_token (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  refresh_token_hash VARCHAR(128) NOT NULL,
  jti VARCHAR(64) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME DEFAULT NULL,
  replaced_by_token_id BIGINT DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent TEXT,
  INDEX (refresh_token_hash),
  INDEX (user_id),
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

Note: users.phone is nullable; a UNIQUE index on phone is used. MySQL allows multiple NULLs, which satisfies "nullable but unique when present" semantics.

Token flows
-----------

Login (identifier + password)
- POST /api/auth/login { identifier, password }
- identifier may be email, username or a phone (phone allowed only if phone_verified=true)
- on success returns { access_token, refresh_token, expires_in }

Register (password self-registration)
- POST /api/auth/register { email, username, password, phone? }
- Public endpoint (no auth required)
- Validates uniqueness of email, username, and phone
- Creates User with hashed password via UserService::register()
- Returns JWT tokens directly (same format as login)
- Password minimum 6 characters

OTP Login / Verify
- POST /api/auth/otp/request { phone, purpose }
  - generates OTP, stores hash in Redis, sends SMS via Aliyun
- POST /api/auth/otp/verify { phone, otp, purpose }
  - verifies OTP; if purpose=login issues tokens; if purpose=verify_phone marks phone_verified

Token Refresh
- POST /api/auth/token/refresh { refresh_token }
- Server looks up hashed refresh token, validates, rotates (creates new refresh token, revokes old)

Logout
- POST /api/auth/logout { refresh_token }
- marks refresh token revoked

User Profile (App)
------------------

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/v1/app/users/me` | ROLE_USER | Get current user profile |
| PUT | `/api/v1/app/users/me` | ROLE_USER | Update email, username, phone, optional password |
| POST | `/api/v1/app/users/change-password` | ROLE_USER | Change own password (requires current password) |

User Management (Manage)
------------------------

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/v1/manage/users` | ROLE_ADMIN | List all users |
| GET | `/api/v1/manage/users/{id}` | ROLE_ADMIN | View user detail |
| POST | `/api/v1/manage/users` | ROLE_ADMIN | Create user (with hashed password) |
| PUT | `/api/v1/manage/users/{id}` | ROLE_ADMIN | Update user (email, username, password, phone, roles) |
| DELETE | `/api/v1/manage/users/{id}` | ROLE_ADMIN | Delete user |
| POST | `/api/v1/manage/users/{id}/change-password` | ROLE_ADMIN | Admin change user password (no current pw required) |

### Cross-Boundary User Identity

`users.id` remains the local Identity database key. Before Identity is referenced by a
separately deployable module or service, `User` MUST expose a unique UUID. Other
modules and integration events use `userUuid`, never the local `users.id`, as a durable
customer or staff reference.

The migration may temporarily resolve a UUID to the local ID inside Identity, but Store,
Inventory, Payment integrations, and future services MUST NOT persist a foreign key or
long-lived reference to `users.id`.

Profile Management (Manage)
--------------------------

`Profile` is the identity profile entity: a 1:1 extension of User, auto-created on User persist.
It carries membership level, profile fields (nickname/avatar/metadata), and joinedAt.
Membership points are stored in Wallet (currency=POINTS), not on Profile.

| Entity | Table | Purpose |
|--------|-------|---------|
| `Profile` | `identity_profile` | 1:1 User extension: level, nickname, avatar, metadata, joinedAt |

**Level Constants:**

```php
public const LEVEL_BRONZE = 'bronze';
public const LEVEL_SILVER = 'silver';
public const LEVEL_GOLD = 'gold';
public const LEVEL_PLATINUM = 'platinum';
public const LEVEL_DIAMOND = 'diamond';
```

**Level Hierarchy (for `findByLevelOrAbove`):**

```
bronze (0) < silver (1) < gold (2) < platinum (3) < diamond (4)
```

**Profile Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `nickname` | string(255) nullable | Display name (takes precedence over username for display) |
| `avatar` | string(500) nullable | Avatar URL |
| `metadata` | json nullable | Extensible preferences (theme, language, etc.) |

**Auto-Creation via Doctrine Listener:**

A `UserProfileListener` (`#[AsDoctrineListener(event: Events::postPersist)]`) ensures
every persisted User has a Profile by default. After `EntityManager::flush()` on a new
User, if no Profile exists, one is created at `LEVEL_BRONZE` automatically. This covers
all User creation paths: `UserService::register()`, admin CRUD, programmatic creation.

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/v1/manage/profiles` | ROLE_ADMIN | List all profiles |
| GET | `/api/v1/manage/profiles/{id}` | ROLE_ADMIN | View profile detail |
| POST | `/api/v1/manage/profiles` | ROLE_ADMIN | Create profile (user, level, nickname, avatar, metadata, joinedAt) |
| PUT | `/api/v1/manage/profiles/{id}` | ROLE_ADMIN | Update profile (level, nickname, avatar, metadata, joinedAt) |
| DELETE | `/api/v1/manage/profiles/{id}` | ROLE_ADMIN | Delete profile |

**User Entity Relationship:**

```php
// User has inverse side OneToOne
#[ORM\OneToOne(mappedBy: 'user', targetEntity: Profile::class, cascade: ['persist', 'remove'])]
private ?Profile $profile = null;

public function getProfile(): ?Profile;
public function setProfile(?Profile $profile): self;  // syncs bidirectional
```

**Repository Methods:**

```php
ProfileRepository extends ServiceEntityRepository
  findById(int $id): ?Profile
  findByUser(User $user): ?Profile
  findByUserId(int $userId): ?Profile
  findByLevel(string $level): array              // exact level match
  findByLevelOrAbove(string $minLevel): array    // cumulative (>= this level)
```

**Points via Wallet:**

Profile points use the Wallet module. A user's points are stored as:

```
Wallet for user_id=X, currency="POINTS"
```

No points field exists on Profile. Promotion conditions use `user.profile.level` for
level-based rules and reference Wallet for point-based rules.

Profile App Self-Service (App)
------------------------------

The App ProfileController uses `SingleDetailApiViewMixin` (GET) and
`SingleCreateAndUpdateApiViewMixin` (PUT). Property filtering is handled
by the mixin via `acceptedCreateProperties` / `acceptedUpdateProperties`:

```php
#[Route('/app/profiles', name: 'app-profiles-')]
#[IsGranted('ROLE_USER')]
class ProfileController extends RestController
{
    use ApiView, SingleDetailApiViewMixin, SingleCreateAndUpdateApiViewMixin;

    protected array $acceptedCreateProperties = ['nickname', 'avatar', 'metadata'];
    protected array $acceptedUpdateProperties = ['nickname', 'avatar', 'metadata'];

    protected function commonFilter(): array
    {
        $user = $this->getUser();
        return $user instanceof User ? ['user' => $user] : ['id' => -1];
    }

    protected function defaultCreateValues(): array
    {
        $user = $this->getUser();
        return ['user' => $user, 'level' => Profile::LEVEL_BRONZE];
    }
}
```

**Users can only modify `nickname`, `avatar`, and `metadata`.** Level changes require admin via `/manage/profiles`.
`SingleCreateAndUpdateApiViewMixin` now supports `acceptedCreateProperties`/`acceptedUpdateProperties`/
`requiredCreateProperties`/`requiredUpdateProperties` — the same contract as `CreateApiViewMixin` and
`UpdateApiViewMixin`.

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/v1/app/profiles` | ROLE_USER | View own profile |
| PUT | `/api/v1/app/profiles` | ROLE_USER | Update own profile (nickname, avatar, metadata only) |

ProfileService
--------------

`App\Identity\Service\ProfileService` extends `BaseService` and adds:

| Method | Description |
|--------|-------------|
| `joinAsMember(User $user)` | Idempotent profile creation at LEVEL_BRONZE. Returns existing record if user already has a profile |

Security Considerations
-----------------------
- Use HTTPS only
- Keep private key & secrets out of repo; use secret manager
- Hash refresh tokens with HMAC-SHA256 using REFRESH_TOKEN_SECRET
- OTPs are 6-digit numeric, stored as hash in Redis, one-time use, TTL 5 minutes
- Implement rate-limits: per-phone, per-IP, and per-account limits
- Detect refresh token reuse: if a revoked/replaced token is used, revoke all user tokens

Aliyun SMS Integration
----------------------
- Aliyun SDK (alibabacloud/client) will be used. The provider reads keys from env.
- Templates must be created and approved in Aliyun console. Template variables: {code}
- We provide ALIYUN_SMS_DRY_RUN for staging to avoid real sends

Implementation notes
--------------------
- Namespace: App\Identity
- Paths: src/Identity/{Entity,Repository,Service,Sms,Security,Controller,Resources}
- Services registered under src/Identity/Resources/config/services_identity.yaml
- Tests: unit tests for TokenManager, OtpService, and **UserService**; integration tests for all endpoints

Test Coverage
-------------

| File | Type | Coverage |
|------|------|----------|
| `UserTest` | Unit (9 tests) | User entity: interfaces, email/username normalization, phone, roles, password, erase, id, toString |
| `ProfileTest` | Unit (21 tests) | Profile entity: constructor defaults, levels, UUID, user/level setters, joinedAt, PrePersist, touch, toString / toStringPrefersNickname, nickname/avatar/metadata accessors, default nulls |
| `UserProfileListenerTest` | Unit (4 tests) | Auto-creates Profile on User persist, skips when exists, default LEVEL_BRONZE, ignores non-User entities |
| `ProfileRepositoryTest` | Integration (9 tests) | findById, findByUser, findByUserId, findByLevel, findByLevelOrAbove, nickname storage, edge cases |
| `ProfileServiceTest` | Unit (11 tests) | new(), get() by id/criteria, update() persist/flush/fields + clear, remove(), joinAsMember create/idempotent/default |
| `ProfileControllerTest` (Manage) | Unit (5 tests) | Unauthenticated access rejection for create/list/detail/update/delete |
| `ProfileControllerTest` (App) | Unit (10 tests) | GET unauthenticated/no profile/existing; PUT unauthenticated/create/existing/level rejected/nickname/unknown fields filtered/defaultCreateValues |
| `SingleCreateAndUpdateApiViewMixinTest` | Unit (10 tests) | Pass-through (no props), acceptedCreateProperties filter, acceptedUpdateProperties filter, requiredCreateProperties throw/pass, requiredUpdateProperties throw/pass, combined required+accepted, empty accepted no-op |
| `UserServiceTest` | Unit (28 tests) | register, changePassword, adminChangePassword, updateProfile, update password hashing |
| `UserControllerTest` | Unit (3 tests) | Unauthenticated access rejection for all actions |
| `UserApiIntegrationTest` | Integration (45 tests) | Register flow, login, profile, change-password, update-profile, manage CRUD, wallet deposit, transfer, balance, reconcile |
| `AuthControllerTest` | Unit (existing) | Login, logout, refresh, OTP verification |

Next steps
----------
I will generate the code patch implementing the module (files, migrations, README) and post it for review. After you approve, I will commit the changes to the repository in small steps with tests and migrations.
