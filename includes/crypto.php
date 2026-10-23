<?php
/**
 * Métodos de encriptación (movido desde /general.php)
 *
 * - encriptar64 / desencriptar64: TripleDES (CBC) compatible con el sistema VB.NET.
 *   La clave y el IV NO están en este archivo: se leen de config/db.local.php
 *   (CRYPT_KEY_B64 y CRYPT_IV), que no se sube a GitHub.
 *
 * - legacy_encode / legacy_decode: codificación heredada usada en usuarios.usuPassword
 *   (cada carácter desplazado +127 en Windows-1252 / latin1).
 *
 * Este archivo no debe abrirse desde el navegador (includes/.htaccess lo bloquea).
 */

if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../config/db.php';
}

const CRYPT_CIPHER = 'des-ede3-cbc';
const LEGACY_SHIFT = 127;

/**
 * Obtiene clave (24 bytes) e IV (8 bytes) desde la configuración privada.
 */
function crypto_get_key_iv(): array {
    $keyB64 = defined('CRYPT_KEY_B64') ? CRYPT_KEY_B64 : 'mkldoGasGlkkDeGnglkas58fa3rasDas';
    $iv     = defined('CRYPT_IV')      ? CRYPT_IV      : 'K4as3SA0';

    $key = base64_decode($keyB64, true);
    if ($key === false || strlen($key) !== 24) {
        throw new RuntimeException('La clave debe tener 24 bytes (192 bits).');
    }
    if (strlen($iv) !== 8) {
        throw new RuntimeException('El IV debe tener 8 bytes (64 bits).');
    }
    return [$key, $iv];
}

/**
 * Encripta con TripleDES (CBC) y devuelve Base64. Debe coincidir con VB.NET.
 */
function encriptar64(string $input): string {
    [$key, $iv] = crypto_get_key_iv();
    $encrypted = openssl_encrypt($input, CRYPT_CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($encrypted);
}

/**
 * Desencripta un texto Base64 generado con encriptar64. Devuelve false si falla.
 */
function desencriptar64(string $inputEncriptado): string|false {
    [$key, $iv] = crypto_get_key_iv();
    $raw = base64_decode($inputEncriptado, true);
    if ($raw === false) {
        return false;
    }
    return openssl_decrypt($raw, CRYPT_CIPHER, $key, OPENSSL_RAW_DATA, $iv);
}

/**
 * Codifica un texto con el formato heredado de usuarios.usuPassword.
 * Recibe UTF-8 y devuelve UTF-8 (tal como lo entrega la conexión PDO utf8mb4).
 */
function legacy_encode(string $plain): string {
    $bytes = mb_convert_encoding($plain, 'Windows-1252', 'UTF-8');
    $out = '';
    foreach (str_split($bytes) as $ch) {
        $out .= chr((ord($ch) + LEGACY_SHIFT) % 256);
    }
    return mb_convert_encoding($out, 'UTF-8', 'Windows-1252');
}

/**
 * Decodifica un valor de usuarios.usuPassword a texto plano (UTF-8).
 */
function legacy_decode(string $encoded): string {
    $bytes = mb_convert_encoding($encoded, 'Windows-1252', 'UTF-8');
    $out = '';
    foreach (str_split($bytes) as $ch) {
        $out .= chr((ord($ch) - LEGACY_SHIFT + 256) % 256);
    }
    return mb_convert_encoding($out, 'UTF-8', 'Windows-1252');
}
