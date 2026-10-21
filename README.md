PHP-gloebit
===========

A minimal PHP interface to [Gloebit](https://www.gloebit.com/)'s API.

`gloebit-php-example.php` demonstrates the OAuth2 **authorization-code** flow:
it sends the user to Gloebit to authorize, exchanges the returned code for an
access token, and calls two protected endpoints (`/user` and `/balance`) with
that token. It's dependency-free (plain `curl` + JSON) and targets **production**
(`https://www.gloebit.com`).

`gloebit-php-transact-example.php` builds on that flow to **charge** a user's
gloebits — what a billing integration such as a **Blesta payment module** needs.
See "Charging gloebits" below.

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
token and the `/user` and `/balance` responses.

How it works
------------

1. **Authorize** — redirect to `GET /oauth2/authorize` with `response_type=code`,
   your `client_id`, `redirect_uri`, `scope`, a `state`, and the app-user's
   `user`/`uid` (see below). The `state` is stored in the session and verified on
   return (CSRF protection); Gloebit echoes it back on the redirect.
2. **Token** — `POST /oauth2/access-token` with `grant_type=authorization_code`
   and the returned `code`. Gloebit reads `client_id`, `client_secret`, and
   `scope` from the **POST body** (not an HTTP Basic header).
3. **Call the API** — send `Authorization: Bearer <access_token>` to protected
   endpoints (here, `GET /user` and `GET /balance`).

Scopes
------

Request only what you need (space-separated in `scope`):

| Scope       | Grants access to                                         |
|-------------|----------------------------------------------------------|
| `balance`   | `/balance` — the user's gloebit balance                  |
| `transact`  | `/transact`, `/transact-u2u` — **charge / move gloebits** |
| `user`      | `/user` — the user's Gloebit user id                     |
| `inventory` | inventory items (legacy)                                 |
| `character` | character management (legacy)                            |
| `id`        | `/id` — **DEPRECATED, do not use** (triggers the legacy character step) |

A payment integration (Blesta, etc.) typically wants **`balance transact`** (plus
`user` if you want to identify the payer). This example requests `user balance`.

> **Avoid the `id` scope.** It's deprecated, and requesting it makes the authorize
> flow show a legacy "Edit character" page. Use `user` for identity instead.

App-user identity (`user` / `uid`)
----------------------------------

Pass, in the authorize request, your application's own identity for the end user:

- **`uid`** — a **stable identifier that is unique per app-user** (e.g. your
  Blesta client id). Gloebit links your app-user to their Gloebit account keyed on
  `(your app, uid)`, so **never reuse one value across users** — a second user
  authorizing with the same `uid` would try to link to the first user's app-user
  and the authorization **fails**. Do not hardcode a shared constant in production.
- **`user`** — a display name for that app-user.

This is how the OpenSim module passes the avatar. It establishes the app-user link
that transactions attribute to; it is **not** what controls the character prompt
(that's the deprecated `id` scope — see above).

Charging gloebits (`gloebit-php-transact-example.php`)
------------------------------------------------------

`gloebit-php-transact-example.php` builds on the auth flow above to **charge** a
user's gloebits via `POST /v2/transact`. It demonstrates the full two-phase
settlement a payment integration (e.g. a Blesta gateway) needs:

1. **Authorize** with scope `user balance transact`. The token response returns
   `app_user_id` and `user_id_on_app` — the two payer identifiers the charge needs.
2. **Charge** — `POST /v2/transact` with a **JSON body** and the bearer token. The
   synchronous response only means the charge was **accepted / queued**, *not*
   settled.
3. **Callbacks** — Gloebit's transaction processor then issues an **HTTP GET** to
   each of the three hold URLs you supplied, driving a two-phase commit:
   - `enact` — place the hold / begin delivery (must be idempotent),
   - `consume` — the charge **settled**; this is where you activate what was
     bought (e.g. extend a month of hosting),
   - `cancel` — rolled back; release the hold and do **not** activate.

   Each callback must reply **HTTP 200** with a JSON array: `[true]` on success,
   `[false, "pending"]` to ask the processor to **retry**, or `[false, "reason"]`
   for a permanent failure. **Sign each hold URL** and honor a callback only if its
   signature checks out: the `transaction-id` alone is not proof the request came
   from Gloebit. The example appends `sig=` (an HMAC-SHA256 of the transaction id
   and state, keyed with your app secret) and checks it with `hash_equals`. Also
   honor a callback only if its `transaction-id` matches one you initiated (the
   example keys this to a small record; a real integration uses its order/invoice
   in a database), and once a charge is canceled, refuse a later `enact` or
   `consume` for it. Make the `consume` activation idempotent
   (it can be retried), and log/persist each outcome so a dropped callback can be
   reconciled — a lost `consume` is a paid-but-unactivated order.

The one script plays three roles, distinguished by request params: a Gloebit
callback (`?gbcb=1&state=…&id=…&sig=…`), the return from authorize (`?code=…`), and the
user confirming the charge (`?do_charge=1`). Extra config env vars:
`GLOEBIT_CHARGE_AMOUNT` (whole gloebits, default `1`) and `GLOEBIT_ASSET_CODE`
(a description of the purchase).

### Testing note — the callbacks need a PUBLIC URL

Gloebit rejects hold URLs pointing at localhost / private IPs, so the
`enact`/`consume`/`cancel` callbacks can't reach a plain `php -S localhost`. To
exercise the full lifecycle, expose the script at a public HTTPS URL — e.g. a
Cloudflare Tunnel (`cloudflared tunnel --url http://localhost:8000`) — set
`GLOEBIT_REDIRECT_URI` to that public URL, and add it to the app's permitted
callback URI prefixes. Charging moves **real gloebits**, so use a test app and a
small `GLOEBIT_CHARGE_AMOUNT`.

See the Gloebit developer/integration docs at <https://dev.gloebit.com/> for the
full API reference.
