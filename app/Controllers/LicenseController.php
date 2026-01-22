<?php

namespace App\Controllers;

use App\Services\LicenseService;
use App\Services\HardwareFingerprintService;
use App\Models\Activation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Exception;

/**
 * License Controller
 *
 * Handles all license-related API endpoints
 *
 * @package LicenseServer\Controllers
 */
class LicenseController
{
    private LicenseService $licenseService;
    private HardwareFingerprintService $fingerprintService;

    public function __construct()
    {
        $this->licenseService = new LicenseService();
        $this->fingerprintService = new HardwareFingerprintService();
    }

    /**
     * Activate license (online activation)
     *
     * POST /api/v1/licenses/activate
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function activate(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            // Validate required fields
            if (empty($data['license_key'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'License key is required'
                ], 400);
            }

            if (empty($data['machine_fingerprint'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Machine fingerprint is required'
                ], 400);
            }

            if (empty($data['public_key'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Machine public key is required'
                ], 400);
            }

            // Activate license
            $result = $this->licenseService->activate(
                $data['license_key'],
                $data['machine_fingerprint'],
                $data['public_key'],
                $data['machine_info'] ?? [],
                'online'
            );

            return new JsonResponse($result, 200);

        } catch (Exception $e) {
            logger()->error('License activation failed', [
                'error' => $e->getMessage(),
                'data' => $data ?? []
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Validate license
     *
     * POST /api/v1/licenses/validate
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function validate(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (empty($data['license_key'])) {
                return new JsonResponse([
                    'valid' => false,
                    'error' => 'License key is required'
                ], 400);
            }

            if (empty($data['machine_fingerprint'])) {
                return new JsonResponse([
                    'valid' => false,
                    'error' => 'Machine fingerprint is required'
                ], 400);
            }

            $result = $this->licenseService->validate(
                $data['license_key'],
                $data['machine_fingerprint'],
                $data['activation_token'] ?? null
            );

            return new JsonResponse($result, $result['valid'] ? 200 : 400);

        } catch (Exception $e) {
            logger()->error('License validation failed', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'valid' => false,
                'error' => 'Validation error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Deactivate license
     *
     * POST /api/v1/licenses/deactivate
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deactivate(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (empty($data['license_key'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'License key is required'
                ], 400);
            }

            if (empty($data['machine_fingerprint'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Machine fingerprint is required'
                ], 400);
            }

            $result = $this->licenseService->deactivate(
                $data['license_key'],
                $data['machine_fingerprint'],
                $data['activation_token'] ?? null
            );

            return new JsonResponse($result, 200);

        } catch (Exception $e) {
            logger()->error('License deactivation failed', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Generate offline activation request
     *
     * POST /api/v1/licenses/offline/request
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function offlineRequest(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (empty($data['license_key'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'License key is required'
                ], 400);
            }

            if (empty($data['machine_fingerprint'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Machine fingerprint is required'
                ], 400);
            }

            if (empty($data['public_key'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Machine public key is required'
                ], 400);
            }

            // Generate offline activation
            $result = $this->licenseService->activate(
                $data['license_key'],
                $data['machine_fingerprint'],
                $data['public_key'],
                $data['machine_info'] ?? [],
                'offline'
            );

            // Return encrypted license file
            return new JsonResponse([
                'success' => true,
                'license_file' => $result['encrypted_license'],
                'signature' => $result['signature'],
                'server_public_key' => $result['server_public_key'],
                'activation_token' => $result['activation_token'],
                'instructions' => 'Save this response as license.dat and load it in your application'
            ], 200);

        } catch (Exception $e) {
            logger()->error('Offline activation request failed', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Process offline activation
     *
     * POST /api/v1/licenses/offline/activate
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function offlineActivate(Request $request): JsonResponse
    {
        // This is essentially the same as offlineRequest
        // Keeping separate for clarity in API documentation
        return $this->offlineRequest($request);
    }

    /**
     * Record ping/heartbeat
     *
     * POST /api/v1/licenses/ping
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function ping(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (empty($data['activation_token'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Activation token is required'
                ], 400);
            }

            // Find activation
            $activation = Activation::findByToken($data['activation_token']);

            if (!$activation) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid activation token'
                ], 404);
            }

            if (!$activation->isActive()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Activation is not active'
                ], 400);
            }

            // Record ping
            $pingData = [
                'ip_address' => get_client_ip(),
                'app_version' => $data['app_version'] ?? null,
                'uptime_seconds' => $data['uptime_seconds'] ?? null,
                'status_code' => 200,
                'metadata' => $data['metadata'] ?? null
            ];

            $activation->recordPing($pingData);

            // Check if license is still valid
            $license = $activation->license;
            $isValid = $license->isValid();

            return new JsonResponse([
                'success' => true,
                'message' => 'Ping recorded successfully',
                'license_status' => [
                    'valid' => $isValid,
                    'status' => $license->status,
                    'expires_at' => $license->expires_at ? $license->expires_at->toDateTimeString() : null,
                    'days_until_expiry' => $license->daysUntilExpiry(),
                    'in_grace_period' => $license->isInGracePeriod()
                ]
            ], 200);

        } catch (Exception $e) {
            logger()->error('Ping recording failed', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to record ping'
            ], 500);
        }
    }

    /**
     * Transfer license to new machine
     *
     * POST /api/v1/licenses/transfer
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function transfer(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (empty($data['license_key'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'License key is required'
                ], 400);
            }

            if (empty($data['old_machine_fingerprint'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Old machine fingerprint is required'
                ], 400);
            }

            if (empty($data['new_machine_fingerprint'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'New machine fingerprint is required'
                ], 400);
            }

            if (empty($data['new_machine_public_key'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'New machine public key is required'
                ], 400);
            }

            // Deactivate old machine
            $deactivateResult = $this->licenseService->deactivate(
                $data['license_key'],
                $data['old_machine_fingerprint'],
                $data['old_activation_token'] ?? null
            );

            if (!$deactivateResult['success']) {
                return new JsonResponse($deactivateResult, 400);
            }

            // Activate on new machine
            $activateResult = $this->licenseService->activate(
                $data['license_key'],
                $data['new_machine_fingerprint'],
                $data['new_machine_public_key'],
                $data['new_machine_info'] ?? [],
                'online'
            );

            // Update transfer count
            $license = \App\Models\License::findByKey($data['license_key']);
            $license->increment('transfer_count');
            $license->last_transfer_at = now();
            $license->save();

            audit_log('license.transferred', [
                'license_key' => $data['license_key'],
                'from' => $data['old_machine_fingerprint'],
                'to' => $data['new_machine_fingerprint']
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'License transferred successfully',
                'activation' => $activateResult
            ], 200);

        } catch (Exception $e) {
            logger()->error('License transfer failed', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get license information
     *
     * GET /api/v1/licenses/{licenseKey}
     *
     * @param Request $request
     * @param string $licenseKey
     * @return JsonResponse
     */
    public function info(Request $request, string $licenseKey): JsonResponse
    {
        try {
            $license = \App\Models\License::findByKey($licenseKey);

            if (!$license) {
                return new JsonResponse([
                    'error' => 'License not found'
                ], 404);
            }

            return new JsonResponse([
                'license_key' => $license->license_key,
                'product' => $license->product->name,
                'type' => $license->license_type,
                'status' => $license->status,
                'issued_at' => $license->issued_at->toDateTimeString(),
                'expires_at' => $license->expires_at ? $license->expires_at->toDateTimeString() : 'Never',
                'days_until_expiry' => $license->daysUntilExpiry(),
                'max_activations' => $license->max_activations,
                'current_activations' => $license->current_activations,
                'allow_offline' => $license->allow_offline,
                'allow_transfer' => $license->allow_transfer,
                'transfer_count' => $license->transfer_count,
                'max_transfers' => $license->max_transfers,
                'features' => $license->features,
                'is_valid' => $license->isValid()
            ], 200);

        } catch (Exception $e) {
            logger()->error('Failed to get license info', [
                'error' => $e->getMessage(),
                'license_key' => $licenseKey
            ]);

            return new JsonResponse([
                'error' => 'Failed to retrieve license information'
            ], 500);
        }
    }
}
