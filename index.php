<?php
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
    // Uses secp256k1 G = fixed generator (Gx, Gy)
    $Gx = gmp_init("79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798", 16);
    $Gy = gmp_init("483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8", 16);
    $privKey = gmp_init($privKeyHex, 16);
    $p = gmp_init("FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F", 16);

    // Elliptic curve multiplication (simple, not fast)
    function ec_double($x1, $y1, $p) {
        $s = gmp_mod(gmp_mul(gmp_mul(3, gmp_pow($x1, 2)), gmp_invert(gmp_mul(2, $y1), $p)), $p);
        $x3 = gmp_mod(gmp_sub(gmp_pow($s, 2), gmp_mul(2, $x1)), $p);
        $y3 = gmp_mod(gmp_sub(gmp_mul($s, gmp_sub($x1, $x3)), $y1), $p);
        return [$x3, $y3];
    }

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
            [$Qx, $Qy] = ec_double($Qx, $Qy, $p);
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
    $prefix = "1E"; // Dogecoin mainnet prefix = 0x1E
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
}

function decode_wif($wif) {
    $decoded = base58_decode($wif);
    if (!$decoded || strlen($decoded) < 68) return false;
    return substr($decoded, 2, 64); // Remove version + checksum
}

// Start of main logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wif = trim($_POST['wif']);
    $toAddr = trim($_POST['to']);

    $privHex = decode_wif($wif);
    if (!$privHex) die("Invalid WIF.");

    $pubKey = private_key_to_public_key($privHex);
    $fromAddr = pubkey_to_address($pubKey);

    // STEP 1: Fetch UTXOs
    $utxoData = json_decode(file_get_contents("https://sochain.com/api/v2/get_tx_unspent/DOGE/$fromAddr"), true);
    if (!$utxoData || $utxoData['status'] !== "success") die("UTXO fetch failed.");

    $inputs = $utxoData['data']['txs'];
    $total = 0;
    $rawInputs = "";
    foreach ($inputs as $utxo) {
        $total += $utxo['value'] * 100000000; // Convert DOGE to satoshis
        $rawInputs .= $utxo['txid'] . ":" . $utxo['output_no'] . "\n";
    }

    echo "<h3>From Address:</h3><pre>$fromAddr</pre>";
    echo "<h3>UTXOs:</h3><pre>$rawInputs</pre>";
    echo "<h3>Total Balance:</h3><pre>" . ($total / 100000000) . " DOGE</pre>";

    echo "<p><b>Transaction building and signing</b> not implemented in this demo due to complexity.</p>";
    echo "<p>But all needed data is here — from address, private key, pubkey, UTXOs, destination.</p>";
    echo "<p>For full TX building, consider using PHP-Bitcoin-signature, or a NodeJS helper script for raw TX creation.</p>";
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
