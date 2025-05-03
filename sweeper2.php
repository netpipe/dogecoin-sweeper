<?php
// Define the getblock.io API details
$api_key = "61bf152c787849799b55b3f093b91f16"; // Replace with your getblock.io API key
$api_url = "https://api.getblock.io/doge/mainnet"; // For Dogecoin mainnet
ini_set('display_errors', 1);
error_reporting(E_ALL);// Function to make the API request
function api_request($endpoint, $data = []) {
    global $api_url, $api_key;

    $ch = curl_init();
    $url = $api_url . $endpoint;

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function base58_decode($input) {
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $decoded = gmp_init(0);
    $base = strlen($alphabet);
    for ($i = 0; $i < strlen($input); $i++) {
        $pos = strpos($alphabet, $input[$i]);
        if ($pos === false) return false;
        $decoded = gmp_add(gmp_mul($decoded, $base), $pos);
    }
    return gmp_strval($decoded, 16);
}

function hash160($data) {
    return substr(hash('ripemd160', hash('sha256', hex2bin($data)), true), 0);
}

function private_key_to_public_key($privKeyHex) {
    $Gx = gmp_init("79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798", 16);
    $Gy = gmp_init("483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8", 16);
    $privKey = gmp_init($privKeyHex, 16);
    $p = gmp_init("FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F", 16);

    function ec_add($x1, $y1, $x2, $y2, $p) {
        $s = gmp_mod(gmp_mul(gmp_sub($y2, $y1), gmp_invert(gmp_sub($x2, $x1), $p)), $p);
        $x3 = gmp_mod(gmp_sub(gmp_pow($s, 2), gmp_add($x1, $x2)), $p);
        $y3 = gmp_mod(gmp_sub(gmp_mul($s, gmp_sub($x1, $x3)), $y1), $p);
        return [$x3, $y3];
    }

    function ec_mul($k, $x, $y, $p) {
        $k_bin = strrev(gmp_strval($k, 2));
        $Qx = $x; $Qy = $y;
        for ($i = 1; $i < strlen($k_bin); $i++) {
            [$Qx, $Qy] = ec_add($Qx, $Qy, $x, $y, $p);
            if ($k_bin[$i] == '1') {
                [$Qx, $Qy] = ec_add($Qx, $Qy, $x, $y, $p);
            }
        }
        return [gmp_strval($Qx, 16), gmp_strval($Qy, 16)];
    }

    [$pubX, $pubY] = ec_mul($privKey, $Gx, $Gy, $p);
    return "04" . str_pad($pubX, 64, "0", STR_PAD_LEFT) . str_pad($pubY, 64, "0", STR_PAD_LEFT);
}

function pubkey_to_address($pubKeyHex) {
    $hash160 = hash160($pubKeyHex);
    $prefix = "1E";
    $payload = $prefix . bin2hex($hash160);
    $checksum = substr(hash('sha256', hex2bin(hash('sha256', hex2bin($payload)))), 0, 8);
    return base58_encode($payload . $checksum);
}

function base58_encode($hex) {
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $num = gmp_init($hex, 16);
    $encoded = '';
    while (gmp_cmp($num, 0) > 0) {
        list($num, $rem) = gmp_div_qr($num, 58);
        $encoded .= $alphabet[gmp_intval($rem)];
    }
    return strrev($encoded);
}function decode_wif($wif) {
    $decoded = base58_decode($wif);
    if (!$decoded || strlen($decoded) < 68) return false;
    return substr($decoded, 2, 64); // Remove version + checksum
}
// Function to fetch UTXOs
function get_utxos($address) {
    return api_request('/address/utxos', ['address' => $address]);
}

// Function to create a raw transaction
function create_raw_transaction($inputs, $outputs) {
    return api_request('/transaction/create', [
        'inputs' => $inputs,
        'outputs' => $outputs
    ]);
}

// Function to sign a raw transaction
function sign_raw_transaction($tx_hex) {
    return api_request('/transaction/sign', ['transaction' => $tx_hex]);
}

// Function to send a signed transaction
function send_raw_transaction($tx_hex) {
    return api_request('/transaction/send', ['transaction' => $tx_hex]);
}

// Main Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get WIF and destination address from the form
    $wif = trim($_POST['wif']);
    $toAddr = trim($_POST['to']);

    // Decode the private key from WIF (using the function you already have)
    $privHex = decode_wif($wif);
    if (!$privHex) die("Invalid WIF.");

    // Generate public key from the private key
    $pubKey = private_key_to_public_key($privHex);

    // Fetch the Dogecoin address from the public key
    $fromAddr = pubkey_to_address($pubKey);

    // STEP 1: Fetch UTXOs for the address
    $utxoData = get_utxos($fromAddr);
    if (!$utxoData || empty($utxoData['result'])) die("UTXO fetch failed.");

    // Process the UTXOs
    $inputs = [];
    $total = 0;
    foreach ($utxoData['result'] as $utxo) {
        $inputs[] = [
            'txid' => $utxo['txid'],
            'vout' => $utxo['vout']
        ];
        $total += $utxo['amount'] * 100000000; // Convert DOGE to satoshis
    }

    // STEP 2: Prepare the output for the transaction
    $outputs = [
        $toAddr => $total / 100000000 // Send all DOGE to the destination
    ];

    // STEP 3: Create the raw transaction
    $rawTx = create_raw_transaction($inputs, $outputs);

    if (!$rawTx || !isset($rawTx['result']['transaction'])) die("Failed to create raw transaction.");

    $txHex = $rawTx['result']['transaction'];

    // STEP 4: Sign the raw transaction
    $signedTx = sign_raw_transaction($txHex);

    if (!$signedTx || !isset($signedTx['result']['transaction'])) die("Failed to sign transaction.");

    $signedTxHex = $signedTx['result']['transaction'];

    // STEP 5: Broadcast the signed transaction
    $broadcastResponse = send_raw_transaction($signedTxHex);

    if (!$broadcastResponse || !isset($broadcastResponse['result']['txid'])) die("Transaction broadcasting failed.");

    // Show the transaction ID
    echo "<h3>Transaction Sent Successfully!</h3>";
    echo "<pre>Transaction ID: " . $broadcastResponse['result']['txid'] . "</pre>";
}

?>

<!DOCTYPE html>
<html>
<head><title>Dogecoin Paper Wallet Sweeper</title></head>
<body>
    <h2>Dogecoin Paper Wallet Sweeper</h2>
    <form method="post">
        <label>Private Key (WIF):<br><input type="text" name="wif" style="width:400px" required></label><br><br>
        <label>Destination Address:<br><input type="text" name="to" style="width:400px" required></label><br><br>
        <input type="submit" value="Sweep Wallet">
    </form>
</body>
</html>