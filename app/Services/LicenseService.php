<?php

namespace App\Services;

use App\Models\License;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Machine;
use App\Models\Activation;
use Hashids\Hashids;
use Exception;

/**
 * License Service
 *
 * Handles license generation, validation, and management
 *
 * @package LicenseServer\Services
 */
class LicenseService
{
    private CryptoService $crypto;
    private HardwareFingerprintService $fingerprint;
    private Hashids $hashids;

    public function __construct()
    {
        $this->crypto = new CryptoService();
        $this->fingerprint = new HardwareFingerprintService();

        // Initialize Hashids for license key generation
        $this->hashids = new Hashids(
            env('APP_KEY', 'license-server'),
            env('LICENSE_KEY_LENGTH', 25),
            'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'
        );
    }

    /**
     * Generate a new license
     *
     * @param array $data License data
     * @return License
     * @throws Exception
     */
    public function generate(array $data): License
    {
        // Validate required fields
        $this->validateGenerationData($data);

        // Get product
        $product = Product::find($data['product_id']);
        if (!$product) {
            throw new Exception('Product not found');
        }

        // Get or create customer
        $customer = $this->getOrCreateCustomer($data['customer']);

        // Generate unique license key
        $licenseKey = $this->generateLicenseKey();

        // Calculate expiry date
        $expiresAt = $this->calculateExpiryDate($data);

        // Create license
        $license = License::create([
            'license_key' => $licenseKey,
            'product_id' => $product->id,
            'tier_id' => $data['tier_id'] ?? null,
            'customer_id' => $customer->id,
            'license_type' => $data['license_type'] ?? 'standard',
            'status' => 'active',
            'max_activations' => $data['max_activations'] ?? 1,
            'current_activations' => 0,
            'issued_at' => now(),
            'expires_at' => $expiresAt,
            'grace_period_days' => $data['grace_period_days'] ?? env('LICENSE_GRACE_PERIOD_DAYS', 7),
            'expiry_message' => $data['expiry_message'] ?? null,
            'allow_offline' => $data['allow_offline'] ?? true,
            'allow_transfer' => $data['allow_transfer'] ?? env('LICENSE_ALLOW_TRANSFER', true),
            'transfer_count' => 0,
            'max_transfers' => $data['max_transfers'] ?? 3,
            'custom_fields' => $data['custom_fields'] ?? [],
            'features' => $data['features'] ?? [],
            'ip_restrictions' => $data['ip_restrictions'] ?? null,
            'geo_restrictions' => $data['geo_restrictions'] ?? null,
            'notes' => $data['notes'] ?? null
        ]);

        // Log license creation
        audit_log('license.created', [
            'license_key' => $licenseKey,
            'product_id' => $product->id,
            'customer_id' => $customer->id
        ]);

        return $license;
    }

    /**
     * Generate unique license key
     *
     * @return string
     */
    private function generateLicenseKey(): string
    {
        do {
            $randomNumber = random_int(1000000, 9999999999);
            $timestamp = time();

            $encoded = $this->hashids->encode($randomNumber, $timestamp);

            // Format as XXXXX-XXXXX-XXXXX-XXXXX-XXXXX
            $segments = env('LICENSE_KEY_SEGMENTS', 5);
            $segmentLength = (int) ceil(strlen($encoded) / $segments);

            $formatted = implode('-', str_split(str_pad($encoded, $segmentLength * $segments, '0'), $segmentLength));

            // Ensure uniqueness
            $exists = License::where('license_key', $formatted)->exists();
        } while ($exists);

        return $formatted;
    }

    /**
     * Calculate expiry date
     *
     * @param array $data
     * @return \Carbon\Carbon|null
     */
    private function calculateExpiryDate(array $data): ?\Carbon\Carbon
    {
        if (isset($data['expires_at'])) {
            return \Carbon\Carbon::parse($data['expires_at']);
        }

        if (isset($data['duration_days'])) {
            return now()->addDays($data['duration_days']);
        }

        // Default duration
        $defaultDuration = env('LICENSE_DEFAULT_DURATION_DAYS', 365);
        return now()->addDays($defaultDuration);
    }

