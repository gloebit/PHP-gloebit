<?php
/**
 * Gloebit - minimal PHP OAuth2 example.
 *
 * Demonstrates the OAuth2 authorization-code flow against Gloebit:
 *   1. redirect the user to Gloebit to authorize,
 *   2. exchange the returned code for an access token,
 *   3. call a protected endpoint (/id) with that token.
 *
 * Dependency-free: plain curl + JSON, PHP 7.2+. (The old version depended on
 * adoy/PHP-OAuth2; that is no longer required.)
 *
 * Register an app in the Gloebit merchant tools to get a consumer key/secret,
 * and set its OAuth redirect URI to match $REDIRECT_URI below.
 *
 * SCOPE: this example covers authentication + identity only. Charging a user's
 * gloebits (what a Blesta / billing payment module needs) uses the transaction
 * API and is added on top of this flow -- see README.md.
 */

// ---- Config -----------------------------------------------------------------
// Prefer environment variables; the fallbacks are obvious placeholders.
$GLOEBIT_BASE  = getenv('GLOEBIT_BASE')          ?: 'https://www.gloebit.com';
$CLIENT_ID     = getenv('GLOEBIT_CLIENT_ID')     ?: 'your-consumer-key';
$CLIENT_SECRET = getenv('GLOEBIT_CLIENT_SECRET') ?: 'your-consumer-secret';
$REDIRECT_URI  = getenv('GLOEBIT_REDIRECT_URI')  ?: 'http://localhost/gloebit-php-example.php';
$SCOPE         = 'id';   // space-separated, e.g. 'id inventory'. Payments will need the transact scope (Fuller).

$AUTHORIZE_URL    = $GLOEBIT_BASE . '/oauth2/authorize';
$ACCESS_TOKEN_URL = $GLOEBIT_BASE . '/oauth2/access-token';
$ID_URL           = $GLOEBIT_BASE . '/id';

// ---- Tiny curl helper (returns [http_status, body]) -------------------------
function gloebit_http($method, $url, array $headers = array(), $body = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('curl error requesting ' . $url . ': ' . $err);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($status, $resp);
}

function gloebit_fail($msg) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre>Gloebit OAuth error: ' . htmlspecialchars($msg) . "\n";
    echo '<a href="' . htmlspecialchars($_SERVER['PHP_SELF']) . '">Retry</a></pre>';
    exit;
}

// ---- Flow -------------------------------------------------------------------
if (isset($_GET['error'])) {
    gloebit_fail($_GET['error']);
}

if (!isset($_GET['code'])) {
    // Step 1: send the user to Gloebit to authorize.
    // In real use, persist $state in the session and verify it on return (CSRF).
    $state = bin2hex(random_bytes(16));
    $params = array(
        'response_type' => 'code',
        'client_id'     => $CLIENT_ID,
        'redirect_uri'  => $REDIRECT_URI,
        'scope'         => $SCOPE,
        'state'         => $state,
    );
    header('Location: ' . $AUTHORIZE_URL . '?' . http_build_query($params));
    exit;
}

// Step 2: exchange the authorization code for an access token.
// The Gloebit token endpoint reads the client credentials and scope from the
// POST body (grant_type, code, redirect_uri, client_id, client_secret, scope) --
// not from an HTTP Basic header.
list($status, $tokenBody) = gloebit_http(
    'POST',
    $ACCESS_TOKEN_URL,
    array(
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ),
    http_build_query(array(
        'grant_type'    => 'authorization_code',
        'code'          => $_GET['code'],
        'redirect_uri'  => $REDIRECT_URI,
        'client_id'     => $CLIENT_ID,
        'client_secret' => $CLIENT_SECRET,
        'scope'         => $SCOPE,
    ))
);

$token = json_decode($tokenBody, true);
if ($status !== 200 || !is_array($token) || empty($token['access_token'])) {
    gloebit_fail('token exchange failed (HTTP ' . $status . '): ' . $tokenBody);
}
$accessToken = $token['access_token'];

// Step 3: call a protected endpoint with the bearer token.
list($idStatus, $idBody) = gloebit_http(
    'GET',
    $ID_URL,
    array(
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
    )
);

header('Content-Type: text/html; charset=utf-8');
echo '<h3>Access token</h3><pre>'
   . htmlspecialchars(json_encode($token, JSON_PRETTY_PRINT)) . '</pre>';
echo '<h3>Response from /id (HTTP ' . intval($idStatus) . ')</h3><pre>'
   . htmlspecialchars($idBody) . '</pre>';
