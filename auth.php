<?php
require_once __DIR__ . '/config.php';
jsonHeader();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST' && isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'login') {
        handleLogin();
    } elseif ($action === 'register') {
        handleRegister();
    } elseif ($action === 'verify-email') {
        handleVerifyEmail();
    } elseif ($action === 'resend-code') {
        handleResendCode();
    } elseif ($action === 'logout') {
        handleLogout();
    } elseif ($action === 'verify-session') {
        handleVerifySession();
    } elseif ($action === 'save-face') {
        handleSaveFace();
    } elseif ($action === 'verify-pin') {
        handleVerifyPin();
    } elseif ($action === 'face-login') {
        handleFaceLogin();
    } elseif ($action === 'set-pin') {
        handleSetPin();
    } elseif ($action === 'change-password') {
        handleChangePassword();
    } elseif ($action === 'update-profile') {
        handleUpdateProfile();
    } elseif ($action === 'forgot-password') {
        handleForgotPassword();
    } elseif ($action === 'reset-password') {
        handleResetPassword();
    } elseif ($action === 'delete-account') {
        handleDeleteAccount();
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function handleLogin() {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email and password required']);
        return;
    }

    $result = supabaseRequest('GET', '/users', null, [
        'email' => 'eq.' . $email,
        'select' => '*'
    ]);

    if ($result['error'] || empty($result['data'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password']);
        return;
    }

    $user = $result['data'][0];

    if (!password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password']);
        return;
    }

    // Create session
    $token = generateSessionToken();
    $expiresAt = date('c', time() + SESSION_DURATION);

    $sessionResult = supabaseRequest('POST', '/sessions', [
        'session_token' => $token,
        'user_id' => $user['id'],
        'expires_at' => $expiresAt
    ]);

    if ($sessionResult['error']) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create session']);
        return;
    }

    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'coins' => $user['coins'],
            'has_face' => !empty($user['face_descriptor']),
            'username' => $user['username'] ?? null,
            'nickname' => $user['nickname'] ?? null,
            'account_number' => $user['account_number'] ?? null,
            'profile_picture' => $user['profile_picture'] ?? null,
            'google_id' => $user['google_id'] ?? null
        ],
        'session_token' => $token,
        'expires_at' => $expiresAt
    ]);
}

function handleRegister() {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $pin_code = $input['pin_code'] ?? '';
    $phone = trim($input['phone'] ?? '');

    if (empty($name) || empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Name, email, and password required']);
        return;
    }

    if (!isValidGmail($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Only Gmail addresses are accepted']);
        return;
    }

    if (!isValidMalaysianPhone($phone)) {
        http_response_code(400);
        echo json_encode(['error' => 'Enter a valid Malaysian mobile number (e.g. 010-1234567)']);
        return;
    }

    if (!empty($pin_code) && !preg_match('/^\d{4}$/', $pin_code)) {
        http_response_code(400);
        echo json_encode(['error' => 'PIN must be 4 digits']);
        return;
    }

    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 6 characters']);
        return;
    }

    // Check if email exists
    $checkResult = supabaseRequest('GET', '/users', null, [
        'email' => 'eq.' . $email,
        'select' => 'id'
    ]);

    if (!$checkResult['error'] && !empty($checkResult['data'])) {
        http_response_code(409);
        echo json_encode(['error' => 'Email already registered']);
        return;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $code = generateVerificationCode();
    $expiresAt = date('c', time() + VERIFICATION_CODE_TTL);

    $insertData = [
        'name' => $name,
        'email' => $email,
        'password_hash' => $hash,
        'role' => 'customer',
        'coins' => 0,
        'phone' => normalizePhone($phone),
        'email_verified' => false,
        'verification_code' => $code,
        'verification_expires_at' => $expiresAt
    ];
    if (!empty($pin_code)) {
        $insertData['pin_code'] = $pin_code;
    }

    $insertResult = supabaseRequest('POST', '/users', $insertData);

    if ($insertResult['error']) {
        http_response_code(500);
        echo json_encode(['error' => 'Registration failed: ' . ($insertResult['message'] ?? 'Unknown error')]);
        return;
    }

    $user = $insertResult['data'][0];

    $sent = sendVerificationEmail($email, $code, $name);
    if (!$sent) {
        supabaseRequest('DELETE', '/users', null, ['id' => 'eq.' . $user['id']]);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to send verification email. Check SMTP_USER / SMTP_PASS (Gmail App Password) are configured.']);
        return;
    }

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'verification_required' => true,
        'message' => 'Verification code sent to ' . $email,
        'email' => $email
    ]);
}

