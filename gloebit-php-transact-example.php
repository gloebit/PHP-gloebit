<?php
/**
 * Gloebit - PHP transaction (charge) example.
 *
 * Builds on gloebit-php-example.php (the OAuth2 auth flow) and adds CHARGING a
 * user's gloebits via POST /v2/transact -- what a billing integration such as a
 * Blesta payment module needs. It demonstrates the full two-phase-commit charge:
 *
 *   1. AUTH   - the same authorization-code flow as the auth example, but with
 *               scope 'user balance transact'. The token response hands back an
 *               'app_user_id' (and 'user_id_on_app'); the charge step needs both.
 *   2. CHARGE - POST /v2/transact (a JSON body) with the access token. The
 *               SYNCHRONOUS response only means "accepted / queued" -- NOT settled.
 *   3. CALLBACKS - Gloebit's transaction processor then issues an HTTP GET to each
 *               hold URL we supplied, to drive the charge: enact -> consume (settled)
 *               or enact -> cancel (rolled back). 'consume' is the authoritative
 *               "payment completed" signal -- that is where a real integration
 *               ACTIVATES the purchased service (e.g. extends a month of hosting).
 *
 * This one script plays all three roles, distinguished by request params:
 *   - ?gbcb=1&state=<enact|consume|cancel>&id=<txn>  -> a Gloebit callback (role 3)
 *   - ?code=... (+ oauth state)                      -> return from authorize (role 1)
 *   - ?do_charge=1                                   -> user confirmed the charge (role 2)
 *
 * IMPORTANT (testing): the hold/callback URLs must be PUBLICLY reachable -- Gloebit
 * rejects localhost/private IPs. `php -S localhost:8000` is fine for auth and for
 * sending the charge, but the enact/consume/cancel callbacks will NOT reach a local
 * server. For end-to-end callback testing, expose this script at a public HTTPS URL --
 * e.g. a Cloudflare Tunnel (`cloudflared tunnel --url http://localhost:8000`) -- and
 * set GLOEBIT_REDIRECT_URI to that public URL.
 *
 * NOTE: charging moves real gloebits. Use a test app and a small amount.
 */

// ---- Config -----------------------------------------------------------------
$GLOEBIT_BASE  = getenv('GLOEBIT_BASE')          ?: 'https://www.gloebit.com';
$CLIENT_ID     = getenv('GLOEBIT_CLIENT_ID')     ?: 'your-consumer-key';
$CLIENT_SECRET = getenv('GLOEBIT_CLIENT_SECRET') ?: 'your-consumer-secret';
$REDIRECT_URI  = getenv('GLOEBIT_REDIRECT_URI')  ?: 'http://localhost:8000/gloebit-php-transact-example.php';

// 'transact' is what lets us charge; 'user'/'balance' are for identity + display.
$SCOPE = 'user balance transact';

// App-user identity sent at authorize (see gloebit-php-example.php for details).
// 'uid' MUST be stable and unique per app-user.
$APP_USER_ID   = getenv('GLOEBIT_APP_USER_ID')   ?: 'example-app-user-id';
$APP_USER_NAME = getenv('GLOEBIT_APP_USER_NAME') ?: 'Example App User';

// What to charge when the user confirms.
$CHARGE_AMOUNT = (int)(getenv('GLOEBIT_CHARGE_AMOUNT') ?: 1);      // gloebits (whole number)
$ASSET_CODE    = getenv('GLOEBIT_ASSET_CODE') ?: 'Example charge'; // description of the purchase

$AUTHORIZE_URL    = $GLOEBIT_BASE . '/oauth2/authorize';
$ACCESS_TOKEN_URL = $GLOEBIT_BASE . '/oauth2/access-token';
$TRANSACT_URL     = $GLOEBIT_BASE . '/v2/transact';

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
        throw new RuntimeException('curl error requesting ' . $url . ': ' . $err);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // No curl_close(): it's a no-op since PHP 8.0 and deprecated in 8.5; the
    // handle is freed automatically when $ch goes out of scope.
    return array($status, $resp);
}

