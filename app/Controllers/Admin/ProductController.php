<?php

namespace App\Controllers\Admin;

use App\Models\Product;
use App\Models\LicenseTier;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Product Management Controller
 *
 * Handles CRUD operations for products in the admin panel
 *
 * @package App\Controllers\Admin
 */
class ProductController
{
    /**
     * List all products
     */
    public function index(Request $request): Response
    {
        try {
            // Check permission
            $user = get_current_user();
            if (!$user || !$user->can('products.view')) {
                return new Response('Forbidden', 403);
            }

            $products = Product::with('tiers')->orderBy('created_at', 'desc')->get();

            $html = $this->renderProductListPage($products);

            return new Response($html);

        } catch (\Exception $e) {
            logger('error', 'Product list error: ' . $e->getMessage());
            return new Response('Internal Server Error', 500);
        }
    }

    /**
     * Show create product form
     */
    public function create(Request $request): Response
    {
        try {
            // Check permission
            $user = get_current_user();
            if (!$user || !$user->can('products.create')) {
                return new Response('Forbidden', 403);
            }

            $html = $this->renderProductForm();

            return new Response($html);

        } catch (\Exception $e) {
            logger('error', 'Product create form error: ' . $e->getMessage());
            return new Response('Internal Server Error', 500);
        }
    }

    /**
     * Store new product
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Check permission
            $user = get_current_user();
            if (!$user || !$user->can('products.create')) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Insufficient permissions'
                ], 403);
            }

            // Validate input
            $data = json_decode($request->getContent(), true);
            $errors = $this->validateProductData($data);

            if (!empty($errors)) {
                return new JsonResponse([
                    'success' => false,
                    'errors' => $errors
                ], 422);
            }

            // Create product
            $product = Product::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'version' => $data['version'] ?? '1.0.0',
                'platform' => $data['platform'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'metadata' => json_encode($data['metadata'] ?? []),
            ]);

            // Create default tier if requested
            if (!empty($data['create_default_tier'])) {
                LicenseTier::create([
                    'product_id' => $product->id,
                    'name' => 'Standard',
                    'description' => 'Standard license tier',
                    'duration_days' => 365,
                    'max_activations' => 1,
                    'price' => 0.00,
                    'currency' => 'USD',
                    'is_active' => true,
                ]);
            }

            // Audit log
            audit_log('product.created', $user->id, [
                'product_id' => $product->id,
                'product_name' => $product->name
            ]);

            logger('info', 'Product created', [
                'product_id' => $product->id,
                'name' => $product->name,
                'user_id' => $user->id
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Product created successfully',
                'product' => $product
            ]);

        } catch (\Exception $e) {
            logger('error', 'Product creation failed: ' . $e->getMessage());

            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to create product: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show edit product form
     */
    public function edit(Request $request, int $id): Response
    {
        try {
            // Check permission
            $user = get_current_user();
            if (!$user || !$user->can('products.edit')) {
                return new Response('Forbidden', 403);
            }

            $product = Product::with('tiers')->find($id);

            if (!$product) {
                return new Response('Product not found', 404);
            }

            $html = $this->renderProductForm($product);

            return new Response($html);

        } catch (\Exception $e) {
            logger('error', 'Product edit form error: ' . $e->getMessage());
            return new Response('Internal Server Error', 500);
        }
    }

