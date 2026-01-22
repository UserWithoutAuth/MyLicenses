<?php

namespace App\Services;

use Exception;

/**
 * Cryptographic Service
 *
 * Handles RSA signing, verification, and encryption operations
 *
 * @package LicenseServer\Services
 */
class CryptoService
{
    private $privateKey;
    private $publicKey;
    private string $privateKeyPath;
    private string $publicKeyPath;
    private ?string $passphrase;

    public function __construct()
    {
        $this->privateKeyPath = env('SERVER_PRIVATE_KEY');
        $this->publicKeyPath = env('SERVER_PUBLIC_KEY');
        $this->passphrase = env('KEY_PASSPHRASE');

        $this->loadKeys();
    }

    /**
     * Load RSA keypairs from storage
     *
     * @throws Exception
     */
    private function loadKeys(): void
    {
        // Check if key files exist
        if (!file_exists($this->privateKeyPath)) {
            throw new Exception("Private key not found at: {$this->privateKeyPath}");
        }

        if (!file_exists($this->publicKeyPath)) {
            throw new Exception("Public key not found at: {$this->publicKeyPath}");
        }

        // Load private key
        $privateKeyContent = file_get_contents($this->privateKeyPath);
        $this->privateKey = openssl_pkey_get_private($privateKeyContent, $this->passphrase);

        if ($this->privateKey === false) {
            throw new Exception("Failed to load private key: " . openssl_error_string());
        }

        // Load public key
        $publicKeyContent = file_get_contents($this->publicKeyPath);
        $this->publicKey = openssl_pkey_get_public($publicKeyContent);

        if ($this->publicKey === false) {
            throw new Exception("Failed to load public key: " . openssl_error_string());
        }
    }

    /**
     * Sign data with server private key
     *
     * @param mixed $data
     * @return string Base64-encoded signature
     * @throws Exception
     */
    public function sign($data): string
    {
        $dataString = is_string($data) ? $data : json_encode($data);

        $signature = '';
        $result = openssl_sign($dataString, $signature, $this->privateKey, OPENSSL_ALGO_SHA512);

        if (!$result) {
            throw new Exception("Signing failed: " . openssl_error_string());
        }

        return base64_encode($signature);
    }

    /**
     * Verify signature with server public key
     *
     * @param mixed $data
     * @param string $signature Base64-encoded signature
     * @return bool
     */
    public function verify($data, string $signature): bool
    {
        $dataString = is_string($data) ? $data : json_encode($data);
        $signatureBinary = base64_decode($signature);

        $result = openssl_verify($dataString, $signatureBinary, $this->publicKey, OPENSSL_ALGO_SHA512);

        return $result === 1;
    }

    /**
     * Verify signature with client public key
     *
     * @param mixed $data
     * @param string $signature Base64-encoded signature
     * @param string $publicKeyPem Client's public key in PEM format
     * @return bool
     */
    public function verifyWithClientKey($data, string $signature, string $publicKeyPem): bool
    {
        $clientPublicKey = openssl_pkey_get_public($publicKeyPem);

        if ($clientPublicKey === false) {
            return false;
        }

        $dataString = is_string($data) ? $data : json_encode($data);
        $signatureBinary = base64_decode($signature);

        $result = openssl_verify($dataString, $signatureBinary, $clientPublicKey, OPENSSL_ALGO_SHA512);

        openssl_free_key($clientPublicKey);

        return $result === 1;
    }

    /**
     * Encrypt data with client's public key
     *
     * @param mixed $data
     * @param string $clientPublicKeyPem
     * @return string Base64-encoded encrypted data
     * @throws Exception
     */
    public function encryptWithClientKey($data, string $clientPublicKeyPem): string
    {
        $clientPublicKey = openssl_pkey_get_public($clientPublicKeyPem);

        if ($clientPublicKey === false) {
            throw new Exception("Invalid client public key");
        }

        $dataString = is_string($data) ? $data : json_encode($data);

        // RSA can only encrypt small amounts of data
        // For larger data, use hybrid encryption (RSA + AES)
        if (strlen($dataString) > 190) {
            return $this->hybridEncrypt($dataString, $clientPublicKey);
        }

        $encrypted = '';
        $result = openssl_public_encrypt($dataString, $encrypted, $clientPublicKey);

        openssl_free_key($clientPublicKey);

        if (!$result) {
            throw new Exception("Encryption failed: " . openssl_error_string());
        }

        return base64_encode($encrypted);
    }

