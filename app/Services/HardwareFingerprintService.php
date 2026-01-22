<?php

namespace App\Services;

use Exception;

/**
 * Hardware Fingerprint Service
 *
 * Validates and manages hardware fingerprints for machine binding
 *
 * @package LicenseServer\Services
 */
class HardwareFingerprintService
{
    private CryptoService $crypto;
    private array $requiredComponents;
    private array $optionalComponents;

    public function __construct()
    {
        $this->crypto = new CryptoService();

        // Define which hardware components are required/optional
        $this->requiredComponents = [
            'cpu_id' => env('FINGERPRINT_USE_CPU_ID', true),
            'mac_address' => env('FINGERPRINT_USE_MAC_ADDRESS', true),
        ];

        $this->optionalComponents = [
            'disk_serial' => env('FINGERPRINT_USE_DISK_SERIAL', true),
            'motherboard_id' => env('FINGERPRINT_USE_MOTHERBOARD_ID', true),
            'bios_serial' => env('FINGERPRINT_USE_BIOS_SERIAL', true),
        ];
    }

    /**
     * Validate hardware fingerprint structure
     *
     * @param string $fingerprint JSON string or hash
     * @return array Validation result
     */
    public function validate(string $fingerprint): array
    {
        // Check if it's a JSON string (full components) or a hash
        if ($this->isJson($fingerprint)) {
            $components = json_decode($fingerprint, true);
            return $this->validateComponents($components);
        } else {
            // It's a hash, basic validation
            if (strlen($fingerprint) !== 64) {
                return [
                    'valid' => false,
                    'error' => 'Invalid fingerprint hash length'
                ];
            }

            if (!ctype_xdigit($fingerprint)) {
                return [
                    'valid' => false,
                    'error' => 'Fingerprint hash contains invalid characters'
                ];
            }

            return ['valid' => true];
        }
    }

    /**
     * Validate hardware components
     *
     * @param array $components
     * @return array
     */
    private function validateComponents(array $components): array
    {
        $errors = [];

        // Check required components
        foreach ($this->requiredComponents as $component => $required) {
            if ($required && empty($components[$component])) {
                $errors[] = "Missing required component: {$component}";
            }
        }

        // Validate component formats
        if (isset($components['mac_address'])) {
            if (!$this->isValidMacAddress($components['mac_address'])) {
                $errors[] = "Invalid MAC address format";
            }
        }

        if (isset($components['cpu_id'])) {
            if (strlen($components['cpu_id']) < 8) {
                $errors[] = "CPU ID too short (minimum 8 characters)";
            }
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'errors' => $errors
            ];
        }

