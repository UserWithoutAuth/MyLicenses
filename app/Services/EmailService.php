<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Email Service
 *
 * Handles sending emails for notifications, alerts, and communications
 *
 * @package LicenseServer\Services
 */
class EmailService
{
    private PHPMailer $mailer;
    private bool $enabled;

    public function __construct()
    {
        $this->enabled = env('MAIL_ENABLED', true);
        $this->mailer = new PHPMailer(true);
        $this->configure();
    }

    /**
     * Configure PHPMailer
     *
     * @return void
     */
    private function configure(): void
    {
        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = env('MAIL_HOST', 'smtp.hostinger.com');
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = env('MAIL_USERNAME');
            $this->mailer->Password = env('MAIL_PASSWORD');
            $this->mailer->SMTPSecure = env('MAIL_ENCRYPTION', 'tls');
            $this->mailer->Port = env('MAIL_PORT', 587);
            $this->mailer->CharSet = 'UTF-8';

            // Default sender
            $this->mailer->setFrom(
                env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
                env('MAIL_FROM_NAME', 'License Server')
            );

            // Debugging (disable in production)
            $this->mailer->SMTPDebug = env('APP_DEBUG', false) ? 2 : 0;

        } catch (Exception $e) {
            logger()->error('Email configuration failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send email
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param string|null $recipientName Recipient name
     * @return bool Success status
     */
    public function send(string $to, string $subject, string $body, ?string $recipientName = null): bool
    {
        if (!$this->enabled) {
            logger()->info('Email sending disabled', [
                'to' => $to,
                'subject' => $subject
            ]);
            return false;
        }

        try {
            // Reset for new email
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();

            // Recipient
            $this->mailer->addAddress($to, $recipientName);

            // Content
            $this->mailer->isHTML(true);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $body;
            $this->mailer->AltBody = strip_tags($body);

            $this->mailer->send();

            logger()->info('Email sent successfully', [
                'to' => $to,
                'subject' => $subject
            ]);

            return true;

        } catch (Exception $e) {
            logger()->error('Email sending failed', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Send license created notification
     *
     * @param \App\Models\License $license
     * @return bool
     */
    public function sendLicenseCreated(\App\Models\License $license): bool
    {
        if (!env('MAIL_NOTIFY_LICENSE_CREATED', true)) {
            return false;
        }

        $customer = $license->customer;
        $product = $license->product;

        $subject = "Your {$product->name} License Has Been Created";

        $expiryDate = $license->expires_at
            ? $license->expires_at->format('F j, Y')
            : 'Never (Lifetime License)';

        $body = $this->renderTemplate('license-created', [
            'customer_name' => $customer->name ?? $customer->email,
            'product_name' => $product->name,
            'license_key' => $license->license_key,
            'expires_at' => $expiryDate,
            'max_activations' => $license->max_activations,
            'activation_url' => env('APP_URL') . '/portal/activate'
        ]);

        return $this->send($customer->email, $subject, $body, $customer->name);
    }

    /**
     * Send license activated notification
     *
     * @param \App\Models\License $license
     * @param \App\Models\Activation $activation
     * @return bool
     */
    public function sendLicenseActivated(\App\Models\License $license, \App\Models\Activation $activation): bool
    {
        if (!env('MAIL_NOTIFY_LICENSE_ACTIVATED', true)) {
            return false;
        }

        $customer = $license->customer;
        $machine = $activation->machine;

        $subject = "License Activated on New Machine";

        $body = $this->renderTemplate('license-activated', [
            'customer_name' => $customer->name ?? $customer->email,
            'license_key' => $license->license_key,
            'machine_name' => $machine->machine_name ?? 'Unknown',
            'machine_os' => $machine->os_info ?? 'Unknown',
            'activated_at' => $activation->activated_at->format('F j, Y g:i A'),
            'activations_used' => $license->current_activations,
            'activations_max' => $license->max_activations,
            'portal_url' => env('APP_URL') . '/portal/licenses'
        ]);

        return $this->send($customer->email, $subject, $body, $customer->name);
    }

    /**
     * Send license expiring notification
     *
     * @param \App\Models\License $license
     * @param int $daysRemaining
     * @return bool
     */
    public function sendLicenseExpiring(\App\Models\License $license, int $daysRemaining): bool
    {
        $customer = $license->customer;
        $product = $license->product;

        $subject = "Your {$product->name} License Expires in {$daysRemaining} Days";

        $body = $this->renderTemplate('license-expiring', [
            'customer_name' => $customer->name ?? $customer->email,
            'product_name' => $product->name,
            'license_key' => $license->license_key,
            'days_remaining' => $daysRemaining,
            'expires_at' => $license->expires_at->format('F j, Y'),
            'renewal_url' => env('APP_URL') . '/portal/renew/' . $license->id
        ]);

        return $this->send($customer->email, $subject, $body, $customer->name);
    }

    /**
     * Send license expired notification
     *
     * @param \App\Models\License $license
     * @return bool
     */
    public function sendLicenseExpired(\App\Models\License $license): bool
    {
        if (!env('MAIL_NOTIFY_LICENSE_EXPIRED', true)) {
            return false;
        }

        $customer = $license->customer;
        $product = $license->product;

        $subject = "Your {$product->name} License Has Expired";

        $gracePeriodDays = $license->grace_period_days;
        $gracePeriodEnd = $license->expires_at->copy()->addDays($gracePeriodDays);

        $body = $this->renderTemplate('license-expired', [
            'customer_name' => $customer->name ?? $customer->email,
            'product_name' => $product->name,
            'license_key' => $license->license_key,
            'expired_at' => $license->expires_at->format('F j, Y'),
            'grace_period_days' => $gracePeriodDays,
            'grace_period_end' => $gracePeriodEnd->format('F j, Y'),
            'renewal_url' => env('APP_URL') . '/portal/renew/' . $license->id
        ]);

        return $this->send($customer->email, $subject, $body, $customer->name);
    }

    /**
     * Send license revoked notification
     *
     * @param \App\Models\License $license
     * @param string $reason
     * @return bool
     */
    public function sendLicenseRevoked(\App\Models\License $license, string $reason): bool
    {
        $customer = $license->customer;
        $product = $license->product;

        $subject = "Your {$product->name} License Has Been Revoked";

        $body = $this->renderTemplate('license-revoked', [
            'customer_name' => $customer->name ?? $customer->email,
            'product_name' => $product->name,
            'license_key' => $license->license_key,
            'reason' => $reason,
            'support_url' => env('APP_URL') . '/support'
        ]);

        return $this->send($customer->email, $subject, $body, $customer->name);
    }

    /**
     * Send suspicious activity alert
     *
     * @param \App\Models\License $license
     * @param string $activity
     * @param array $details
     * @return bool
     */
    public function sendSuspiciousActivity(\App\Models\License $license, string $activity, array $details = []): bool
    {
        if (!env('MAIL_NOTIFY_SUSPICIOUS_ACTIVITY', true)) {
            return false;
        }

        $customer = $license->customer;

        $subject = "Suspicious Activity Detected on Your License";

        $detailsHtml = '';
        foreach ($details as $key => $value) {
            $detailsHtml .= "<li><strong>" . ucfirst(str_replace('_', ' ', $key)) . ":</strong> {$value}</li>";
        }

        $body = $this->renderTemplate('suspicious-activity', [
            'customer_name' => $customer->name ?? $customer->email,
            'license_key' => $license->license_key,
            'activity' => $activity,
            'details' => $detailsHtml,
            'portal_url' => env('APP_URL') . '/portal/licenses'
        ]);

        return $this->send($customer->email, $subject, $body, $customer->name);
    }

    /**
     * Render email template
     *
     * @param string $template Template name
     * @param array $data Template variables
     * @return string HTML content
     */
    private function renderTemplate(string $template, array $data): string
    {
        // Extract variables
        extract($data);

        $appName = env('APP_NAME', 'License Server');
        $appUrl = env('APP_URL', 'https://example.com');

        // Common styles
        $styles = <<<CSS
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; background: #f5f7fa; margin: 0; padding: 0; }
.container { max-width: 600px; margin: 40px auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
.header h1 { margin: 0; font-size: 24px; }
.content { padding: 30px; }
.content h2 { color: #667eea; margin-top: 0; }
.license-box { background: #f5f7fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #667eea; }
.license-key { font-family: 'Courier New', monospace; font-size: 18px; font-weight: bold; color: #667eea; letter-spacing: 1px; }
.button { display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
.button:hover { background: #5568d3; }
.footer { background: #f5f7fa; padding: 20px; text-align: center; font-size: 12px; color: #666; }
.alert { padding: 15px; border-radius: 6px; margin: 20px 0; }
.alert-warning { background: #fff3e0; border-left: 4px solid #ff9800; color: #e65100; }
.alert-danger { background: #ffebee; border-left: 4px solid #f44336; color: #c62828; }
.info-list { list-style: none; padding: 0; }
.info-list li { padding: 8px 0; border-bottom: 1px solid #e0e0e0; }
CSS;

        // Template-specific content
        switch ($template) {
            case 'license-created':
                return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>{$styles}</style></head><body>
<div class="container">
    <div class="header"><h1>🎉 License Created</h1></div>
    <div class="content">
        <h2>Hello {$customer_name}!</h2>
        <p>Your license for <strong>{$product_name}</strong> has been successfully created.</p>

        <div class="license-box">
            <p style="margin: 0; color: #666; font-size: 12px;">YOUR LICENSE KEY</p>
            <p class="license-key">{$license_key}</p>
        </div>

        <ul class="info-list">
            <li><strong>Product:</strong> {$product_name}</li>
            <li><strong>Expires:</strong> {$expires_at}</li>
            <li><strong>Max Activations:</strong> {$max_activations}</li>
        </ul>

        <p>To activate your license, visit the activation portal:</p>
        <a href="{$activation_url}" class="button">Activate License</a>

        <p style="margin-top: 30px; font-size: 14px; color: #666;">
            Keep this license key safe. You'll need it to activate the software on your devices.
        </p>
    </div>
    <div class="footer">
        <p>&copy; {$appName} | <a href="{$appUrl}">{$appUrl}</a></p>
    </div>
</div>
</body></html>
HTML;

            case 'license-activated':
                return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>{$styles}</style></head><body>
<div class="container">
    <div class="header"><h1>✅ License Activated</h1></div>
    <div class="content">
        <h2>Hello {$customer_name}!</h2>
        <p>Your license has been successfully activated on a new machine.</p>

        <div class="license-box">
            <p style="margin: 0; color: #666; font-size: 12px;">LICENSE KEY</p>
            <p class="license-key">{$license_key}</p>
        </div>

        <ul class="info-list">
            <li><strong>Machine:</strong> {$machine_name}</li>
            <li><strong>Operating System:</strong> {$machine_os}</li>
            <li><strong>Activated:</strong> {$activated_at}</li>
            <li><strong>Activations Used:</strong> {$activations_used} of {$activations_max}</li>
        </ul>

        <p>If you didn't perform this activation, please contact support immediately.</p>
        <a href="{$portal_url}" class="button">View All Licenses</a>
    </div>
    <div class="footer">
        <p>&copy; {$appName} | <a href="{$appUrl}">{$appUrl}</a></p>
    </div>
</div>
</body></html>
HTML;

            case 'license-expiring':
                return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>{$styles}</style></head><body>
<div class="container">
    <div class="header"><h1>⏰ License Expiring Soon</h1></div>
    <div class="content">
        <h2>Hello {$customer_name}!</h2>

        <div class="alert alert-warning">
            <strong>⚠️ Your license will expire in {$days_remaining} days!</strong>
        </div>

        <p>Your license for <strong>{$product_name}</strong> is approaching its expiration date.</p>

        <div class="license-box">
            <p style="margin: 0; color: #666; font-size: 12px;">LICENSE KEY</p>
            <p class="license-key">{$license_key}</p>
        </div>

        <ul class="info-list">
            <li><strong>Product:</strong> {$product_name}</li>
            <li><strong>Expires:</strong> {$expires_at}</li>
            <li><strong>Days Remaining:</strong> {$days_remaining}</li>
        </ul>

        <p>Renew now to avoid any interruption in service:</p>
        <a href="{$renewal_url}" class="button">Renew License</a>
    </div>
    <div class="footer">
        <p>&copy; {$appName} | <a href="{$appUrl}">{$appUrl}</a></p>
    </div>
</div>
</body></html>
HTML;

            case 'license-expired':
                return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>{$styles}</style></head><body>
<div class="container">
    <div class="header"><h1>❌ License Expired</h1></div>
    <div class="content">
        <h2>Hello {$customer_name}!</h2>

        <div class="alert alert-danger">
            <strong>Your license has expired!</strong>
        </div>

        <p>Your license for <strong>{$product_name}</strong> expired on {$expired_at}.</p>

        <div class="license-box">
            <p style="margin: 0; color: #666; font-size: 12px;">LICENSE KEY</p>
            <p class="license-key">{$license_key}</p>
        </div>

        <p>You have a <strong>{$grace_period_days}-day grace period</strong> until {$grace_period_end}. After this period, the software will stop working.</p>

        <p>Renew now to maintain uninterrupted access:</p>
        <a href="{$renewal_url}" class="button">Renew License Now</a>
    </div>
    <div class="footer">
        <p>&copy; {$appName} | <a href="{$appUrl}">{$appUrl}</a></p>
    </div>
</div>
</body></html>
HTML;

            case 'license-revoked':
                return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>{$styles}</style></head><body>
<div class="container">
    <div class="header"><h1>🚫 License Revoked</h1></div>
    <div class="content">
        <h2>Hello {$customer_name}!</h2>

        <div class="alert alert-danger">
            <strong>Your license has been revoked!</strong>
        </div>

        <p>Your license for <strong>{$product_name}</strong> has been revoked.</p>

        <div class="license-box">
            <p style="margin: 0; color: #666; font-size: 12px;">LICENSE KEY</p>
            <p class="license-key">{$license_key}</p>
        </div>

        <p><strong>Reason:</strong> {$reason}</p>

        <p>If you believe this is an error or need assistance, please contact our support team:</p>
        <a href="{$support_url}" class="button">Contact Support</a>
    </div>
    <div class="footer">
        <p>&copy; {$appName} | <a href="{$appUrl}">{$appUrl}</a></p>
    </div>
</div>
</body></html>
HTML;

            case 'suspicious-activity':
                return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style>{$styles}</style></head><body>
<div class="container">
    <div class="header"><h1>⚠️ Security Alert</h1></div>
    <div class="content">
        <h2>Hello {$customer_name}!</h2>

        <div class="alert alert-danger">
            <strong>Suspicious activity detected on your license!</strong>
        </div>

        <p>We detected unusual activity on license: <strong class="license-key">{$license_key}</strong></p>

        <p><strong>Activity:</strong> {$activity}</p>

        <p><strong>Details:</strong></p>
        <ul>{$details}</ul>

        <p>If this was not you, please secure your account immediately:</p>
        <a href="{$portal_url}" class="button">Review Activity</a>
    </div>
    <div class="footer">
        <p>&copy; {$appName} | <a href="{$appUrl}">{$appUrl}</a></p>
    </div>
</div>
</body></html>
HTML;

            default:
                return '<html><body><p>Email template not found.</p></body></html>';
        }
    }
}