function gloebit_fail($msg) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre>Gloebit error: ' . htmlspecialchars($msg) . "\n";
    echo '<a href="' . htmlspecialchars(basename(__FILE__)) . '">Start over</a></pre>';
    exit;
}

set_exception_handler(function ($e) { gloebit_fail($e->getMessage()); });

// ---- A tiny record of the charges WE initiated ------------------------------
// A callback is trusted only if its transaction-id matches one we started: the
// (unguessable) transaction-id is our handle on the charge. A real integration
// stores this in its database keyed to the order/invoice; here we use a temp file.
// The id is sanitized before it touches the filesystem (it arrives from an
// external request in the callback role).
function gloebit_txn_path($id) {
    $safe = preg_replace('/[^A-Fa-f0-9-]/', '', $id);
    return sys_get_temp_dir() . '/gloebit-php-txn-' . $safe . '.json';
}
function gloebit_txn_store($id, array $data) {
    file_put_contents(gloebit_txn_path($id), json_encode($data), LOCK_EX);
}
function gloebit_txn_load($id) {
    $p = gloebit_txn_path($id);
    if ($id === '' || !is_file($p)) {
        return null;
    }
    $d = json_decode(file_get_contents($p), true);
    return is_array($d) ? $d : null;
}
function gloebit_txn_set_status($id, $status) {
    $d = gloebit_txn_load($id);
    if ($d === null) { $d = array(); }
    $d['status'] = $status;
    gloebit_txn_store($id, $d);
}

