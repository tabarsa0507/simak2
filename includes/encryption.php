<?php
// رمزنگاری متن
function encrypt_text($text) {
    if (empty($text)) return '';
    $key = ENCRYPTION_KEY;
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($text, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

// رمزگشایی متن
function decrypt_text($encrypted_text) {
    if (empty($encrypted_text)) return '';
    $key = ENCRYPTION_KEY;
    $data = base64_decode($encrypted_text);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
}
?>