        return ['valid' => true];
    }

    /**
     * Generate fingerprint hash from components
     *
     * @param array $components
     * @return string SHA-256 hash
     */
    public function generateHash(array $components): string
    {
        // Extract only enabled components
        $enabledComponents = [];

        foreach ($this->requiredComponents as $component => $enabled) {
            if ($enabled && isset($components[$component])) {
                $enabledComponents[$component] = $components[$component];
            }
        }

        foreach ($this->optionalComponents as $component => $enabled) {
            if ($enabled && isset($components[$component])) {
                $enabledComponents[$component] = $components[$component];
            }
        }

        // Sort for consistency
        ksort($enabledComponents);

        return $this->crypto->generateFingerprintHash($enabledComponents);
    }

    /**
     * Compare two fingerprints for similarity
     *
     * @param string $fingerprint1
     * @param string $fingerprint2
     * @param int $threshold Similarity threshold (0-100)
     * @return array Match result with similarity score
     */
    public function compare(string $fingerprint1, string $fingerprint2, int $threshold = 80): array
    {
        // Exact match
        if ($fingerprint1 === $fingerprint2) {
            return [
                'match' => true,
                'similarity' => 100,
                'exact' => true
            ];
        }

        // If both are JSON, compare components
        if ($this->isJson($fingerprint1) && $this->isJson($fingerprint2)) {
            $components1 = json_decode($fingerprint1, true);
            $components2 = json_decode($fingerprint2, true);

            return $this->compareComponents($components1, $components2, $threshold);
        }

        // Hashes don't match
        return [
            'match' => false,
            'similarity' => 0,
            'exact' => false
        ];
    }

    /**
     * Compare hardware components for similarity
     *
     * @param array $components1
     * @param array $components2
     * @param int $threshold
     * @return array
     */
    private function compareComponents(array $components1, array $components2, int $threshold): array
    {
        $allComponents = array_unique(array_merge(
            array_keys($components1),
            array_keys($components2)
        ));

        $matches = 0;
        $total = count($allComponents);

        foreach ($allComponents as $component) {
            if (isset($components1[$component]) && isset($components2[$component])) {
                if ($components1[$component] === $components2[$component]) {
                    $matches++;
                }
            }
        }

        $similarity = $total > 0 ? round(($matches / $total) * 100) : 0;

        return [
            'match' => $similarity >= $threshold,
            'similarity' => $similarity,
            'exact' => $similarity === 100,
            'matched_components' => $matches,
            'total_components' => $total
        ];
    }

    /**
     * Check if hardware changed significantly
     *
     * @param array $oldComponents
     * @param array $newComponents
     * @return array Change analysis
     */
    public function analyzeChanges(array $oldComponents, array $newComponents): array
    {
        $changes = [];

        foreach ($this->requiredComponents as $component => $enabled) {
            if (!$enabled) continue;

            $oldValue = $oldComponents[$component] ?? null;
            $newValue = $newComponents[$component] ?? null;

            if ($oldValue !== $newValue) {
                $changes[$component] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                    'severity' => 'high' // Required component changed
                ];
            }
        }

        foreach ($this->optionalComponents as $component => $enabled) {
            if (!$enabled) continue;

            $oldValue = $oldComponents[$component] ?? null;
            $newValue = $newComponents[$component] ?? null;

            if ($oldValue !== $newValue) {
                $changes[$component] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                    'severity' => 'medium' // Optional component changed
                ];
            }
        }

        $highSeverityCount = count(array_filter($changes, fn($c) => $c['severity'] === 'high'));

        return [
            'has_changes' => !empty($changes),
            'changes' => $changes,
            'high_severity_count' => $highSeverityCount,
            'is_significant' => $highSeverityCount >= 2, // 2+ critical components changed
            'recommendation' => $this->getChangeRecommendation($highSeverityCount)
        ];
    }

    /**
     * Get recommendation based on hardware changes
     *
     * @param int $highSeverityCount
     * @return string
     */
    private function getChangeRecommendation(int $highSeverityCount): string
    {
        if ($highSeverityCount === 0) {
            return 'Minor hardware change detected. License can continue.';
        } elseif ($highSeverityCount === 1) {
            return 'Moderate hardware change detected. Verification recommended.';
        } elseif ($highSeverityCount >= 2) {
            return 'Significant hardware change detected. Machine appears to be different. License transfer may be required.';
        }

        return 'Unknown';
    }

    /**
     * Extract machine info from components
     *
     * @param array $components
     * @return array
     */
    public function extractMachineInfo(array $components): array
    {
        return [
            'machine_name' => $components['machine_name'] ?? null,
            'os_info' => $components['os_info'] ?? null,
            'cpu_info' => $components['cpu_info'] ?? null,
            'mac_address' => $components['mac_address'] ?? null,
            'disk_serial' => $components['disk_serial'] ?? null,
            'motherboard_id' => $components['motherboard_id'] ?? null,
        ];
    }

    /**
     * Validate MAC address format
     *
     * @param string $macAddress
     * @return bool
     */
    private function isValidMacAddress(string $macAddress): bool
    {
        // Support multiple formats: XX:XX:XX:XX:XX:XX, XX-XX-XX-XX-XX-XX, XXXXXXXXXXXX
        $patterns = [
            '/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/',
            '/^([0-9A-Fa-f]{2}-){5}[0-9A-Fa-f]{2}$/',
            '/^[0-9A-Fa-f]{12}$/'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $macAddress)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if string is JSON
     *
     * @param string $string
     * @return bool
     */
    private function isJson(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Sanitize hardware component data
     *
     * @param array $components
     * @return array
     */
    public function sanitize(array $components): array
    {
        $sanitized = [];

        foreach ($components as $key => $value) {
            // Only allow alphanumeric, dash, colon, underscore, and space
            $sanitized[$key] = preg_replace('/[^a-zA-Z0-9\-:_ ]/', '', $value);
        }

        return $sanitized;
    }
}