    /**
     * Activate license on a machine
     *
     * @param string $licenseKey
     * @param string $machineFingerprint
     * @param string $machinePublicKey
     * @param array $machineInfo
     * @param string $activationType
     * @return array Activation result
     * @throws Exception
     */
    public function activate(
        string $licenseKey,
        string $machineFingerprint,
        string $machinePublicKey,
        array $machineInfo = [],
        string $activationType = 'online'
    ): array {
        // Find license
        $license = License::findByKey($licenseKey);

        if (!$license) {
            throw new Exception('Invalid license key');
        }

        // Validate license status
        if (!$license->isValid()) {
            throw new Exception($this->getLicenseStatusMessage($license));
        }

        // Check if can activate
        if (!$license->canActivate()) {
            throw new Exception('Maximum activations reached for this license');
        }

        // Validate machine fingerprint
        $fingerprintValidation = $this->fingerprint->validate($machineFingerprint);
        if (!$fingerprintValidation['valid']) {
            throw new Exception('Invalid machine fingerprint: ' . ($fingerprintValidation['error'] ?? 'Unknown error'));
        }

        // Check IP restrictions
        if ($license->ip_restrictions && !$this->validateIpRestriction($license->ip_restrictions)) {
            throw new Exception('License cannot be activated from this IP address');
        }

        // Check geo restrictions
        if ($license->geo_restrictions && !$this->validateGeoRestriction($license->geo_restrictions)) {
            throw new Exception('License cannot be activated from this location');
        }

        // Find or create machine
        $machineData = $this->fingerprint->extractMachineInfo($machineInfo);
        $machineData['public_key'] = $machinePublicKey;

        $machine = Machine::findOrCreateByFingerprint($machineFingerprint, $machineData);

        // Check if machine is blacklisted
        if ($machine->isBlacklisted()) {
            throw new Exception('This machine has been blacklisted: ' . $machine->blacklist_reason);
        }

        // Check for existing activation
        $existingActivation = Activation::where('license_id', $license->id)
            ->where('machine_id', $machine->id)
            ->where('status', 'active')
            ->first();

        if ($existingActivation) {
            // Already activated, return existing activation
            return $this->buildActivationResponse($license, $existingActivation, $machine);
        }

        // Create new activation
        $activation = Activation::create([
            'license_id' => $license->id,
            'machine_id' => $machine->id,
            'activation_type' => $activationType,
            'status' => 'active',
            'ip_address' => get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'app_version' => $machineInfo['app_version'] ?? null
        ]);

        // Log activation
        audit_log('license.activated', [
            'license_key' => $licenseKey,
            'machine_fingerprint' => $machine->fingerprint_hash,
            'activation_type' => $activationType
        ]);

        return $this->buildActivationResponse($license, $activation, $machine);
    }

    /**
     * Build activation response
     *
     * @param License $license
     * @param Activation $activation
     * @param Machine $machine
     * @return array
     * @throws Exception
     */
    private function buildActivationResponse(License $license, Activation $activation, Machine $machine): array
    {
        $licenseData = [
            'license_key' => $license->license_key,
            'product_id' => $license->product_id,
            'product_name' => $license->product->name,
            'customer_email' => $license->customer->email,
            'license_type' => $license->license_type,
            'issued_at' => $license->issued_at->toIso8601String(),
            'expires_at' => $license->expires_at ? $license->expires_at->toIso8601String() : null,
            'grace_period_days' => $license->grace_period_days,
            'features' => $license->features,
            'custom_fields' => $license->custom_fields,
            'machine_fingerprint' => $machine->fingerprint_hash,
            'activation_token' => $activation->activation_token,
            'activated_at' => $activation->activated_at->toIso8601String()
        ];

        // Generate signature
        $signature = $this->crypto->generateLicenseSignature($licenseData);

        // Encrypt license data with machine's public key
        $encryptedLicense = $this->crypto->encryptWithClientKey($licenseData, $machine->public_key);

        return [
            'success' => true,
            'activation_token' => $activation->activation_token,
            'license' => [
                'key' => $license->license_key,
                'product' => $license->product->name,
                'type' => $license->license_type,
                'expires_at' => $license->expires_at ? $license->expires_at->toDateTimeString() : 'Never',
                'features' => $license->features,
                'custom_fields' => $license->custom_fields
            ],
            'encrypted_license' => $encryptedLicense,
            'signature' => $signature,
            'server_public_key' => $this->crypto->getServerPublicKey()
        ];
    }

