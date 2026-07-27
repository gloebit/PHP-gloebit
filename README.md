PHP-gloebit
===========

A minimal PHP interface to [Gloebit](https://www.gloebit.com/)'s API.

`gloebit-php-example.php` demonstrates the OAuth2 **authorization-code** flow:
it sends the user to Gloebit to authorize, exchanges the returned code for an
access token, and calls two protected endpoints (`/id` and `/balance`) with that
token. It's dependency-free (plain `curl` + JSON) and targets **production**
(`https://www.gloebit.com`).

> This example is a **base to build on**. It covers authentication and two
> read-only calls. Charging a user's gloebits — what a billing integration such
> as a **Blesta payment module** needs — adds the `transact` scope and the
> transaction API on top of this same flow (see "Next: payments" below).

Requirements
------------

- PHP 7.2+ with the `curl` and `json` extensions (both are standard).
- A Gloebit app (consumer key + secret) with an OAuth **redirect URI**
  registered to match where you host this script.

Setup
-----

1. Register an app in the Gloebit merchant tools to get a **consumer key** and
   **secret**, and set its OAuth redirect URI (e.g. `http://localhost:8000/gloebit-php-example.php`).
2. Provide credentials via environment variables (preferred) or by editing the
   config block at the top of `gloebit-php-example.php`:

   ```sh
   export GLOEBIT_CLIENT_ID='your-consumer-key'
   export GLOEBIT_CLIENT_SECRET='your-consumer-secret'
   export GLOEBIT_REDIRECT_URI='http://localhost:8000/gloebit-php-example.php'
   # identify the end user (see "App-user identity" below):
   export GLOEBIT_APP_USER_ID='your-unique-per-user-id'
   export GLOEBIT_APP_USER_NAME='Display Name'
   # optional: export GLOEBIT_BASE='https://www.gloebit.com'
   ```

Run
---

Using PHP's built-in server:

```sh
php -S localhost:8000
```

Then open <http://localhost:8000/gloebit-php-example.php>. You'll be redirected
to Gloebit to authorize, then returned to the page, which prints the access
token and the `/id` and `/balance` responses.

How it works
------------

1. **Authorize** — redirect to `GET /oauth2/authorize` with `response_type=code`,
   your `client_id`, `redirect_uri`, `scope`, a `state`, and the app-user's
   `user`/`uid` (see below).
2. **Token** — `POST /oauth2/access-token` with `grant_type=authorization_code`
   and the returned `code`. Gloebit reads `client_id`, `client_secret`, and
   `scope` from the **POST body** (not an HTTP Basic header).
3. **Call the API** — send `Authorization: Bearer <access_token>` to protected
   endpoints (here, `GET /id` and `GET /balance`).

Scopes
------

Request only what you need (space-separated in `scope`):

| Scope       | Grants access to                                   |
|-------------|----------------------------------------------------|
| `id`        | `/id` — the user's identifying info (name)          |
| `balance`   | `/balance` — the user's gloebit balance             |
| `transact`  | `/transact`, `/transact-u2u` — **charge / move gloebits** |
| `user`      | `/user-details` — gloebit user id only (less identifying) |
| `inventory` | inventory items (legacy)                            |
| `character` | character management (legacy)                       |

A payment integration (Blesta, etc.) typically wants **`balance transact`** (and
`id` if you want the payer's name). This example requests `id balance`.

App-user identity (`user` / `uid`) — and the "character" prompt
--------------------------------------------------------------

Gloebit associates each of your users with an **app-user** (historically called a
"character") keyed on `(your app, uid)`. Pass, in the authorize request:

- **`uid`** — a **stable identifier that is unique per app-user** (e.g. your
  Blesta client id). **Never reuse one value across users**: the app-user is keyed
  on `(app, uid)`, so a second user authorizing with the same `uid` would try to
  link to the first user's app-user and the authorization **fails**. Do not
  hardcode a shared constant in production.
- **`user`** — a display name for that app-user.

Passing both makes Gloebit **auto-associate the app-user and skip its interactive
"create a character" step** (this is how the OpenSim module passes the avatar).
Omit them and Gloebit prompts the user to create a character on first authorize.

Next: payments (transaction API)
--------------------------------

To actually move gloebits (e.g. a Blesta payment gateway that charges a customer
for hosting), add the `transact` scope and use the access token with Gloebit's
**transaction API** (`/transact`) to authorize and enact a charge. That layer is
not in this example yet; it will be added as a small reusable client on top of
this same flow.

See the Gloebit developer/integration docs at <https://dev.gloebit.com/> for the
full API reference.
