<?php

namespace App\Controllers\Admin;

use App\Services\AuthService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Admin Authentication Controller
 *
 * Handles admin login, 2FA, and logout
 *
 * @package LicenseServer\Controllers\Admin
 */
class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    /**
     * Show login form
     *
     * GET /admin/login
     *
     * @param Request $request
     * @return Response
     */
    public function showLogin(Request $request): Response
    {
        // If already logged in, redirect to dashboard
        if ($this->auth->isAuthenticated()) {
            return new RedirectResponse('/admin/dashboard');
        }

        $html = $this->renderLoginPage();
        return new Response($html);
    }

    /**
     * Process login
     *
     * POST /admin/login
     *
     * @param Request $request
     * @return Response
     */
    public function login(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            // Form submission
            $data = [
                'email' => $request->request->get('email'),
                'password' => $request->request->get('password')
            ];
        }

        if (empty($data['email']) || empty($data['password'])) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Email and password are required'
            ], 400);
        }

        $result = $this->auth->login($data['email'], $data['password']);

        if (!$result['success']) {
            return new JsonResponse($result, 401);
        }

        // Check if 2FA is required
        if ($result['requires_2fa']) {
            return new JsonResponse([
                'success' => true,
                'requires_2fa' => true,
                'user_id' => $result['user_id']
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => '/admin/dashboard'
        ]);
    }

    /**
     * Verify 2FA code
     *
     * POST /admin/verify-2fa
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function verify2FA(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['user_id']) || empty($data['code'])) {
            return new JsonResponse([
                'success' => false,
                'error' => 'User ID and 2FA code are required'
            ], 400);
        }

        $result = $this->auth->verify2FA($data['user_id'], $data['code']);

        if (!$result['success']) {
            return new JsonResponse($result, 401);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Authentication successful',
            'redirect' => '/admin/dashboard'
        ]);
    }

    /**
     * Logout
     *
     * POST /admin/logout
     *
     * @param Request $request
     * @return Response
     */
    public function logout(Request $request): Response
    {
        $this->auth->logout();

        return new RedirectResponse('/admin/login');
    }

    /**
     * Render login page HTML
     *
     * @return string
     */
    private function renderLoginPage(): string
    {
        $appName = env('APP_NAME', 'License Server');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - {$appName}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 420px;
            padding: 40px;
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo h1 {
            color: #667eea;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .logo p {
            color: #666;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        input:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }

        .alert-success {
            background: #efe;
            color: #3c3;
            border: 1px solid #cfc;
        }

        #twoFactorForm {
            display: none;
        }

        .back-link {
            display: none;
            text-align: center;
            margin-top: 15px;
            color: #667eea;
            cursor: pointer;
            font-size: 14px;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #fff;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin-left: 8px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <h1>🔐 {$appName}</h1>
            <p>Admin Panel</p>
        </div>

        <div id="alert" class="alert" style="display: none;"></div>

        <!-- Login Form -->
        <form id="loginForm">
            <div class="form-group">
                <label for="email">Email or Username</label>
                <input type="text" id="email" name="email" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit" class="btn" id="loginBtn">Sign In</button>
        </form>

        <!-- 2FA Form -->
        <form id="twoFactorForm">
            <div class="form-group">
                <label for="twoFactorCode">Two-Factor Authentication Code</label>
                <input type="text" id="twoFactorCode" name="code" required placeholder="000000" maxlength="8" autocomplete="off">
                <p style="font-size: 12px; color: #666; margin-top: 8px;">Enter the 6-digit code from your authenticator app or a recovery code</p>
            </div>

            <button type="submit" class="btn" id="verify2FABtn">Verify</button>
            <div class="back-link" id="backToLogin">← Back to login</div>
        </form>
    </div>

    <script>
        const loginForm = document.getElementById('loginForm');
        const twoFactorForm = document.getElementById('twoFactorForm');
        const alert = document.getElementById('alert');
        const backToLogin = document.getElementById('backToLogin');
        let currentUserId = null;

        function showAlert(message, type = 'error') {
            alert.className = 'alert alert-' + type;
            alert.textContent = message;
            alert.style.display = 'block';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 5000);
        }

        function showLoading(btn, show) {
            if (show) {
                btn.disabled = true;
                btn.innerHTML += '<span class="loading"></span>';
            } else {
                btn.disabled = false;
                btn.innerHTML = btn.innerHTML.replace(/<span class="loading"><\/span>/, '');
            }
        }

        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const loginBtn = document.getElementById('loginBtn');
            showLoading(loginBtn, true);

            const formData = {
                email: document.getElementById('email').value,
                password: document.getElementById('password').value
            };

            try {
                const response = await fetch('/admin/login', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(formData)
                });

                const result = await response.json();

                if (result.success) {
                    if (result.requires_2fa) {
                        currentUserId = result.user_id;
                        loginForm.style.display = 'none';
                        twoFactorForm.style.display = 'block';
                        backToLogin.style.display = 'block';
                        document.getElementById('twoFactorCode').focus();
                    } else {
                        showAlert('Login successful! Redirecting...', 'success');
                        setTimeout(() => {
                            window.location.href = result.redirect || '/admin/dashboard';
                        }, 1000);
                    }
                } else {
                    showAlert(result.error || 'Login failed');
                }
            } catch (error) {
                showAlert('An error occurred. Please try again.');
            } finally {
                showLoading(loginBtn, false);
            }
        });

        twoFactorForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const verifyBtn = document.getElementById('verify2FABtn');
            showLoading(verifyBtn, true);

            const formData = {
                user_id: currentUserId,
                code: document.getElementById('twoFactorCode').value
            };

            try {
                const response = await fetch('/admin/verify-2fa', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(formData)
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('Authentication successful! Redirecting...', 'success');
                    setTimeout(() => {
                        window.location.href = result.redirect || '/admin/dashboard';
                    }, 1000);
                } else {
                    showAlert(result.error || 'Invalid 2FA code');
                    document.getElementById('twoFactorCode').value = '';
                }
            } catch (error) {
                showAlert('An error occurred. Please try again.');
            } finally {
                showLoading(verifyBtn, false);
            }
        });

        backToLogin.addEventListener('click', () => {
            twoFactorForm.style.display = 'none';
            backToLogin.style.display = 'none';
            loginForm.style.display = 'block';
            document.getElementById('password').value = '';
            document.getElementById('twoFactorCode').value = '';
            currentUserId = null;
        });
    </script>
</body>
</html>
HTML;
    }
}