    /**
     * Update product
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            // Check permission
            $user = get_current_user();
            if (!$user || !$user->can('products.edit')) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Insufficient permissions'
                ], 403);
            }

            $product = Product::find($id);

            if (!$product) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Product not found'
                ], 404);
            }

            // Validate input
            $data = json_decode($request->getContent(), true);
            $errors = $this->validateProductData($data, $id);

            if (!empty($errors)) {
                return new JsonResponse([
                    'success' => false,
                    'errors' => $errors
                ], 422);
            }

            // Update product
            $product->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'version' => $data['version'] ?? $product->version,
                'platform' => $data['platform'] ?? null,
                'is_active' => $data['is_active'] ?? $product->is_active,
                'metadata' => json_encode($data['metadata'] ?? []),
            ]);

            // Audit log
            audit_log('product.updated', $user->id, [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'changes' => $data
            ]);

            logger('info', 'Product updated', [
                'product_id' => $product->id,
                'name' => $product->name,
                'user_id' => $user->id
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Product updated successfully',
                'product' => $product
            ]);

        } catch (\Exception $e) {
            logger('error', 'Product update failed: ' . $e->getMessage());

            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to update product: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show product details
     */
    public function show(Request $request, int $id): Response
    {
        try {
            // Check permission
            $user = get_current_user();
            if (!$user || !$user->can('products.view')) {
                return new Response('Forbidden', 403);
            }

            $product = Product::with(['tiers', 'licenses'])->find($id);

            if (!$product) {
                return new Response('Product not found', 404);
            }

            $html = $this->renderProductDetailPage($product);

            return new Response($html);

        } catch (\Exception $e) {
            logger('error', 'Product detail error: ' . $e->getMessage());
            return new Response('Internal Server Error', 500);
        }
    }

