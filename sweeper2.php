<?php
// Define the getblock.io API details
$api_key = "61bf152c787849799b55b3f093b91f16"; // Replace with your getblock.io API key
$api_url = "https://api.getblock.io/doge/mainnet"; // For Dogecoin mainnet

// Function to make the API request
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
