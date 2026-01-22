<?php

namespace App\Controllers\Admin;

use App\Services\BackupService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Backup Management Controller
 *
 * Handles backup operations in the admin panel
 *
 * @package App\Controllers\Admin
 */
class BackupController
{
    private $backupService;

    public function __construct()
    {
        $this->backupService = new BackupService();
    }

    /**
     * Show backup management page
     */
    public function index(Request $request): Response
    {
        $backups = $this->backupService->listBackups();
        $config = require BASE_PATH . '/config/backup.php';

        $html = $this->renderBackupPage($backups, $config);

        return new Response($html);
    }

    /**
     * Create new backup (AJAX)
     */
    public function create(Request $request): JsonResponse
    {
        try {
            // Check permission
            $user = get_current_user();
            if (!$user || !$user->can('backups.create')) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Insufficient permissions'
                ], 403);
            }

            // Create backup
            $result = $this->backupService->createBackup();

            // Audit log
            audit_log('backup.created', $user->id, [
                'backup_name' => $result['backup_name'] ?? null,
                'success' => $result['success']
            ]);

            return new JsonResponse($result);

        } catch (\Exception $e) {
            logger('error', 'Backup creation failed via admin: ' . $e->getMessage());

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download backup file
     */
    public function download(Request $request, string $filename): Response
    {
        try {
            // Check permission
            $user = get_current_user();
            if (!$user || !$user->can('backups.download')) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Insufficient permissions'
                ], 403);
            }

            $backupPath = BASE_PATH . '/storage/backups/' . basename($filename);

            if (!file_exists($backupPath)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Backup file not found'
                ], 404);
            }

            // Security check: ensure file is in backups directory
            if (realpath($backupPath) !== $backupPath ||
                strpos($backupPath, BASE_PATH . '/storage/backups/') !== 0) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid backup file'
                ], 400);
            }

            // Audit log
            audit_log('backup.downloaded', $user->id, [
                'filename' => $filename
            ]);

            $response = new BinaryFileResponse($backupPath);
            $response->headers->set('Content-Type', 'application/octet-stream');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . basename($filename) . '"');

            return $response;

        } catch (\Exception $e) {
            logger('error', 'Backup download failed: ' . $e->getMessage());

            return new JsonResponse([
                'success' => false,
                'error' => 'Download failed'
            ], 500);
        }
    }

    /**
     * Delete backup file
     */
    public function delete(Request $request, string $filename): JsonResponse
    {
        try {
            // Check permission
            $user = get_current_user();
            if (!$user || !$user->can('backups.delete')) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Insufficient permissions'
                ], 403);
            }

            $backupPath = BASE_PATH . '/storage/backups/' . basename($filename);

            if (!file_exists($backupPath)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Backup file not found'
                ], 404);
            }

            // Security check
            if (realpath($backupPath) !== $backupPath ||
                strpos($backupPath, BASE_PATH . '/storage/backups/') !== 0) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid backup file'
                ], 400);
            }

            // Delete backup file
            unlink($backupPath);

            // Delete checksum file if exists
            if (file_exists($backupPath . '.sha256')) {
                unlink($backupPath . '.sha256');
            }

            // Audit log
            audit_log('backup.deleted', $user->id, [
                'filename' => $filename
            ]);

            logger('info', 'Backup deleted via admin', [
                'filename' => $filename,
                'user_id' => $user->id
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Backup deleted successfully'
            ]);

        } catch (\Exception $e) {
            logger('error', 'Backup deletion failed: ' . $e->getMessage());

            return new JsonResponse([
                'success' => false,
                'error' => 'Deletion failed'
            ], 500);
        }
    }

    /**
     * Get backup configuration (AJAX)
     */
    public function getConfig(Request $request): JsonResponse
    {
        try {
            $config = require BASE_PATH . '/config/backup.php';

            return new JsonResponse([
                'success' => true,
                'config' => [
                    'name' => $config['name'],
                    'frequency' => $config['schedule']['frequency'],
                    'destinations' => [
                        'local' => $config['destinations']['local']['enabled'],
                        's3' => $config['destinations']['s3']['enabled'],
                        'sftp' => $config['destinations']['sftp']['enabled'],
                        'dropbox' => $config['destinations']['dropbox']['enabled'],
                    ],
                    'retention' => [
                        'keep_all_for_days' => $config['retention']['keep_all_for_days'],
                        'keep_daily_for_days' => $config['retention']['keep_daily_for_days'],
                        'keep_weekly_for_weeks' => $config['retention']['keep_weekly_for_weeks'],
                    ],
                    'encryption' => $config['encryption']['enabled'],
                    'compression' => $config['compression']['enabled'],
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Render backup management page
     */
    private function renderBackupPage(array $backups, array $config): string
    {
        $user = get_current_user();
        $canCreate = $user && $user->can('backups.create');
        $canDownload = $user && $user->can('backups.download');
        $canDelete = $user && $user->can('backups.delete');

        // Calculate storage usage
        $totalSize = 0;
        foreach ($backups as $backup) {
            $totalSize += $backup['size'];
        }

        $diskFree = disk_free_space(BASE_PATH . '/storage');
        $diskTotal = disk_total_space(BASE_PATH . '/storage');
        $diskUsed = $diskTotal - $diskFree;
        $diskPercent = round(($diskUsed / $diskTotal) * 100, 1);

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Management - License Server Admin</title>
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

        .stat-card .label {
            color: #999;
            font-size: 12px;
            margin-top: 5px;
        }

        .actions {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
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

        .btn-danger {
            background: #ef4444;
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

        .content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .table th {
            background: #f9fafb;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
        }

        .table td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }

        .table tr:hover {
            background: #f9fafb;
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

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .progress-bar {
            width: 100%;
            height: 10px;
            background: #e5e7eb;
            border-radius: 5px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #3b82f6);
            transition: width 0.3s;
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

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border-left: 4px solid #3b82f6;
        }

        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }

        .spinner {
            border: 3px solid #f3f4f6;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .config-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
            padding: 20px;
            background: #f9fafb;
            border-radius: 5px;
        }

        .config-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .config-item .icon {
            font-size: 20px;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🗄️ Backup Management</h1>
            <p>Create, manage, and restore system backups</p>
            <div class="nav">
                <a href="/admin/dashboard">← Back to Dashboard</a>
                <a href="/admin/licenses">Licenses</a>
                <a href="/admin/settings">Settings</a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Backups</h3>
                <div class="value"><?php echo count($backups); ?></div>
                <div class="label">Available for restore</div>
            </div>
            <div class="stat-card">
                <h3>Total Size</h3>
                <div class="value"><?php echo $this->formatBytes($totalSize); ?></div>
                <div class="label">All backups combined</div>
            </div>
            <div class="stat-card">
                <h3>Disk Usage</h3>
                <div class="value"><?php echo $diskPercent; ?>%</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $diskPercent; ?>%"></div>
                </div>
                <div class="label"><?php echo round($diskUsed / 1024 / 1024 / 1024, 2); ?> GB / <?php echo round($diskTotal / 1024 / 1024 / 1024, 2); ?> GB</div>
            </div>
            <div class="stat-card">
                <h3>Backup Frequency</h3>
                <div class="value"><?php echo ucfirst($config['schedule']['frequency']); ?></div>
                <div class="label">Automated schedule</div>
            </div>
        </div>

        <div class="actions">
            <?php if ($canCreate): ?>
                <button id="createBackupBtn" class="btn btn-primary">
                    📦 Create New Backup
                </button>
            <?php endif; ?>
            <button id="refreshBtn" class="btn btn-secondary">
                🔄 Refresh
            </button>
        </div>

        <div id="alertContainer"></div>

        <div class="loading" id="loadingIndicator">
            <div class="spinner"></div>
            <p style="margin-top: 15px;">Creating backup, please wait...</p>
        </div>

        <div class="content">
            <h2>Backup History</h2>

            <?php if (empty($backups)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📦</div>
                    <h3>No backups found</h3>
                    <p>Create your first backup to get started</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Backup Name</th>
                            <th>Created</th>
                            <th>Age</th>
                            <th>Size</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($backup['name']); ?></strong>
                                </td>
                                <td><?php echo $backup['created']; ?></td>
                                <td>
                                    <span class="badge badge-info">
                                        <?php echo $backup['age_days']; ?> days
                                    </span>
                                </td>
                                <td><?php echo $backup['size_human']; ?></td>
                                <td>
                                    <?php if ($canDownload): ?>
                                        <a href="/admin/backups/download/<?php echo urlencode($backup['name']); ?>"
                                           class="btn btn-secondary" style="padding: 6px 12px; font-size: 14px;">
                                            ⬇️ Download
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <button onclick="deleteBackup('<?php echo htmlspecialchars($backup['name']); ?>')"
                                                class="btn btn-danger" style="padding: 6px 12px; font-size: 14px;">
                                            🗑️ Delete
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 style="margin-top: 40px;">Backup Configuration</h2>
            <div class="config-grid">
                <div class="config-item">
                    <span class="icon">📁</span>
                    <div>
                        <strong>Local</strong><br>
                        <small><?php echo $config['destinations']['local']['enabled'] ? '✅ Enabled' : '❌ Disabled'; ?></small>
                    </div>
                </div>
                <div class="config-item">
                    <span class="icon">☁️</span>
                    <div>
                        <strong>S3</strong><br>
                        <small><?php echo $config['destinations']['s3']['enabled'] ? '✅ Enabled' : '❌ Disabled'; ?></small>
                    </div>
                </div>
                <div class="config-item">
                    <span class="icon">🔐</span>
                    <div>
                        <strong>Encryption</strong><br>
                        <small><?php echo $config['encryption']['enabled'] ? '✅ Enabled' : '❌ Disabled'; ?></small>
                    </div>
                </div>
                <div class="config-item">
                    <span class="icon">📦</span>
                    <div>
                        <strong>Compression</strong><br>
                        <small><?php echo $config['compression']['enabled'] ? '✅ Enabled' : '❌ Disabled'; ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Create backup
        document.getElementById('createBackupBtn')?.addEventListener('click', async function() {
            if (!confirm('Create a new backup? This may take several minutes.')) {
                return;
            }

            const btn = this;
            btn.disabled = true;
            document.getElementById('loadingIndicator').style.display = 'block';
            showAlert('Creating backup...', 'info');

            try {
                const response = await fetch('/admin/backups/create', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('✅ Backup created successfully! ' + result.backup_name, 'success');
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showAlert('❌ Backup failed: ' + result.error, 'error');
                }
            } catch (error) {
                showAlert('❌ Error: ' + error.message, 'error');
            } finally {
                btn.disabled = false;
                document.getElementById('loadingIndicator').style.display = 'none';
            }
        });

        // Delete backup
        async function deleteBackup(filename) {
            if (!confirm('Delete backup "' + filename + '"? This cannot be undone.')) {
                return;
            }

            try {
                const response = await fetch('/admin/backups/delete/' + encodeURIComponent(filename), {
                    method: 'DELETE'
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('✅ Backup deleted successfully', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('❌ Delete failed: ' + result.error, 'error');
                }
            } catch (error) {
                showAlert('❌ Error: ' + error.message, 'error');
            }
        }

        // Refresh page
        document.getElementById('refreshBtn')?.addEventListener('click', function() {
            location.reload();
        });

        // Show alert
        function showAlert(message, type) {
            const container = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.className = 'alert alert-' + type;
            alert.textContent = message;
            container.innerHTML = '';
            container.appendChild(alert);

            if (type === 'success' || type === 'info') {
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
     * Format bytes helper
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
