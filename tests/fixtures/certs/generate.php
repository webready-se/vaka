<?php

/**
 * Regenerates the test certificates in this directory (they are checked in,
 * so tests never need openssl at run time): php tests/fixtures/certs/generate.php
 *
 *   ca.pem             a test certificate authority
 *   valid.pem/.key     signed by it, for 127.0.0.1 and localhost
 *   wrong-name.pem/.key signed by it, for other.example only
 *   self-signed.pem/.key not signed by anyone
 *
 * Valid for ten years from when they were generated.
 */

$dir = __DIR__;
$days = 3650;
$config = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

function leaf_config(string $dir, string $san): array
{
    $file = "{$dir}/.openssl.cnf";
    file_put_contents($file, "[req]\ndistinguished_name=dn\n[dn]\n[ext]\nsubjectAltName={$san}\nbasicConstraints=CA:FALSE\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n");
    return ['config' => $file, 'digest_alg' => 'sha256', 'x509_extensions' => 'ext'];
}

// The CA
$caKey = openssl_pkey_new($config);
$caCsr = openssl_csr_new(['commonName' => 'Vaka Test CA'], $caKey, $config);
$caCert = openssl_csr_sign($caCsr, null, $caKey, $days, ['config' => leaf_config($dir, 'DNS:ca.example')['config'], 'digest_alg' => 'sha256', 'x509_extensions' => 'ca'], 1);
openssl_x509_export_to_file($caCert, "{$dir}/ca.pem");

$leaves = [
    'valid' => ['127.0.0.1', 'IP:127.0.0.1,DNS:localhost', true],
    'wrong-name' => ['other.example', 'DNS:other.example', true],
    'self-signed' => ['127.0.0.1', 'IP:127.0.0.1,DNS:localhost', false],
];
foreach ($leaves as $name => [$cn, $san, $signed]) {
    $key = openssl_pkey_new($config);
    $csr = openssl_csr_new(['commonName' => $cn, 'organizationName' => 'Vaka Tests'], $key, $config);
    $cert = $signed
        ? openssl_csr_sign($csr, $caCert, $caKey, $days, leaf_config($dir, $san), random_int(2, PHP_INT_MAX))
        : openssl_csr_sign($csr, null, $key, $days, leaf_config($dir, $san), random_int(2, PHP_INT_MAX));
    openssl_x509_export_to_file($cert, "{$dir}/{$name}.pem");
    openssl_pkey_export_to_file($key, "{$dir}/{$name}.key");
}
unlink("{$dir}/.openssl.cnf");

echo "Generated test certificates in {$dir}\n";