// RFC-4122 v4 UUID for the transaction-id (idempotency key for the charge).
function gloebit_uuid4() {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

// A callback URL for this script. WE choose the format; Gloebit's processor issues an
// HTTP GET to exactly the URL we supply, so we tag it with a marker + the state + txn id.
function gloebit_callback_url($state, $txnId) {
    global $REDIRECT_URI;
    $sep = (strpos($REDIRECT_URI, '?') === false) ? '?' : '&';
    return $REDIRECT_URI . $sep . 'gbcb=1&state=' . rawurlencode($state)
         . '&id=' . rawurlencode($txnId);
}

// =============================================================================
// ROLE 3 -- Gloebit callback (enact / consume / cancel). Must run first: this is
// Gloebit's transaction processor calling us, with no session and no OAuth.
//
// Contract (mirrors the OpenSim module's handler): read 'id' and 'state', do the
// work, and reply HTTP 200 application/json with a JSON array:
//   [true]              -> this stage succeeded
//   [false, "pending"]  -> temporary failure; the processor will RETRY
//   [false, "reason"]   -> permanent failure
// =============================================================================
if (isset($_GET['gbcb'])) {
    http_response_code(200);   // the reply contract below is only meaningful as a 200
    header('Content-Type: application/json');
    $id    = isset($_GET['id'])    ? $_GET['id']    : '';
    $state = isset($_GET['state']) ? $_GET['state'] : '';

    // Log every callback (id + state). A real integration persists the enact/consume/
    // cancel outcome keyed to its order/invoice so it can reconcile against Gloebit if a
    // callback is ever dropped -- a lost 'consume' is a paid-but-unactivated order.
    error_log("gloebit callback: state=$state id=$id");

    // Only act on a charge we actually initiated.
    $rec = gloebit_txn_load($id);
    if ($rec === null) {
        error_log("gloebit callback: unknown transaction id=$id");
        echo json_encode(array(false, 'unknown transaction'));
        exit;
    }
    $current = isset($rec['status']) ? $rec['status'] : '';

    // Provisioning can fail; keep the JSON-array reply contract intact by turning any
    // exception into a retriable [false, "pending"] (still HTTP 200) rather than letting
    // the global exception handler emit HTML/400, which the processor can't interpret.
    try {
        switch ($state) {
            case 'enact':
                // First phase: place the hold / begin delivery. For a pure payment there
                // is nothing to deliver, so we just record the hold. MUST be idempotent
                // (the processor may retry enact). Return [false, "pending"] if your own
                // provisioning is momentarily unavailable and you want a retry.
                gloebit_txn_set_status($id, 'enacted');
                echo json_encode(array(true));
                break;

            case 'consume':
                // Second phase: the charge SETTLED. This is where a real integration
                // activates what was bought -- e.g. extend the subscription / mark the
                // invoice paid. Make the activation IDEMPOTENT: consume can be delivered
                // more than once (retries), so skip the hook if already consumed.
                if ($current !== 'consumed') {
                    gloebit_txn_set_status($id, 'consumed');   // <- your "activate service" hook
                }
                echo json_encode(array(true));
                break;

            case 'cancel':
                // The charge was rolled back. Release any hold and do NOT activate.
                // Never roll back an already-settled (consumed) charge.
                if ($current !== 'consumed') {
                    gloebit_txn_set_status($id, 'canceled');
                }
                echo json_encode(array(true));
                break;

            default:
                echo json_encode(array(false, 'unknown state'));
        }
    } catch (Throwable $e) {
        error_log('gloebit callback error id=' . $id . ': ' . $e->getMessage());
        echo json_encode(array(false, 'pending'));   // transient -- ask the processor to retry
    }
    exit;
}

// =============================================================================
// ROLES 1 & 2 -- interactive browser flow (auth, then charge).
// =============================================================================
session_start();

if (isset($_GET['error'])) {
    gloebit_fail($_GET['error']);
}

// ROLE 2 -- the user confirmed the charge (we already have a token in the session).
if (isset($_GET['do_charge'])) {
    if (empty($_SESSION['gloebit_access_token'])) {
        gloebit_fail('no access token in session -- start over to authorize first.');
    }
    $accessToken  = $_SESSION['gloebit_access_token'];
    $appUserId    = isset($_SESSION['gloebit_app_user_id'])   ? $_SESSION['gloebit_app_user_id']   : null;
    $userIdOnApp  = isset($_SESSION['gloebit_user_id_on_app']) ? $_SESSION['gloebit_user_id_on_app'] : $APP_USER_ID;

    // v2 requires app-user-id (validated against the token). If the token didn't return
    // one, fail clearly rather than sending null and getting an opaque server rejection.
    if (empty($appUserId)) {
        gloebit_fail('token returned no app_user_id -- re-authorize before charging.');
    }
    // A charge moves real gloebits: require a positive whole-number amount.
    if ($CHARGE_AMOUNT < 1) {
        gloebit_fail('GLOEBIT_CHARGE_AMOUNT must be a positive whole number.');
    }

    // The transaction-id is the server's IDEMPOTENCY KEY: the same id de-dupes to a single
    // charge, a new id is a new charge. So a given charge must use a STABLE id, NOT a fresh
    // one per request -- otherwise a browser refresh / back-button re-hits ?do_charge=1 and
    // charges AGAIN. This demo pins one id in the session (a fresh authorization clears it,
    // so you can run another charge). A real integration derives the transaction-id from its
    // order/invoice, which makes retries naturally idempotent and refresh-safe.
    $alreadyCharged = !empty($_SESSION['gloebit_charge_txn']);
    $txnId = $alreadyCharged ? $_SESSION['gloebit_charge_txn'] : gloebit_uuid4();
    $status = null;
    $body   = null;

    if (!$alreadyCharged) {
        // Record the txn before sending so the async callbacks can be tied back to it.
        gloebit_txn_store($txnId, array('status' => 'submitted', 'amount' => $CHARGE_AMOUNT));
        $_SESSION['gloebit_charge_txn'] = $txnId;

        $transaction = array(
            'version'                 => 1,
            'application-key'         => $CLIENT_ID,
            'request-created'         => time(),
            'transaction-id'          => $txnId,
            'username-on-application'  => $APP_USER_NAME,
            // Identify the payer -- both must match the token's user (v2 validates this):
            'app-user-id'             => $appUserId,     // from the token response
            'buyer-id-on-application' => $userIdOnApp,   // the 'uid' used at authorize
            // What is being charged:
            'gloebit-balance-change'  => $CHARGE_AMOUNT, // whole gloebits to charge
            'asset-code'              => $ASSET_CODE,
            'asset-quantity'          => 1,
            // Two-phase-commit callbacks (must be publicly reachable -- see file header):
            'asset-enact-hold-url'    => gloebit_callback_url('enact',   $txnId),
            'asset-consume-hold-url'  => gloebit_callback_url('consume', $txnId),
            'asset-cancel-hold-url'   => gloebit_callback_url('cancel',  $txnId),
        );

        list($status, $body) = gloebit_http(
            'POST',
            $TRANSACT_URL,
            array(
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ),
            json_encode($transaction)   // /v2/transact expects a JSON body
        );
    }

    header('Content-Type: text/html; charset=utf-8');
    if ($alreadyCharged) {
        echo '<h3>Charge already submitted</h3>';
        echo '<p>A charge for this session was already sent; refreshing does not charge again.</p>';
    } else {
        $ok = ($status >= 200 && $status < 300);
        echo '<h3>Charge ' . ($ok ? 'submitted' : 'FAILED') . ' (HTTP ' . intval($status) . ')</h3>';
        if (!$ok) {
            echo '<p><strong>The charge was not accepted</strong> -- see the response below.</p>';
        }
        echo '<pre>' . htmlspecialchars((string)$body) . '</pre>';
        if ($ok) {
            echo '<p>This means the charge was <em>accepted / queued</em>. The authoritative '
               . 'result arrives asynchronously via the <code>consume</code> (settled) or '
               . '<code>cancel</code> (rolled back) callback to this script -- which only '
               . 'works if this URL is publicly reachable.</p>';
        }
    }
    echo '<p>transaction-id: <code>' . htmlspecialchars($txnId) . '</code></p>';
    $rec = gloebit_txn_load($txnId);
    echo '<p>Local status for this transaction: <strong>'
       . htmlspecialchars($rec ? $rec['status'] : 'unknown')
       . '</strong> (updated by the callbacks when reachable).</p>';
    exit;
}

// ROLE 1a -- no code yet: send the user to Gloebit to authorize.
if (!isset($_GET['code'])) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['gloebit_oauth_state'] = $state;
    $params = array(
        'response_type' => 'code',
        'client_id'     => $CLIENT_ID,
        'redirect_uri'  => $REDIRECT_URI,
        'scope'         => $SCOPE,
        'state'         => $state,
        'user'          => $APP_USER_NAME,
        'uid'           => $APP_USER_ID,
    );
    header('Location: ' . $AUTHORIZE_URL . '?' . http_build_query($params));
    exit;
}