    /**
     * Hybrid encryption (RSA + AES) for large data
     *
     * @param string $data
     * @param resource $clientPublicKey
     * @return string
     * @throws Exception
     */
    private function hybridEncrypt(string $data, $clientPublicKey): string
    {
        // Generate random AES key
        $aesKey = random_bytes(32);
        $iv = random_bytes(16);

        // Encrypt data with AES
        $encryptedData = openssl_encrypt($data, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $iv);

        if ($encryptedData === false) {
            throw new Exception("AES encryption failed");
        }

        // Encrypt AES key with RSA
        $encryptedKey = '';
        $result = openssl_public_encrypt($aesKey, $encryptedKey, $clientPublicKey);

        if (!$result) {
            throw new Exception("RSA encryption of AES key failed");
        }

        // Combine: [encrypted_key_length(4 bytes)][encrypted_key][iv(16 bytes)][encrypted_data]
        $combined = pack('N', strlen($encryptedKey)) . $encryptedKey . $iv . $encryptedData;

        return base64_encode($combined);
    }

    /**
     * Decrypt data with server private key
     *
     * @param string $encryptedData Base64-encoded encrypted data
     * @return string
     * @throws Exception
     */
    public function decrypt(string $encryptedData): string
    {
        $encrypted = base64_decode($encryptedData);

        $decrypted = '';
        $result = openssl_private_decrypt($encrypted, $decrypted, $this->privateKey);

        if (!$result) {
            throw new Exception("Decryption failed: " . openssl_error_string());
        }

        return $decrypted;
    }

    /**
     * Get server public key in PEM format
     *
     * @return string
     */
    public function getServerPublicKey(): string
    {
        return file_get_contents($this->publicKeyPath);
    }

    /**
     * Generate license file signature
     *
     * @param array $licenseData
     * @return string
     * @throws Exception
     */
    public function generateLicenseSignature(array $licenseData): string
    {
        // Create deterministic string from license data
        $signatureData = [
            'license_key' => $licenseData['license_key'] ?? '',
            'product_id' => $licenseData['product_id'] ?? '',
            'customer_email' => $licenseData['customer_email'] ?? '',
            'expires_at' => $licenseData['expires_at'] ?? '',
            'machine_fingerprint' => $licenseData['machine_fingerprint'] ?? '',
        ];

        // Sort by keys for consistency
        ksort($signatureData);

        return $this->sign($signatureData);
    }

    /**
     * Verify license file signature
     *
     * @param array $licenseData
     * @param string $signature
     * @return bool
     */
    public function verifyLicenseSignature(array $licenseData, string $signature): bool
    {
        $signatureData = [
            'license_key' => $licenseData['license_key'] ?? '',
            'product_id' => $licenseData['product_id'] ?? '',
            'customer_email' => $licenseData['customer_email'] ?? '',
            'expires_at' => $licenseData['expires_at'] ?? '',
            'machine_fingerprint' => $licenseData['machine_fingerprint'] ?? '',
        ];

        ksort($signatureData);

        return $this->verify($signatureData, $signature);
    }

    /**
     * Hash data using SHA-256
     *
     * @param mixed $data
     * @return string
     */
    public function hash($data): string
    {
        $dataString = is_string($data) ? $data : json_encode($data);
        return hash('sha256', $dataString);
    }

    /**
     * Generate fingerprint hash
     *
     * @param array $components
     * @return string
     */
    public function generateFingerprintHash(array $components): string
    {
        ksort($components);
        $dataString = json_encode($components);
        return hash('sha256', $dataString);
    }

    /**
     * Cleanup resources
     */
    public function __destruct()
    {
        if ($this->privateKey) {
            openssl_free_key($this->privateKey);
        }

        if ($this->publicKey) {
            openssl_free_key($this->publicKey);
        }
    }
}