function handleVerifyEmail() {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim($input['email'] ?? ''));
    $code = trim($input['code'] ?? '');

    if (empty($email) || empty($code)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email and verification code required']);
        return;
    }

    if (!preg_match('/^\d{' . VERIFICATION_CODE_LENGTH . '}$/', $code)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid verification code format']);
        return;
    }

    $userResult = supabaseRequest('GET', '/users', null, [
        'email' => 'eq.' . $email,
        'select' => '*'
    ]);

    if ($userResult['error'] || empty($userResult['data'])) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        return;
    }

    $user = $userResult['data'][0];

    if ($user['email_verified']) {
        http_response_code(400);
        echo json_encode(['error' => 'Email already verified']);
        return;
    }

    if (empty($user['verification_code']) || $user['verification_code'] !== $code) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid verification code']);
        return;
    }

    $expiresAt = strtotime($user['verification_expires_at'] ?? '0');
    if ($expiresAt < time()) {
        http_response_code(400);
        echo json_encode(['error' => 'Verification code expired. Resend a new code.']);
        return;
    }

    $patchResult = supabaseRequest('PATCH', '/users?id=eq.' . $user['id'], [
        'email_verified' => true,
        'verification_code' => null,
        'verification_expires_at' => null
    ]);

    if ($patchResult['error']) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to verify email']);
        return;
    }

    // Award bonus coins transaction
    supabaseRequest('POST', '/coin_transactions', [
        'user_id' => $user['id'],
        'type' => 'bonus',
        'amount' => 10,
        'description' => 'Welcome bonus - first registration',
        'balance_after' => 10
    ]);
    supabaseRequest('PATCH', '/users?id=eq.' . $user['id'], ['coins' => 10]);

    // Create session
    $token = generateSessionToken();
    $sessionExpiresAt = date('c', time() + SESSION_DURATION);

    supabaseRequest('POST', '/sessions', [
        'session_token' => $token,
        'user_id' => $user['id'],
        'expires_at' => $sessionExpiresAt
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Registration successful! You earned 10 bonus coins.',
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'coins' => 10,
            'has_face' => false
        ],
        'session_token' => $token,
        'expires_at' => $sessionExpiresAt
    ]);
}

function handleResendCode() {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim($input['email'] ?? ''));

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email required']);
        return;
    }

    $userResult = supabaseRequest('GET', '/users', null, [
        'email' => 'eq.' . $email,
        'select' => '*'
    ]);

    if ($userResult['error'] || empty($userResult['data'])) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        return;
    }

    $user = $userResult['data'][0];

    if ($user['email_verified']) {
        http_response_code(400);
        echo json_encode(['error' => 'Email already verified']);
        return;
    }

    $code = generateVerificationCode();
    $expiresAt = date('c', time() + VERIFICATION_CODE_TTL);

    supabaseRequest('PATCH', '/users?id=eq.' . $user['id'], [
        'verification_code' => $code,
        'verification_expires_at' => $expiresAt
    ]);

    $sent = sendVerificationEmail($email, $code, $user['name']);

    if (!$sent) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to send verification email. Check SMTP_USER / SMTP_PASS (Gmail App Password) are configured.']);
        return;
    }

    echo json_encode(['success' => true, 'message' => 'Verification code sent to ' . $email]);
}

function handleLogout() {
    $headers = getallheaders();
    $token = $headers['X-Session-Token'] ?? '';

    if (!empty($token)) {
        supabaseRequest('DELETE', '/sessions', null, ['session_token' => 'eq.' . $token]);
    }

    echo json_encode(['success' => true]);
}

function handleVerifySession() {
    $session = validateSession();
    if (!$session) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired session']);
        return;
    }

    $user = $session['users'];
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'coins' => $user['coins'],
            'has_face' => !empty($user['face_descriptor']),
            'username' => $user['username'] ?? null,
            'nickname' => $user['nickname'] ?? null,
            'account_number' => $user['account_number'] ?? null,
            'profile_picture' => $user['profile_picture'] ?? null,
            'google_id' => $user['google_id'] ?? null
        ]
    ]);
}

function handleSaveFace() {
    $session = validateSession();
    if (!$session) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $descriptor = $input['descriptor'] ?? [];
    $faceImages = $input['face_images'] ?? [];

    if (empty($descriptor)) {
        http_response_code(400);
        echo json_encode(['error' => 'Face descriptor required']);
        return;
    }

    $patchData = ['face_descriptor' => $descriptor];
    if (!empty($faceImages) && is_array($faceImages)) {
        $patchData['face_images'] = $faceImages;
    }

    $result = supabaseRequest('PATCH', '/users?id=eq.' . $session['user_id'], $patchData);

    if ($result['error'] && array_key_exists('face_images', $patchData)) {
        unset($patchData['face_images']);
        $result = supabaseRequest('PATCH', '/users?id=eq.' . $session['user_id'], $patchData);
    }

    if ($result['error']) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save face data']);
        return;
    }

    echo json_encode(['success' => true, 'message' => 'Face data saved successfully']);
}

