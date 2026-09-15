<?php
require_once __DIR__ . '/config.php';
jsonHeader();

$method = $_SERVER['REQUEST_METHOD'];

$session = validateSession();
if (!$session) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = $session['user_id'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'balance') {
        getBalance($userId);
    } elseif ($action === 'history') {
        getHistory($userId);
    } elseif ($action === 'discount') {
        getDiscount($userId);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} elseif ($method === 'POST' && isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'redeem') {
        redeemCoins($userId);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function getBalance($userId) {
    $result = supabaseRequest('GET', '/users', null, [
        'id' => 'eq.' . $userId,
        'select' => 'coins'
    ]);

    if ($result['error'] || empty($result['data'])) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get balance']);
        return;
    }

    $coins = $result['data'][0]['coins'];
    $discountValue = round($coins * 0.01, 2); // RM 0.10 per 10 coins

    echo json_encode([
        'success' => true,
        'coins' => $coins,
        'discount_rm' => $discountValue,
        'can_redeem' => $coins >= 10
    ]);
}

function getHistory($userId) {
    $result = supabaseRequest('GET', '/coin_transactions', null, [
        'user_id' => 'eq.' . $userId,
        'select' => '*',
        'order' => 'created_at.desc',
        'limit' => 50
    ]);

    echo json_encode([
        'success' => true,
        'transactions' => $result['error'] ? [] : $result['data']
    ]);
}

function getDiscount($userId) {
    $result = supabaseRequest('GET', '/users', null, [
        'id' => 'eq.' . $userId,
        'select' => 'coins'
    ]);

    if ($result['error'] || empty($result['data'])) {
        echo json_encode(['success' => true, 'can_redeem' => false, 'coins' => 0, 'max_discount' => 0]);
        return;
    }

    $coins = $result['data'][0]['coins'];
    $maxDiscount = floor($coins / 10) * 0.1; // RM 0.10 per 10 coins

    echo json_encode([
        'success' => true,
        'can_redeem' => $coins >= 10,
        'coins' => $coins,
        'max_discount' => $maxDiscount
    ]);
}

function redeemCoins($userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    $jobId = $input['job_id'] ?? '';
    $redeemAmount = round((float)($input['amount'] ?? 0), 2); // in RM
    $coinsNeeded = (int)round($redeemAmount * 100); // 10 coins per RM 0.10

    if (empty($jobId) || $redeemAmount <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Job ID and redeem amount required']);
        return;
    }

    if ($coinsNeeded < 10 || $coinsNeeded % 10 !== 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Redeem amount must be in RM 0.10 steps (minimum 10 coins)']);
        return;
    }

    // Get user coins
    $userResult = supabaseRequest('GET', '/users', null, [
        'id' => 'eq.' . $userId,
        'select' => 'coins'
    ]);

    if ($userResult['error'] || empty($userResult['data'])) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get user data']);
        return;
    }

    $currentCoins = $userResult['data'][0]['coins'];

    if ($currentCoins < $coinsNeeded) {
        http_response_code(400);
        echo json_encode(['error' => 'Insufficient coins']);
        return;
    }

    // Get print job
    $jobResult = supabaseRequest('GET', '/print_jobs', null, [
        'job_id' => 'eq.' . $jobId,
        'select' => '*'
    ]);

    if ($jobResult['error'] || empty($jobResult['data'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Print job not found']);
        return;
    }

    $job = $jobResult['data'][0];
    $newPrice = max(0, $job['total_price'] - $redeemAmount);
    $newCoins = $currentCoins - $coinsNeeded;

    // Update job price
    supabaseRequest('PATCH', '/print_jobs?id=eq.' . $job['id'], [
        'total_price' => $newPrice,
        'updated_at' => date('c')
    ]);

    // Update user coins
    supabaseRequest('PATCH', '/users?id=eq.' . $userId, [
        'coins' => $newCoins
    ]);

    // Create coin transaction
    supabaseRequest('POST', '/coin_transactions', [
        'user_id' => $userId,
        'print_job_id' => $job['id'],
        'type' => 'spent',
        'amount' => $coinsNeeded,
        'description' => 'Redeemed RM ' . number_format($redeemAmount, 2) . ' discount for job ' . $jobId,
        'balance_after' => $newCoins
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Discount applied successfully',
        'new_price' => $newPrice,
        'coins_remaining' => $newCoins,
        'coins_spent' => $coinsNeeded
    ]);
}
