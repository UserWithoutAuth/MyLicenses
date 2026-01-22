<?php

namespace App\Controllers\Admin;

use App\Models\License;
use App\Models\Customer;
use App\Models\Activation;
use App\Models\Product;
use App\Services\AuthService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Admin Dashboard Controller
 *
 * Displays overview statistics and metrics
 *
 * @package LicenseServer\Controllers\Admin
 */
class DashboardController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    /**
     * Show dashboard
     *
     * GET /admin/dashboard
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $user = $this->auth->getCurrentUser();

        if (!$user) {
            return new Response('Unauthorized', 401);
        }

        // Get statistics
        $stats = $this->getStatistics();

        $html = $this->renderDashboard($user, $stats);
        return new Response($html);
    }

    /**
     * Get dashboard statistics
     *
     * @return array
     */
    private function getStatistics(): array
    {
        return [
            'total_licenses' => License::count(),
            'active_licenses' => License::where('status', 'active')->count(),
            'expired_licenses' => License::expired()->count(),
            'expiring_soon' => License::expiringSoon(7)->count(),
            'total_customers' => Customer::count(),
            'active_customers' => Customer::active()->count(),
            'total_activations' => Activation::count(),
            'active_activations' => Activation::where('status', 'active')->count(),
            'total_products' => Product::count(),
            'active_products' => Product::active()->count(),
            'recent_licenses' => License::orderBy('created_at', 'desc')->limit(10)->get(),
            'recent_activations' => Activation::with(['license', 'machine'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
        ];
    }

    /**
     * Get statistics API endpoint
     *
     * GET /admin/api/statistics
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function statistics(Request $request): JsonResponse
    {
        $user = $this->auth->getCurrentUser();

        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $stats = $this->getStatistics();

        return new JsonResponse($stats);
    }

    /**
     * Render dashboard HTML
     *
     * @param \App\Models\User $user
     * @param array $stats
     * @return string
     */
    private function renderDashboard($user, array $stats): string
    {
        $appName = env('APP_NAME', 'License Server');
        $userName = htmlspecialchars($user->name ?? $user->email);
        $userRole = ucfirst(str_replace('_', ' ', $user->role));

        $totalLicenses = number_format($stats['total_licenses']);
        $activeLicenses = number_format($stats['active_licenses']);
        $expiredLicenses = number_format($stats['expired_licenses']);
        $expiringSoon = number_format($stats['expiring_soon']);
        $totalCustomers = number_format($stats['total_customers']);
        $activeActivations = number_format($stats['active_activations']);

        $recentLicensesHtml = '';
        foreach ($stats['recent_licenses'] as $license) {
            $statusBadge = $this->getStatusBadge($license->status);
            $expiryDate = $license->expires_at ? $license->expires_at->format('M d, Y') : 'Never';

            $recentLicensesHtml .= <<<HTML
            <tr>
                <td>{$license->license_key}</td>
                <td>{$license->product->name}</td>
                <td>{$license->customer->email}</td>
                <td>{$statusBadge}</td>
                <td>{$expiryDate}</td>
            </tr>
HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - {$appName}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f7fa;
            color: #333;
        }

        .header {
            background: white;
            border-bottom: 1px solid #e0e0e0;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .header h1 {
            font-size: 24px;
            color: #667eea;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-name {
            text-align: right;
        }

        .user-name strong {
            display: block;
            font-size: 14px;
        }

        .user-name small {
            color: #666;
            font-size: 12px;
        }

        .logout-btn {
            padding: 8px 16px;
            background: #f44336;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }

        .logout-btn:hover {
            background: #d32f2f;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-left: 4px solid #667eea;
        }

        .stat-card.warning {
            border-left-color: #ff9800;
        }

        .stat-card.danger {
            border-left-color: #f44336;
        }

        .stat-card.success {
            border-left-color: #4caf50;
        }

        .stat-value {
            font-size: 36px;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }

        .section h2 {
            font-size: 20px;
            margin-bottom: 20px;
            color: #333;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px;
            background: #f5f7fa;
            font-weight: 600;
            font-size: 14px;
            color: #666;
            border-bottom: 2px solid #e0e0e0;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }

        tr:hover {
            background: #fafafa;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-active {
            background: #e8f5e9;
            color: #4caf50;
        }

        .badge-expired {
            background: #ffebee;
            color: #f44336;
        }

        .badge-suspended {
            background: #fff3e0;
            color: #ff9800;
        }

        .nav {
            background: white;
            border-bottom: 1px solid #e0e0e0;
            padding: 0 30px;
        }

        .nav-links {
            display: flex;
            gap: 5px;
        }

        .nav-link {
            padding: 15px 20px;
            text-decoration: none;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .nav-link:hover {
            color: #667eea;
        }

        .nav-link.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔐 {$appName}</h1>
        <div class="user-info">
            <div class="user-name">
                <strong>{$userName}</strong>
                <small>{$userRole}</small>
            </div>
            <form method="POST" action="/admin/logout" style="display: inline;">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </div>

    <div class="nav">
        <div class="nav-links">
            <a href="/admin/dashboard" class="nav-link active">Dashboard</a>
            <a href="/admin/licenses" class="nav-link">Licenses</a>
            <a href="/admin/customers" class="nav-link">Customers</a>
            <a href="/admin/products" class="nav-link">Products</a>
            <a href="/admin/reports" class="nav-link">Reports</a>
            <a href="/admin/settings" class="nav-link">Settings</a>
        </div>
    </div>

    <div class="container">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">{$totalLicenses}</div>
                <div class="stat-label">Total Licenses</div>
            </div>

            <div class="stat-card success">
                <div class="stat-value">{$activeLicenses}</div>
                <div class="stat-label">Active Licenses</div>
            </div>

            <div class="stat-card warning">
                <div class="stat-value">{$expiringSoon}</div>
                <div class="stat-label">Expiring Soon</div>
            </div>

            <div class="stat-card danger">
                <div class="stat-value">{$expiredLicenses}</div>
                <div class="stat-label">Expired Licenses</div>
            </div>

            <div class="stat-card">
                <div class="stat-value">{$totalCustomers}</div>
                <div class="stat-label">Total Customers</div>
            </div>

            <div class="stat-card success">
                <div class="stat-value">{$activeActivations}</div>
                <div class="stat-label">Active Activations</div>
            </div>
        </div>

        <div class="section">
            <h2>Recent Licenses</h2>
            <table>
                <thead>
                    <tr>
                        <th>License Key</th>
                        <th>Product</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th>Expires</th>
                    </tr>
                </thead>
                <tbody>
                    {$recentLicensesHtml}
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get status badge HTML
     *
     * @param string $status
     * @return string
     */
    private function getStatusBadge(string $status): string
    {
        $class = 'badge-' . $status;
        $label = ucfirst($status);

        return "<span class=\"badge {$class}\">{$label}</span>";
    }
}