function handleVerifyPin() {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $pin = $input['pin'] ?? '';

    if (empty($email) || empty($pin)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email and PIN required']);
        return;
    }

    if (!preg_match('/^\d{4}$/', $pin)) {
        http_response_code(400);
        echo json_encode(['error' => 'PIN must be 4 digits']);
        return;
    }

    $result = supabaseRequest('GET', '/users', null, [
        'email' => 'eq.' . $email,
        'select' => '*'
    ]);

    if ($result['error'] || empty($result['data'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or PIN']);
        return;
    }

    $user = $result['data'][0];

    if (empty($user['pin_code']) || $user['pin_code'] !== $pin) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or PIN']);
        return;
    }

    $token = generateSessionToken();
    $expiresAt = date('c', time() + SESSION_DURATION);

    supabaseRequest('POST', '/sessions', [
        'session_token' => $token,
        'user_id' => $user['id'],
        'expires_at' => $expiresAt
    ]);

    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'coins' => $user['coins'],
            'has_face' => !empty($user['face_descriptor'])
        ],
        'session_token' => $token,
        'expires_at' => $expiresAt
    ]);
}

function handleFaceLogin() {
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = $input['user_id'] ?? '';

    if (empty($userId)) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID required']);
        return;
    }

    $result = supabaseRequest('GET', '/users', null, [
        'id' => 'eq.' . $userId,
        'select' => '*'
    ]);

    if ($result['error'] || empty($result['data'])) {
        http_response_code(401);
        echo json_encode(['error' => 'User not found']);
        return;
    }

    $user = $result['data'][0];

    $token = generateSessionToken();
    $expiresAt = date('c', time() + SESSION_DURATION);

    supabaseRequest('POST', '/sessions', [
        'session_token' => $token,
        'user_id' => $user['id'],
        'expires_at' => $expiresAt
    ]);

    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'coins' => $user['coins'],
            'has_face' => !empty($user['face_descriptor'])
        ],
        'session_token' => $token,
        'expires_at' => $expiresAt
    ]);
}

function handleSetPin() {
    $session = validateSession();
    if (!$session) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $pin = $input['pin'] ?? '';

    if (!preg_match('/^\d{4}$/', $pin)) {
        http_response_code(400);
        echo json_encode(['error' => 'PIN must be 4 digits']);
        return;
    }

    $result = supabaseRequest('PATCH', '/users?id=eq.' . $session['user_id'], [
        'pin_code' => $pin
    ]);

    if ($result['error']) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save PIN']);
        return;
    }

    echo json_encode(['success' => true, 'message' => 'PIN saved successfully']);
}

function handleChangePassword() {
    $session = validateSession();
    if (!$session) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $currentPassword = $input['current_password'] ?? '';
    $newPassword = $input['new_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword)) {
        http_response_code(400);
        echo json_encode(['error' => 'Current and new password required']);
        return;
    }

    if (strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'New password must be at least 6 characters']);
        return;
    }

    $userResult = supabaseRequest('GET', '/users', null, [
        'id' => 'eq.' . $session['user_id'],
        'select' => '*'
    ]);

    if ($userResult['error'] || empty($userResult['data'])) {
        http_response_code(401);
        echo json_encode(['error' => 'User not found']);
        return;
    }

    $user = $userResult['data'][0];

    if (!password_verify($currentPassword, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Current password is incorrect']);
        return;
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    $result = supabaseRequest('PATCH', '/users?id=eq.' . $session['user_id'], [
        'password_hash' => $hash
    ]);

    if ($result['error']) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to change password']);
        return;
    }

    echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
}

function handleUpdateProfile() {
    $session = validateSession();
    if (!$session) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request']);
        return;
    }

    $patchData = [];
    if (array_key_exists('username', $input)) {
        $patchData['username'] = $input['username'] === null ? null : trim($input['username']);
    }
    if (array_key_exists('nickname', $input)) {
        $patchData['nickname'] = $input['nickname'] === null ? null : trim($input['nickname']);
    }
    if (array_key_exists('account_number', $input)) {
        $patchData['account_number'] = $input['account_number'] === null ? null : trim($input['account_number']);
    }
    if (array_key_exists('profile_picture', $input)) {
        $patchData['profile_picture'] = $input['profile_picture'] === null ? null : $input['profile_picture'];
    }
    if (array_key_exists('google_id', $input)) {
        $patchData['google_id'] = $input['google_id'] === null ? null : strtolower(trim($input['google_id']));
    }

    if (empty($patchData)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        return;
    }

    $result = supabaseRequest('PATCH', '/users?id=eq.' . $session['user_id'], $patchData);

    if ($result['error']) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update profile. If this persists, run the profile fields migration.']);
        return;
    }

    echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
}

