<?php

namespace App\Controllers\Admin;

use App\Models\License;
use App\Models\Product;
use App\Models\Customer;
use App\Services\LicenseService;
use App\Services\AuthService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Exception;

/**
 * License Admin Controller
 *
 * Manages licenses through admin panel
 *
 * @package LicenseServer\Controllers\Admin
 */
class LicenseAdminController
{
    private LicenseService $licenseService;
    private AuthService $auth;

    public function __construct()
    {
        $this->licenseService = new LicenseService();
        $this->auth = new AuthService();
    }

    /**
     * List all licenses
     *
     * GET /admin/licenses
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $user = $this->auth->getCurrentUser();

        if (!$user || !$user->can('licenses.view')) {
            return new Response('Forbidden', 403);
        }

        // Get filters from query params
        $status = $request->query->get('status');
        $search = $request->query->get('search');
        $page = (int) $request->query->get('page', 1);
        $perPage = 25;

        // Build query
        $query = License::with(['product', 'customer']);

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('license_key', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($q) use ($search) {
                      $q->where('email', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                  });
            });
        }

        // Get total count
        $total = $query->count();

        // Get paginated results
        $licenses = $query->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $html = $this->renderLicensesIndex($user, $licenses, $total, $page, $perPage, $status, $search);
        return new Response($html);
    }

    /**
     * Show create license form
     *
     * GET /admin/licenses/create
     *
     * @param Request $request
     * @return Response
     */
    public function create(Request $request): Response
    {
        $user = $this->auth->getCurrentUser();

        if (!$user || !$user->can('licenses.create')) {
            return new Response('Forbidden', 403);
        }

        $products = Product::active()->get();
        $customers = Customer::active()->get();

        $html = $this->renderCreateForm($user, $products, $customers);
        return new Response($html);
    }

