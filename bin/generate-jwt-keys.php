<?php
/**
 * Generate JWT RSA keys using openssl_pkey_new.
 * Works around Hostinger's missing ext-sodium by using only openssl.
 *
 * Usage: cd public_html && php config/scripts/generate-jwt-keys.php
 */

$configFile = __DIR__ . '/../config/openssl.cnf';
putenv("OPENSSL_CONF=$configFile");

// Generate minimal openssl.cnf if missing
$cnfDir = __DIR__ . '/../config';
if (!file_exists($configFile)) {
    file_put_contents($configFile, "HOME = .\nopenssl_conf = openssl_init\n[openssl_init]\nssl_conf = ssl_sect\n[ssl_sect]\nsystem_default = system_default_sect\n[system_default_sect]\nOptions = UnsafeLegacyRenegotiation\nCipherString = ALL:@SECLEVEL=0\n[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n");
}

// Generate keys
$tmpDir = sys_get_temp_dir();
$randFile = $tmpDir . '/tnsvt_v2_rand_' . getmypid() . '.bin';
@mkdir($tmpDir, 0755, true);
file_put_contents($randFile, random_bytes(256));
putenv("RANDFILE=$randFile");
putenv("HOME=$tmpDir");

$res = openssl_pkey_new([
    'private_key_bits' => 4096,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'digest_alg' => 'sha256',
]);

if (!$res) {
    fwrite(STDERR, "openssl_pkey_new FAILED: " . openssl_error_string() . "\n");
    exit(1);
}

openssl_pkey_export($res, $priv, null);
$details = openssl_pkey_get_details($res);
$pub = $details['key'];

$jwtDir = __DIR__ . '/../config/jwt';
if (!is_dir($jwtDir)) {
    mkdir($jwtDir, 0755, true);
}
file_put_contents($jwtDir . '/private.pem', $priv);
file_put_contents($jwtDir . '/public.pem', $pub);

echo "✓ JWT keys generated:\n";
echo "  - $jwtDir/private.pem (" . strlen($priv) . " bytes)\n";
echo "  - $jwtDir/public.pem (" . strlen($pub) . " bytes)\n";

@unlink($randFile);