function handleForgotPassword() {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim($input['email'] ?? ''));
    $site = rtrim(trim($input['site'] ?? ''), '/');

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email required']);
        return;
    }

    if (!isValidGmail($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Only Gmail addresses are accepted']);
        return;
    }

    if (empty($site) || !preg_match('#^https?://#', $site)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request origin']);
        return;
    }

    $userResult = supabaseRequest('GET', '/users', null, [
        'email' => 'eq.' . $email,
        'select' => '*'
    ]);

    if ($userResult['error'] || empty($userResult['data'])) {
        echo json_encode(['success' => true, 'message' => 'If an account exists, a reset link has been sent.']);
        return;
    }

    $user = $userResult['data'][0];

    $token = bin2hex(random_bytes(24));
    $expiresAt = date('c', time() + RESET_TOKEN_TTL);

    $patch = supabaseRequest('PATCH', '/users?id=eq.' . $user['id'], [
        'verification_code' => $token,
        'verification_expires_at' => $expiresAt
    ]);

    if ($patch['error']) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to send reset email. Please try again.']);
        return;
    }

    $resetUrl = $site . '/website/reset-password.html?token=' . $token;

    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;">'
        . '<h2 style="color:#0f172a;margin:0 0 8px;">Reset your password</h2>'
        . '<p style="color:#475569;font-size:14px;">Hi ' . htmlspecialchars($user['name']) . ',</p>'
        . '<p style="color:#475569;font-size:14px;line-height:1.6;">We received a request to reset your password. Click the button below to create a new one. This link expires in 30 minutes.</p>'
        . '<div style="text-align:center;margin:24px 0;">'
        . '<a href="' . htmlspecialchars($resetUrl) . '" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:12px 28px;border-radius:8px;">Reset Password</a>'
        . '</div>'
        . '<p style="color:#475569;font-size:13px;line-height:1.6;">If the button does not work, copy and paste this link into your browser:</p>'
        . '<p style="color:#2563eb;font-size:12px;word-break:break-all;">' . htmlspecialchars($resetUrl) . '</p>'
        . '<p style="color:#94a3b8;font-size:12px;margin-top:16px;">If you did not request this, you can safely ignore this email.</p>'
        . '</div>';

    $sent = smtpSend($email, APP_NAME . ' - Reset your password', $html);

    if (!$sent) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to send reset email. Check SMTP_USER / SMTP_PASS (Gmail App Password) are configured.']);
        return;
    }

    echo json_encode(['success' => true, 'message' => 'If an account exists, a reset link has been sent.']);
}

function handleResetPassword() {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = trim($input['token'] ?? '');
    $newPassword = $input['newPassword'] ?? '';

    if (empty($token) || empty($newPassword)) {
        http_response_code(400);
        echo json_encode(['error' => 'Reset link and new password required']);
        return;
    }

    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or expired reset link']);
        return;
    }

    if (strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'New password must be at least 6 characters']);
        return;
    }

    $userResult = supabaseRequest('GET', '/users', null, [
        'verification_code' => 'eq.' . $token,
        'select' => '*'
    ]);

    if ($userResult['error'] || empty($userResult['data'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or expired reset link']);
        return;
    }

    $user = $userResult['data'][0];

    $expiresAt = strtotime($user['verification_expires_at'] ?? '0');
    if ($expiresAt < time()) {
        http_response_code(400);
        echo json_encode(['error' => 'This reset link has expired. Request a new one.']);
        return;
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    $result = supabaseRequest('PATCH', '/users?id=eq.' . $user['id'], [
        'password_hash' => $hash,
        'email_verified' => true,
        'verification_code' => null,
        'verification_expires_at' => null
    ]);

    if ($result['error']) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to reset password. Please try again.']);
        return;
    }

    echo json_encode(['success' => true, 'message' => 'Password reset successful. You can now log in.']);
}

function handleDeleteAccount() {
    $session = validateSession();
    if (!$session) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    $userId = $session['user_id'];

    supabaseRequest('DELETE', '/sessions', null, ['user_id' => 'eq.' . $userId]);
    supabaseRequest('DELETE', '/payments', null, ['user_id' => 'eq.' . $userId]);
    supabaseRequest('DELETE', '/coin_transactions', null, ['user_id' => 'eq.' . $userId]);
    supabaseRequest('DELETE', '/print_jobs', null, ['user_id' => 'eq.' . $userId]);

    $result = supabaseRequest('DELETE', '/users', null, ['id' => 'eq.' . $userId]);

    if ($result['error']) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete account']);
        return;
    }

    $token = $session['session_token'] ?? '';
    if (!empty($token)) {
        supabaseRequest('DELETE', '/sessions', null, ['session_token' => 'eq.' . $token]);
    }

    echo json_encode(['success' => true, 'message' => 'Account deleted successfully']);
}