// ROLE 1b -- back from authorize: verify state, then exchange the code for a token.
$expectedState = isset($_SESSION['gloebit_oauth_state']) ? $_SESSION['gloebit_oauth_state'] : null;
unset($_SESSION['gloebit_oauth_state']);   // one-time use
if (!$expectedState || !isset($_GET['state']) || !hash_equals($expectedState, $_GET['state'])) {
    gloebit_fail('state mismatch -- possible CSRF or an expired session. Start over.');
}

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

// Stash what the charge step needs. The token response carries 'app_user_id' and
// 'user_id_on_app' -- the two identifiers /v2/transact requires for the payer.
$_SESSION['gloebit_access_token']   = $token['access_token'];
$_SESSION['gloebit_app_user_id']    = isset($token['app_user_id'])   ? $token['app_user_id']   : null;
$_SESSION['gloebit_user_id_on_app'] = isset($token['user_id_on_app']) ? $token['user_id_on_app'] : $APP_USER_ID;
// A fresh authorization permits a new charge -- clear ROLE 2's one-charge-per-
// authorization guard so re-authorizing lets you run the charge again.
unset($_SESSION['gloebit_charge_txn']);

// Show a confirmation page instead of charging automatically.
header('Content-Type: text/html; charset=utf-8');
echo '<h3>Authorized</h3>';
echo '<p>Scope: <code>' . htmlspecialchars(isset($token['scope']) ? $token['scope'] : '') . '</code></p>';
echo '<p>Ready to charge <strong>' . intval($CHARGE_AMOUNT) . ' G$</strong> for "'
   . htmlspecialchars($ASSET_CODE) . '".</p>';
echo '<p><a href="' . htmlspecialchars(basename(__FILE__)) . '?do_charge=1">Charge now</a></p>';
