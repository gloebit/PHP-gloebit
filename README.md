PHP-gloebit
===========

A minimal PHP interface to [Gloebit](https://www.gloebit.com/)'s API.

`gloebit-php-example.php` demonstrates the OAuth2 **authorization-code** flow:
it sends the user to Gloebit to authorize, exchanges the returned code for an
access token, and calls a protected endpoint (`/id`) with that token. It's
dependency-free (plain `curl` + JSON) and targets **production**
(`https://www.gloebit.com`).

> Scope: this example covers **authentication + identity** only. Charging a
> user's gloebits — what a billing integration such as a **Blesta payment
> module** needs — uses Gloebit's transaction API and is a follow-on layer built
> on top of this flow. See "Next: payments" below.

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
token and the `/id` response.

How it works
------------

1. **Authorize** — redirect to `GET /oauth2/authorize` with `response_type=code`,
   your `client_id`, `redirect_uri`, `scope`, and a `state`.
2. **Token** — `POST /oauth2/access-token` with `grant_type=authorization_code`
   and the returned `code`, authenticating via HTTP Basic (`client_id:secret`).
3. **Call the API** — send `Authorization: Bearer <access_token>` to protected
   endpoints (here, `GET /id`).

Next: payments (transaction API)
--------------------------------

To actually move gloebits (e.g. a Blesta payment gateway that charges a customer
for hosting), the OAuth token above is used with Gloebit's **transaction API** to
authorize and enact a charge. That layer is not in this example yet; it will be
added as a small reusable client on top of the same flow.
