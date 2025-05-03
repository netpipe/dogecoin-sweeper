<?php

server=1
rpcuser=dogeuser
rpcpassword=dogepass
rpcallowip=127.0.0.1
rpcport=22555

class DogecoinRPC {
    private $user;
    private $password;
    private $host;
    private $port;
    private $url;

    public function __construct($user, $password, $host = '127.0.0.1', $port = 22555) {
        $this->user = $user;
        $this->password = $password;
        $this->host = $host;
        $this->port = $port;
        $this->url = "http://{$host}:{$port}/";
    }

    private function rpcCall($method, $params = []) {
        $payload = json_encode([
            'jsonrpc' => '1.0',
            'id'      => 'phpclient',
            'method'  => $method,
            'params'  => $params
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->user}:{$this->password}");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            return ['error' => curl_error($ch)];
        }

        $decoded = json_decode($response, true);
        if (isset($decoded['error']) && $decoded['error'] !== null) {
            return ['error' => $decoded['error']];
        }

        return $decoded['result'];
    }

    public function getBalance($account = '*') {
        return $this->rpcCall('getbalance', [$account]);
    }

    public function listUnspent($min = 1, $max = 9999999, $addresses = []) {
        return $this->rpcCall('listunspent', [$min, $max, $addresses]);
    }

    public function sendRawTransaction($hex) {
        return $this->rpcCall('sendrawtransaction', [$hex]);
    }

    public function validateAddress($address) {
        return $this->rpcCall('validateaddress', [$address]);
    }

    public function createRawTransaction($inputs, $outputs) {
        return $this->rpcCall('createrawtransaction', [$inputs, $outputs]);
    }

    public function signRawTransactionWithWallet($hex) {
        return $this->rpcCall('signrawtransactionwithwallet', [$hex]);
    }
}
//require 'DogecoinRPC.php';

$rpc = new DogecoinRPC('dogeuser', 'dogepass');

// 1. Check balance
$balance = $rpc->getBalance();
echo "Balance: $balance DOGE\n";

// 2. Validate an address
$address = "D9fZfDcuXqM49hJGqf7gSXRZdFsQ5sUvuD";
$valid = $rpc->validateAddress($address);
echo "Is Valid: " . ($valid['isvalid'] ? 'Yes' : 'No') . "\n";

// 3. List UTXOs
$utxos = $rpc->listUnspent(1, 9999999, [$address]);
print_r($utxos);

// 4. Send raw transaction (example only - replace with real hex)
//$rawHex = "0100000001abcdef...";
//$sendResult = $rpc->sendRawTransaction($rawHex);
print_r($sendResult);
?>