<?php

namespace App\Services;

use App\Models\License;
use Carbon\Carbon;

/**
 * Notification Service
 *
 * Handles automated notifications for license events
 *
 * @package LicenseServer\Services
 */
class NotificationService
{
    private EmailService $emailService;

    public function __construct()
    {
        $this->emailService = new EmailService();
    }

    /**
     * Send expiring license notifications
     *
     * Checks for licenses expiring in configured days and sends notifications
     *
     * @return array Results summary
     */
    public function sendExpiringNotifications(): array
    {
        // Get configured notification days
        $notifyDays = $this->getNotificationDays();

        $results = [
            'checked' => 0,
            'notified' => 0,
            'errors' => 0
        ];

        foreach ($notifyDays as $days) {
            $licenses = $this->getLicensesExpiringIn($days);
            $results['checked'] += $licenses->count();

            foreach ($licenses as $license) {
                // Check if already notified for this day
                if ($this->wasRecentlyNotified($license, $days)) {
                    continue;
                }

                try {
                    $sent = $this->emailService->sendLicenseExpiring($license, $days);

                    if ($sent) {
                        $this->recordNotification($license, 'expiring', $days);
                        $results['notified']++;

                        logger()->info('Expiry notification sent', [
                            'license_id' => $license->id,
                            'license_key' => $license->license_key,
                            'days_remaining' => $days
                        ]);
                    }
                } catch (\Exception $e) {
                    $results['errors']++;

                    logger()->error('Failed to send expiry notification', [
                        'license_id' => $license->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $results;
    }

    /**
     * Send expired license notifications
     *
     * Notifies customers whose licenses have expired
     *
     * @return array Results summary
     */
    public function sendExpiredNotifications(): array
    {
        // Get licenses that expired today
        $licenses = License::where('status', 'active')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', Carbon::today())
            ->get();

        $results = [
            'checked' => $licenses->count(),
            'notified' => 0,
            'errors' => 0
        ];

        foreach ($licenses as $license) {
            // Check if already notified
            if ($this->wasRecentlyNotified($license, 0, 'expired')) {
                continue;
            }

            try {
                $sent = $this->emailService->sendLicenseExpired($license);

                if ($sent) {
                    $this->recordNotification($license, 'expired', 0);
                    $results['notified']++;

                    // Update license status
                    $license->status = 'expired';
                    $license->save();

                    logger()->info('Expiration notification sent', [
                        'license_id' => $license->id,
                        'license_key' => $license->license_key
                    ]);
                }
            } catch (\Exception $e) {
                $results['errors']++;

                logger()->error('Failed to send expiration notification', [
                    'license_id' => $license->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    /**
     * Send missed ping notifications
     *
     * Notifies when licenses haven't sent heartbeat in a while
     *
     * @return array Results summary
     */
    public function sendMissedPingNotifications(): array
    {
        if (!env('PING_NOTIFY_MISSED', true)) {
            return ['checked' => 0, 'notified' => 0, 'errors' => 0];
        }

        $results = [
            'checked' => 0,
            'notified' => 0,
            'errors' => 0
        ];

        $pingTimeout = env('PING_TIMEOUT_MINUTES', 15);
        $missedThreshold = env('PING_MISSED_THRESHOLD', 3);

        // Get activations with overdue pings
        $activations = \App\Models\Activation::where('status', 'active')
            ->where('activation_type', 'online')
            ->whereNotNull('last_ping_at')
            ->where('last_ping_at', '<', Carbon::now()->subMinutes($pingTimeout * $missedThreshold))
            ->get();

        $results['checked'] = $activations->count();

        foreach ($activations as $activation) {
            $license = $activation->license;

            // Check if already notified recently
            if ($this->wasRecentlyNotified($license, 0, 'missed_ping')) {
                continue;
            }

            try {
                $details = [
                    'license_key' => $license->license_key,
                    'machine_name' => $activation->machine->machine_name ?? 'Unknown',
                    'last_ping' => $activation->last_ping_at->diffForHumans(),
                    'expected_interval' => $pingTimeout . ' minutes'
                ];

                $sent = $this->emailService->sendSuspiciousActivity(
                    $license,
                    'License has stopped sending heartbeat signals',
                    $details
                );

                if ($sent) {
                    $this->recordNotification($license, 'missed_ping', 0);
                    $results['notified']++;
                }
            } catch (\Exception $e) {
                $results['errors']++;

                logger()->error('Failed to send missed ping notification', [
                    'activation_id' => $activation->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    /**
     * Get licenses expiring in X days
     *
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getLicensesExpiringIn(int $days): \Illuminate\Database\Eloquent\Collection
    {
        $targetDate = Carbon::now()->addDays($days)->startOfDay();

        return License::where('status', 'active')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', $targetDate)
            ->get();
    }

    /**
     * Get notification days from config
     *
     * @return array
     */
    private function getNotificationDays(): array
    {
        $daysString = env('MAIL_NOTIFY_LICENSE_EXPIRING_DAYS', '7,3,1');
        $days = explode(',', $daysString);

        return array_map('intval', $days);
    }

    /**
     * Check if license was recently notified
     *
     * @param License $license
     * @param int $days
     * @param string $type
     * @return bool
     */
    private function wasRecentlyNotified(License $license, int $days, string $type = 'expiring'): bool
    {
        // Check notifications table or metadata
        $metadata = $license->custom_fields ?? [];

        $key = "notification_{$type}_{$days}";

        if (isset($metadata[$key])) {
            $lastNotified = Carbon::parse($metadata[$key]);

            // Don't send same notification within 24 hours
            return $lastNotified->isAfter(Carbon::now()->subHours(24));
        }

        return false;
    }

    /**
     * Record notification sent
     *
     * @param License $license
     * @param string $type
     * @param int $days
     * @return void
     */
    private function recordNotification(License $license, string $type, int $days): void
    {
        $metadata = $license->custom_fields ?? [];
        $key = "notification_{$type}_{$days}";
        $metadata[$key] = Carbon::now()->toIso8601String();

        $license->custom_fields = $metadata;
        $license->save();
    }

    /**
     * Run all scheduled notifications
     *
     * @return array Combined results
     */
    public function runAll(): array
    {
        $results = [
            'expiring' => $this->sendExpiringNotifications(),
            'expired' => $this->sendExpiredNotifications(),
            'missed_pings' => $this->sendMissedPingNotifications(),
            'total_notified' => 0,
            'total_errors' => 0
        ];

        $results['total_notified'] = $results['expiring']['notified']
            + $results['expired']['notified']
            + $results['missed_pings']['notified'];

        $results['total_errors'] = $results['expiring']['errors']
            + $results['expired']['errors']
            + $results['missed_pings']['errors'];

        logger()->info('Notification scheduler completed', $results);

        return $results;
    }
}