    /**
     * Delete product
     */
    public function delete(Request $request, int $id): JsonResponse
    {
        try {
            // Check permission
            $user = get_current_user();
            if (!$user || !$user->can('products.delete')) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Insufficient permissions'
                ], 403);
            }

            $product = Product::find($id);

            if (!$product) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Product not found'
                ], 404);
            }

            // Check if product has active licenses
            $activeLicenses = $product->licenses()->where('status', 'active')->count();
            if ($activeLicenses > 0) {
                return new JsonResponse([
                    'success' => false,
                    'error' => "Cannot delete product with {$activeLicenses} active license(s). Deactivate or revoke licenses first."
                ], 400);
            }

            $productName = $product->name;
            $product->delete();

            // Audit log
            audit_log('product.deleted', $user->id, [
                'product_id' => $id,
                'product_name' => $productName
            ]);

            logger('info', 'Product deleted', [
                'product_id' => $id,
                'name' => $productName,
                'user_id' => $user->id
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Product deleted successfully'
            ]);

        } catch (\Exception $e) {
            logger('error', 'Product deletion failed: ' . $e->getMessage());

            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to delete product: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle product status
     */
    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        try {
            // Check permission
            $user = get_current_user();
            if (!$user || !$user->can('products.edit')) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Insufficient permissions'
                ], 403);
            }

            $product = Product::find($id);

            if (!$product) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Product not found'
                ], 404);
            }

            $product->is_active = !$product->is_active;
            $product->save();

            // Audit log
            audit_log('product.status_changed', $user->id, [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'new_status' => $product->is_active ? 'active' : 'inactive'
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Product status updated',
                'is_active' => $product->is_active
            ]);

        } catch (\Exception $e) {
            logger('error', 'Product status toggle failed: ' . $e->getMessage());

            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to update status'
            ], 500);
        }
    }

    /**
     * Validate product data
     */
    private function validateProductData(array $data, ?int $productId = null): array
    {
        $errors = [];

        // Name validation
        if (empty($data['name'])) {
            $errors['name'] = 'Product name is required';
        } elseif (strlen($data['name']) < 3) {
            $errors['name'] = 'Product name must be at least 3 characters';
        } elseif (strlen($data['name']) > 100) {
            $errors['name'] = 'Product name must not exceed 100 characters';
        } else {
            // Check for duplicate name
            $query = Product::where('name', $data['name']);
            if ($productId) {
                $query->where('id', '!=', $productId);
            }
            if ($query->exists()) {
                $errors['name'] = 'A product with this name already exists';
            }
        }

        // Version validation
        if (!empty($data['version'])) {
            if (!preg_match('/^\d+\.\d+(\.\d+)?$/', $data['version'])) {
                $errors['version'] = 'Version must be in format: X.Y or X.Y.Z (e.g., 1.0 or 1.0.0)';
            }
        }

        // Platform validation
        if (!empty($data['platform'])) {
            $validPlatforms = ['windows', 'linux', 'macos', 'cross-platform'];
            if (!in_array(strtolower($data['platform']), $validPlatforms)) {
                $errors['platform'] = 'Invalid platform. Choose from: ' . implode(', ', $validPlatforms);
            }
        }

        return $errors;
    }

    /**
     * Render product list page
     */
    private function renderProductListPage($products): string
    {
        $user = get_current_user();
        $canCreate = $user && $user->can('products.create');
        $canEdit = $user && $user->can('products.edit');
        $canDelete = $user && $user->can('products.delete');

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - License Server Admin</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .header h1 {
            color: #333;
            margin-bottom: 10px;
        }

        .header p {
            color: #666;
        }

        .nav {
            margin-top: 20px;
        }

        .nav a {
            color: #667eea;
            text-decoration: none;
            margin-right: 20px;
        }

        .nav a:hover {
            text-decoration: underline;
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
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .stat-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }

        .stat-card .value {
            color: #333;
            font-size: 32px;
            font-weight: bold;
        }

        .actions {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 14px;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .search-bar {
            margin-bottom: 20px;
        }

        .search-bar input {
            width: 100%;
            max-width: 400px;
            padding: 12px 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .product-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 20px;
            transition: all 0.3s;
        }

        .product-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .product-card-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .product-card-title {
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }

        .product-card-version {
            font-size: 12px;
            color: #666;
            background: #f3f4f6;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .product-card-description {
            color: #666;
            font-size: 14px;
            margin-bottom: 15px;
            min-height: 40px;
        }

        .product-card-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            padding: 10px 0;
            border-top: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
        }

        .product-card-stat {
            text-align: center;
        }

        .product-card-stat .label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
        }

        .product-card-stat .value {
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }

        .product-card-actions {
            display: flex;
            gap: 10px;
            justify-content: space-between;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📦 Products</h1>
            <p>Manage software products and license tiers</p>
            <div class="nav">
                <a href="/admin/dashboard">← Back to Dashboard</a>
                <a href="/admin/licenses">Licenses</a>
                <a href="/admin/backups">Backups</a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Products</h3>
                <div class="value"><?php echo count($products); ?></div>
            </div>
            <div class="stat-card">
                <h3>Active Products</h3>
                <div class="value"><?php echo $products->where('is_active', true)->count(); ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Licenses</h3>
                <div class="value"><?php echo $products->sum(function($p) { return $p->licenses->count(); }); ?></div>
            </div>
            <div class="stat-card">
                <h3>License Tiers</h3>
                <div class="value"><?php echo $products->sum(function($p) { return $p->tiers->count(); }); ?></div>
            </div>
        </div>

        <div class="actions">
            <div class="search-bar">
                <input type="text" id="searchInput" placeholder="🔍 Search products...">
            </div>
            <div>
                <?php if ($canCreate): ?>
                    <a href="/admin/products/create" class="btn btn-primary">
                        ➕ Create Product
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div id="alertContainer"></div>

        <div class="content">
            <?php if ($products->isEmpty()): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📦</div>
                    <h3>No products found</h3>
                    <p>Create your first product to start issuing licenses</p>
                    <?php if ($canCreate): ?>
                        <a href="/admin/products/create" class="btn btn-primary" style="margin-top: 20px;">
                            Create First Product
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="product-grid" id="productGrid">
                    <?php foreach ($products as $product): ?>
                        <div class="product-card" data-name="<?php echo strtolower($product->name); ?>">
                            <div class="product-card-header">
                                <div>
                                    <div class="product-card-title"><?php echo htmlspecialchars($product->name); ?></div>
                                    <?php if ($product->version): ?>
                                        <div class="product-card-version">v<?php echo htmlspecialchars($product->version); ?></div>
                                    <?php endif; ?>
                                </div>
                                <span class="badge <?php echo $product->is_active ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo $product->is_active ? '✓ Active' : '✗ Inactive'; ?>
                                </span>
                            </div>

                            <div class="product-card-description">
                                <?php echo htmlspecialchars($product->description ?: 'No description'); ?>
                            </div>

                            <div class="product-card-stats">
                                <div class="product-card-stat">
                                    <div class="label">Licenses</div>
                                    <div class="value"><?php echo $product->licenses->count(); ?></div>
                                </div>
                                <div class="product-card-stat">
                                    <div class="label">Tiers</div>
                                    <div class="value"><?php echo $product->tiers->count(); ?></div>
                                </div>
                                <?php if ($product->platform): ?>
                                <div class="product-card-stat">
                                    <div class="label">Platform</div>
                                    <div class="value" style="font-size: 12px;"><?php echo strtoupper($product->platform); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="product-card-actions">
                                <a href="/admin/products/<?php echo $product->id; ?>" class="btn btn-secondary btn-sm">
                                    👁️ View
                                </a>
                                <?php if ($canEdit): ?>
                                    <a href="/admin/products/<?php echo $product->id; ?>/edit" class="btn btn-primary btn-sm">
                                        ✏️ Edit
                                    </a>
                                    <button onclick="toggleStatus(<?php echo $product->id; ?>)" class="btn <?php echo $product->is_active ? 'btn-secondary' : 'btn-success'; ?> btn-sm">
                                        <?php echo $product->is_active ? '⏸️' : '▶️'; ?>
                                    </button>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <button onclick="deleteProduct(<?php echo $product->id; ?>, '<?php echo htmlspecialchars($product->name); ?>')" class="btn btn-danger btn-sm">
                                        🗑️
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Search functionality
        document.getElementById('searchInput')?.addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase();
            const cards = document.querySelectorAll('.product-card');

            cards.forEach(card => {
                const name = card.getAttribute('data-name');
                if (name.includes(query)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Toggle product status
        async function toggleStatus(id) {
            try {
                const response = await fetch(`/admin/products/${id}/toggle`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('✅ Product status updated', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert('❌ ' + result.error, 'error');
                }
            } catch (error) {
                showAlert('❌ Error: ' + error.message, 'error');
            }
        }

        // Delete product
        async function deleteProduct(id, name) {
            if (!confirm(`Delete product "${name}"? This action cannot be undone.`)) {
                return;
            }

            try {
                const response = await fetch(`/admin/products/${id}`, {
                    method: 'DELETE'
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('✅ Product deleted successfully', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('❌ ' + result.error, 'error');
                }
            } catch (error) {
                showAlert('❌ Error: ' + error.message, 'error');
            }
        }

        // Show alert
        function showAlert(message, type) {
            const container = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.className = 'alert alert-' + type;
            alert.textContent = message;
            container.innerHTML = '';
            container.appendChild(alert);

            if (type === 'success') {
                setTimeout(() => alert.remove(), 5000);
            }
        }
    </script>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Render product form (create/edit)
     */
    private function renderProductForm($product = null): string
    {
        $isEdit = $product !== null;
        $title = $isEdit ? 'Edit Product' : 'Create Product';
        $action = $isEdit ? "/admin/products/{$product->id}" : '/admin/products';
        $method = $isEdit ? 'PUT' : 'POST';

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?> - License Server Admin</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .header h1 {
            color: #333;
            margin-bottom: 10px;
        }

        .nav a {
            color: #667eea;
            text-decoration: none;
            margin-right: 20px;
        }

        .content {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .form-group label .required {
            color: #ef4444;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-group small {
            display: block;
            margin-top: 5px;
            color: #666;
            font-size: 12px;
        }

        .form-group .error {
            color: #ef4444;
            font-size: 12px;
            margin-top: 5px;
            display: none;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
        }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 30px;
            border-top: 1px solid #e5e7eb;
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php echo $title; ?></h1>
            <div class="nav">
                <a href="/admin/products">← Back to Products</a>
            </div>
        </div>

        <div class="content">
            <div id="alertContainer"></div>

            <form id="productForm">
                <div class="form-group">
                    <label>Product Name <span class="required">*</span></label>
                    <input type="text" name="name" id="name"
                           value="<?php echo $isEdit ? htmlspecialchars($product->name) : ''; ?>"
                           required>
                    <small>Unique name for this product (e.g., "MyApp Pro")</small>
                    <div class="error" id="nameError"></div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="description"><?php echo $isEdit ? htmlspecialchars($product->description) : ''; ?></textarea>
                    <small>Brief description of the product</small>
                </div>

                <div class="form-group">
                    <label>Version</label>
                    <input type="text" name="version" id="version"
                           value="<?php echo $isEdit ? htmlspecialchars($product->version) : '1.0.0'; ?>"
                           placeholder="1.0.0">
                    <small>Product version (e.g., 1.0.0)</small>
                    <div class="error" id="versionError"></div>
                </div>

                <div class="form-group">
                    <label>Platform</label>
                    <select name="platform" id="platform">
                        <option value="">Select platform...</option>
                        <option value="windows" <?php echo ($isEdit && $product->platform === 'windows') ? 'selected' : ''; ?>>Windows</option>
                        <option value="linux" <?php echo ($isEdit && $product->platform === 'linux') ? 'selected' : ''; ?>>Linux</option>
                        <option value="macos" <?php echo ($isEdit && $product->platform === 'macos') ? 'selected' : ''; ?>>macOS</option>
                        <option value="cross-platform" <?php echo ($isEdit && $product->platform === 'cross-platform') ? 'selected' : ''; ?>>Cross-Platform</option>
                    </select>
                    <small>Target platform for this product</small>
                </div>

                <div class="form-group checkbox-group">
                    <input type="checkbox" name="is_active" id="is_active"
                           <?php echo (!$isEdit || $product->is_active) ? 'checked' : ''; ?>>
                    <label for="is_active">Active (allow license generation)</label>
                </div>

                <?php if (!$isEdit): ?>
                <div class="form-group checkbox-group">
                    <input type="checkbox" name="create_default_tier" id="create_default_tier" checked>
                    <label for="create_default_tier">Create default license tier</label>
                </div>
                <?php endif; ?>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <?php echo $isEdit ? '💾 Update Product' : '➕ Create Product'; ?>
                    </button>
                    <a href="/admin/products" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('productForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.textContent = '⏳ Saving...';

            // Clear previous errors
            document.querySelectorAll('.error').forEach(el => el.style.display = 'none');

            const formData = {
                name: document.getElementById('name').value,
                description: document.getElementById('description').value,
                version: document.getElementById('version').value,
                platform: document.getElementById('platform').value,
                is_active: document.getElementById('is_active').checked,
                <?php if (!$isEdit): ?>
                create_default_tier: document.getElementById('create_default_tier').checked
                <?php endif; ?>
            };

            try {
                const response = await fetch('<?php echo $action; ?>', {
                    method: '<?php echo $method; ?>',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(formData)
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('✅ Product <?php echo $isEdit ? 'updated' : 'created'; ?> successfully!', 'success');
                    setTimeout(() => {
                        window.location.href = '/admin/products';
                    }, 1500);
                } else if (result.errors) {
                    // Show validation errors
                    Object.keys(result.errors).forEach(field => {
                        const errorEl = document.getElementById(field + 'Error');
                        if (errorEl) {
                            errorEl.textContent = result.errors[field];
                            errorEl.style.display = 'block';
                        }
                    });
                    showAlert('❌ Please fix the errors below', 'error');
                    btn.disabled = false;
                    btn.textContent = '<?php echo $isEdit ? '💾 Update Product' : '➕ Create Product'; ?>';
                } else {
                    showAlert('❌ ' + result.error, 'error');
                    btn.disabled = false;
                    btn.textContent = '<?php echo $isEdit ? '💾 Update Product' : '➕ Create Product'; ?>';
                }
            } catch (error) {
                showAlert('❌ Error: ' + error.message, 'error');
                btn.disabled = false;
                btn.textContent = '<?php echo $isEdit ? '💾 Update Product' : '➕ Create Product'; ?>';
            }
        });

        function showAlert(message, type) {
            const container = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.className = 'alert alert-' + type;
            alert.textContent = message;
            container.innerHTML = '';
            container.appendChild(alert);
        }
    </script>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Render product detail page
     */
    private function renderProductDetailPage($product): string
    {
        // This would be implemented similarly with product details, tiers, and licenses
        // For now, redirect to edit page
        return $this->renderProductForm($product);
    }
}
