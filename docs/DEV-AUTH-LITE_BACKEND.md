# DEV-AUTH-LITE-BACKEND

Lite V1.0 uses Laravel's `web` guard, server sessions, and same-origin CSRF protection. Every authenticated user can access every Project; Project selection remains in the session as `current_project_id`. This task adds no project membership or role model.

## HTTP contract

| Route | Access | Result |
| --- | --- | --- |
| `GET /auth/login` | public | Inertia `Auth/Login`, with a safe relative `redirectTo` (default `/projects`) |
| `POST /api/auth/login` | public, 6 requests/minute/IP | `{data: {id, name, email}}`; invalid credentials return 422 with `errors.email` |
| `GET /api/auth/me` | authenticated | `{data: {id, name, email}}` |
| `POST /api/auth/logout` | authenticated | 204 with no body |

All other workbench pages and `/api/projects/...` endpoints require a session. Guests receive a login redirect for web pages and JSON 401 for API routes. Login rotates the session ID and clears any previous Project selection. Logout invalidates the session, rotates the CSRF token, and clears the Project selection. Passwords never appear in API responses.

## Create the first fixed user

The repository provides no public registration endpoint. An operator with console access can create a user using the official Tinker console:

```text
php artisan tinker
>>> $email = 'operator@example.com';
>>> $name = 'Operator';
>>> $password = \Laravel\Prompts\password('Initial password');
>>> $user = \App\Models\User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => $password]);
>>> unset($password);
```

Enter the password only at the hidden prompt. `User` hashes the password through its `hashed` cast. Repeating `firstOrCreate` leaves an existing account and its password unchanged. Use the same procedure with a distinct email to create another fixed user. Keep console access restricted to operators.

## CSRF and frontend integration

The browser must use same-origin cookies and send Laravel's CSRF token on POST requests. The W11 UI owns `resources/js/api/auth.ts` and `Auth/Login.vue`; this backend branch only supplies their frozen routes and payload. The backend test explicitly binds the real CSRF middleware with its PHPUnit bypass disabled, checking 419 for missing tokens and success for matching tokens. On expired sessions, the client should reload the login page to obtain a fresh token.

## Test boundary

Business Feature tests opt in to `tests/Concerns/AuthenticatesUser.php` so production middleware stays active. The task adds no auth bypass, JWT, token storage, registration, RBAC, or user-project membership. MySQL 8.4 runtime validation remains a separate release gate.