    /**
     * Validate license
     *
     * @param string $licenseKey
     * @param string $machineFingerprint
     * @param string|null $activationToken
     * @return array Validation result
     */
    public function validate(string $licenseKey, string $machineFingerprint, ?string $activationToken = null): array
    {
        try {
            $license = License::findByKey($licenseKey);

            if (!$license) {
                return [
                    'valid' => false,
                    'error' => 'Invalid license key'
                ];
            }

            if (!$license->isValid()) {
                return [
                    'valid' => false,
                    'error' => $this->getLicenseStatusMessage($license),
                    'status' => $license->status
                ];
            }

            // Find machine
            $machine = Machine::findByFingerprint($machineFingerprint);

            if (!$machine) {
                return [
                    'valid' => false,
                    'error' => 'Machine not found. License not activated on this machine.'
                ];
            }

            // Find activation
            $activation = Activation::where('license_id', $license->id)
                ->where('machine_id', $machine->id);

            if ($activationToken) {
                $activation->where('activation_token', $activationToken);
            }

            $activation = $activation->where('status', 'active')->first();

            if (!$activation) {
                return [
                    'valid' => false,
                    'error' => 'License not activated on this machine'
                ];
            }

            return [
                'valid' => true,
                'license' => [
                    'key' => $license->license_key,
                    'product' => $license->product->name,
                    'type' => $license->license_type,
                    'status' => $license->status,
                    'expires_at' => $license->expires_at ? $license->expires_at->toDateTimeString() : null,
                    'days_until_expiry' => $license->daysUntilExpiry(),
                    'in_grace_period' => $license->isInGracePeriod(),
                    'features' => $license->features,
                    'custom_fields' => $license->custom_fields
                ],
                'activation' => [
                    'token' => $activation->activation_token,
                    'activated_at' => $activation->activated_at->toDateTimeString(),
                    'last_ping_at' => $activation->last_ping_at ? $activation->last_ping_at->toDateTimeString() : null
                ]
            ];
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => 'Validation error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Deactivate license from a machine
     *
     * @param string $licenseKey
     * @param string $machineFingerprint
     * @param string|null $activationToken
     * @return array
     * @throws Exception
     */
    public function deactivate(string $licenseKey, string $machineFingerprint, ?string $activationToken = null): array
    {
        $license = License::findByKey($licenseKey);

        if (!$license) {
            throw new Exception('Invalid license key');
        }

        $machine = Machine::findByFingerprint($machineFingerprint);

        if (!$machine) {
            throw new Exception('Machine not found');
        }

        $query = Activation::where('license_id', $license->id)
            ->where('machine_id', $machine->id)
            ->where('status', 'active');

        if ($activationToken) {
            $query->where('activation_token', $activationToken);
        }

        $activation = $query->first();

        if (!$activation) {
            throw new Exception('No active activation found');
        }

        $activation->deactivate();

        return [
            'success' => true,
            'message' => 'License deactivated successfully'
        ];
    }

    /**
     * Get or create customer
     *
     * @param array $customerData
     * @return Customer
     * @throws Exception
     */
    private function getOrCreateCustomer(array $customerData): Customer
    {
        if (isset($customerData['id'])) {
            $customer = Customer::find($customerData['id']);
            if ($customer) {
                return $customer;
            }
        }

        if (isset($customerData['email'])) {
            $customer = Customer::findByEmail($customerData['email']);
            if ($customer) {
                return $customer;
            }
        }

        // Create new customer
        if (!isset($customerData['email'])) {
            throw new Exception('Customer email is required');
        }

        return Customer::create([
            'email' => $customerData['email'],
            'name' => $customerData['name'] ?? null,
            'company' => $customerData['company'] ?? null,
            'phone' => $customerData['phone'] ?? null,
            'country' => $customerData['country'] ?? null,
            'is_active' => true
        ]);
    }

    /**
     * Validate generation data
     *
     * @param array $data
     * @throws Exception
     */
    private function validateGenerationData(array $data): void
    {
        if (empty($data['product_id'])) {
            throw new Exception('Product ID is required');
        }

        if (empty($data['customer'])) {
            throw new Exception('Customer data is required');
        }
    }

    /**
     * Get license status message
     *
     * @param License $license
     * @return string
     */
    private function getLicenseStatusMessage(License $license): string
    {
        if ($license->status === 'revoked') {
            return 'License has been revoked' . ($license->revoked_reason ? ': ' . $license->revoked_reason : '');
        }

        if ($license->status === 'suspended') {
            return 'License is currently suspended';
        }

        if ($license->isExpired()) {
            if ($license->isInGracePeriod()) {
                return 'License expired but still in grace period';
            }
            return $license->expiry_message ?? 'License has expired';
        }

        return 'License is not valid';
    }

    /**
     * Validate IP restriction
     *
     * @param array $restrictions
     * @return bool
     */
    private function validateIpRestriction(array $restrictions): bool
    {
        $clientIp = get_client_ip();

        foreach ($restrictions as $restriction) {
            if ($this->ipMatch($clientIp, $restriction)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP matches pattern/CIDR
     *
     * @param string $ip
     * @param string $pattern
     * @return bool
     */
    private function ipMatch(string $ip, string $pattern): bool
    {
        // Exact match
        if ($ip === $pattern) {
            return true;
        }

        // CIDR notation
        if (strpos($pattern, '/') !== false) {
            list($subnet, $mask) = explode('/', $pattern);
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $maskLong = -1 << (32 - (int)$mask);

            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        return false;
    }

    /**
     * Validate geo restriction
     *
     * @param array $restrictions
     * @return bool
     */
    private function validateGeoRestriction(array $restrictions): bool
    {
        // TODO: Implement geolocation checking
        // This would require integrating with a geolocation service
        return true;
    }
}