    /**
     * Store new license
     *
     * POST /admin/licenses
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->auth->getCurrentUser();

        if (!$user || !$user->can('licenses.create')) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        try {
            $data = json_decode($request->getContent(), true);

            // Validate required fields
            if (empty($data['product_id'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Product is required'
                ], 400);
            }

            if (empty($data['customer_email'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Customer email is required'
                ], 400);
            }

            // Prepare license data
            $licenseData = [
                'product_id' => $data['product_id'],
                'customer' => [
                    'email' => $data['customer_email'],
                    'name' => $data['customer_name'] ?? null,
                    'company' => $data['customer_company'] ?? null
                ],
                'license_type' => $data['license_type'] ?? 'standard',
                'max_activations' => $data['max_activations'] ?? 1,
                'duration_days' => $data['duration_days'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'grace_period_days' => $data['grace_period_days'] ?? 7,
                'expiry_message' => $data['expiry_message'] ?? null,
                'allow_offline' => $data['allow_offline'] ?? true,
                'allow_transfer' => $data['allow_transfer'] ?? true,
                'max_transfers' => $data['max_transfers'] ?? 3,
                'custom_fields' => $data['custom_fields'] ?? [],
                'features' => $data['features'] ?? [],
                'notes' => $data['notes'] ?? null
            ];

            // Generate license
            $license = $this->licenseService->generate($licenseData);

            audit_log('admin.license.created', [
                'license_id' => $license->id,
                'license_key' => $license->license_key,
                'admin_id' => $user->id
            ]);

            return new JsonResponse([
                'success' => true,
                'license' => [
                    'id' => $license->id,
                    'license_key' => $license->license_key,
                    'product' => $license->product->name,
                    'customer' => $license->customer->email,
                    'expires_at' => $license->expires_at ? $license->expires_at->toDateTimeString() : null
                ],
                'redirect' => '/admin/licenses/' . $license->id
            ]);

        } catch (Exception $e) {
            logger()->error('Failed to create license', [
                'error' => $e->getMessage(),
                'admin_id' => $user->id
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show license details
     *
     * GET /admin/licenses/{id}
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function show(Request $request, int $id): Response
    {
        $user = $this->auth->getCurrentUser();

        if (!$user || !$user->can('licenses.view')) {
            return new Response('Forbidden', 403);
        }

        $license = License::with(['product', 'customer', 'activations.machine'])
            ->find($id);

        if (!$license) {
            return new Response('License not found', 404);
        }

        $html = $this->renderLicenseDetails($user, $license);
        return new Response($html);
    }

    /**
     * Revoke license
     *
     * POST /admin/licenses/{id}/revoke
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function revoke(Request $request, int $id): JsonResponse
    {
        $user = $this->auth->getCurrentUser();

        if (!$user || !$user->can('licenses.revoke')) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $license = License::find($id);

        if (!$license) {
            return new JsonResponse(['error' => 'License not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Revoked by administrator';

        $license->revoke($reason);

        audit_log('admin.license.revoked', [
            'license_id' => $license->id,
            'license_key' => $license->license_key,
            'reason' => $reason,
            'admin_id' => $user->id
        ]);

        return new JsonResponse([
            'success' => true,
            'message' => 'License revoked successfully'
        ]);
    }

    /**
     * Render licenses index page
     */
    private function renderLicensesIndex($user, $licenses, $total, $page, $perPage, $status, $search): string
    {
        $appName = env('APP_NAME', 'License Server');
        $userName = htmlspecialchars($user->name ?? $user->email);

        $licensesHtml = '';
        foreach ($licenses as $license) {
            $statusBadge = $this->getStatusBadge($license->status);
            $expiryDate = $license->expires_at ? $license->expires_at->format('M d, Y') : 'Never';
            $activationsText = "{$license->current_activations} / {$license->max_activations}";

            $licensesHtml .= <<<HTML
            <tr>
                <td><a href="/admin/licenses/{$license->id}" style="color: #667eea; text-decoration: none;">{$license->license_key}</a></td>
                <td>{$license->product->name}</td>
                <td>{$license->customer->email}</td>
                <td>{$statusBadge}</td>
                <td>{$expiryDate}</td>
                <td>{$activationsText}</td>
                <td>
                    <a href="/admin/licenses/{$license->id}" class="btn-small">View</a>
                </td>
            </tr>
HTML;
        }

        $totalPages = ceil($total / $perPage);
        $paginationHtml = $this->renderPagination($page, $totalPages, $status, $search);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Licenses - {$appName}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; }
        .header { background: white; border-bottom: 1px solid #e0e0e0; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 24px; color: #667eea; }
        .container { max-width: 1400px; margin: 0 auto; padding: 30px; }
        .toolbar { background: white; padding: 20px; border-radius: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .search-box { padding: 10px 15px; border: 2px solid #e0e0e0; border-radius: 6px; width: 300px; }
        .btn { padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #5568d3; }
        .btn-small { padding: 5px 12px; background: #667eea; color: white; border-radius: 4px; font-size: 13px; text-decoration: none; }
        table { width: 100%; background: white; border-radius: 12px; overflow: hidden; }
        th { text-align: left; padding: 15px; background: #f5f7fa; font-weight: 600; border-bottom: 2px solid #e0e0e0; }
        td { padding: 15px; border-bottom: 1px solid #f0f0f0; }
        tr:hover { background: #fafafa; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .badge-active { background: #e8f5e9; color: #4caf50; }
        .badge-expired { background: #ffebee; color: #f44336; }
        .badge-suspended { background: #fff3e0; color: #ff9800; }
        .badge-revoked { background: #f5f5f5; color: #757575; }
        .pagination { display: flex; gap: 5px; margin-top: 20px; justify-content: center; }
        .page-btn { padding: 8px 12px; background: white; border: 1px solid #e0e0e0; border-radius: 4px; cursor: pointer; text-decoration: none; color: #333; }
        .page-btn:hover { background: #f5f7fa; }
        .page-btn.active { background: #667eea; color: white; border-color: #667eea; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Licenses</h1>
        <div>{$userName} | <a href="/admin/dashboard">Dashboard</a> | <form method="POST" action="/admin/logout" style="display: inline;"><button type="submit" style="background: none; border: none; color: #f44336; cursor: pointer;">Logout</button></form></div>
    </div>

    <div class="container">
        <div class="toolbar">
            <input type="text" class="search-box" placeholder="Search licenses..." value="{$search}">
            <a href="/admin/licenses/create" class="btn">+ Create License</a>
        </div>

        <table>
            <thead>
                <tr>
                    <th>License Key</th>
                    <th>Product</th>
                    <th>Customer</th>
                    <th>Status</th>
                    <th>Expires</th>
                    <th>Activations</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                {$licensesHtml}
            </tbody>
        </table>

        {$paginationHtml}
    </div>
</body>
</html>
HTML;
    }

    /**
     * Render create license form
     */
    private function renderCreateForm($user, $products, $customers): string
    {
        // Implementation placeholder - would contain full form HTML
        return '<html><body><h1>Create License Form</h1><p>Form implementation goes here</p></body></html>';
    }

    /**
     * Render license details page
     */
    private function renderLicenseDetails($user, $license): string
    {
        // Implementation placeholder - would contain full details HTML
        return '<html><body><h1>License Details</h1><p>Details implementation goes here</p></body></html>';
    }

    /**
     * Render pagination
     */
    private function renderPagination($page, $totalPages, $status, $search): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $html = '<div class="pagination">';

        for ($i = 1; $i <= $totalPages; $i++) {
            $active = $i == $page ? 'active' : '';
            $url = "/admin/licenses?page={$i}";
            if ($status) $url .= "&status={$status}";
            if ($search) $url .= "&search={$search}";

            $html .= "<a href=\"{$url}\" class=\"page-btn {$active}\">{$i}</a>";
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Get status badge HTML
     */
    private function getStatusBadge(string $status): string
    {
        $class = 'badge-' . $status;
        $label = ucfirst($status);
        return "<span class=\"badge {$class}\">{$label}</span>";
    }
}
