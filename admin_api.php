<?php
require_once __DIR__ . '/config.php';
jsonHeader();

$method = $_SERVER['REQUEST_METHOD'];
$session = validateSession();

if (!$session || $session['users']['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required']);
    exit;
}

if ($method === 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'stats') {
        getStats();
    } elseif ($action === 'transactions') {
        getTransactions();
    } elseif ($action === 'alerts') {
        getAlerts();
    } elseif ($action === 'coin-transactions') {
        getCoinTransactions();
    } elseif ($action === 'all-users') {
        getAllUsers();
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} elseif ($method === 'POST' && isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'mark-alert-read') {
        markAlertRead();
    } elseif ($action === 'mark-all-alerts-read') {
        markAllAlertsRead();
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function getStats() {
    $today = gmdate('Y-m-d');
    $startToday = $today . 'T00:00:00Z';
    $endToday = $today . 'T23:59:59Z';

    // Get today's print jobs
    $todayJobsResult = supabaseRequest('GET', '/print_jobs', null, [
        'created_at' => 'gte.' . $startToday,
        'and' => '(created_at.lte.' . $endToday . ')',
        'select' => 'id,total_price,status,paid'
    ]);

    $totalJobs = 0;
    $totalRevenue = 0;

    if (!$todayJobsResult['error'] && !empty($todayJobsResult['data'])) {
        foreach ($todayJobsResult['data'] as $job) {
            $totalJobs++;
            if (!empty($job['paid'])) {
                $totalRevenue += (float)$job['total_price'];
            }
        }
    }

    // Get all-time stats
    $allJobsResult = supabaseRequest('GET', '/print_jobs', null, [
        'select' => 'id,total_price,status,paid'
    ]);

    $allTimeJobs = 0;
    $allTimeRevenue = 0;
    $pendingJobs = 0;
    $printingJobs = 0;

    if (!$allJobsResult['error'] && !empty($allJobsResult['data'])) {
        foreach ($allJobsResult['data'] as $job) {
            $allTimeJobs++;
            if ($job['paid']) {
                $allTimeRevenue += (float)$job['total_price'];
            }
            if ($job['status'] === 'pending') $pendingJobs++;
            if ($job['status'] === 'printing') $printingJobs++;
        }
    }

    // Get printer status
    $printerResult = supabaseRequest('GET', '/printer_status', null, [
        'select' => '*',
        'order' => 'last_checked.desc',
        'limit' => 1
    ]);

    $printer = !$printerResult['error'] && !empty($printerResult['data'])
        ? $printerResult['data'][0]
        : ['paper_level' => 100, 'toner_black' => 100, 'toner_cyan' => 100, 'toner_magenta' => 100, 'toner_yellow' => 100, 'status' => 'online'];

    // Get active users count
    $usersResult = supabaseRequest('GET', '/users', null, [
        'select' => 'id',
        'role' => 'eq.customer'
    ]);
    $totalCustomers = !$usersResult['error'] ? count($usersResult['data']) : 0;

    // Get unread alerts count
    $alertsResult = supabaseRequest('GET', '/alerts', null, [
        'is_read' => 'eq.false',
        'select' => 'id'
    ]);
    $unreadAlerts = !$alertsResult['error'] ? count($alertsResult['data']) : 0;

    echo json_encode([
        'success' => true,
        'stats' => [
            'today_jobs' => $totalJobs,
            'today_revenue' => round($totalRevenue, 2),
            'all_time_jobs' => $allTimeJobs,
            'all_time_revenue' => round($allTimeRevenue, 2),
            'pending_jobs' => $pendingJobs,
            'printing_jobs' => $printingJobs,
            'total_customers' => $totalCustomers,
            'unread_alerts' => $unreadAlerts
        ],
        'printer' => $printer
    ]);
}

function getTransactions() {
    $limit = (int)($_GET['limit'] ?? 20);
    if ($limit > 100) $limit = 100;

    $params = [
        'select' => '*,users(name,email)',
        'order' => 'created_at.desc',
        'limit' => $limit
    ];

    $date = $_GET['date'] ?? '';
    if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $params['created_at'] = 'gte.' . $date . 'T00:00:00Z';
        $params['and'] = '(created_at.lte.' . $date . 'T23:59:59Z)';
    }

    $result = supabaseRequest('GET', '/print_jobs', null, $params);

    $transactions = [];
    if (!$result['error'] && !empty($result['data'])) {
        foreach ($result['data'] as $job) {
            $transactions[] = [
                'id' => $job['id'],
                'job_id' => $job['job_id'],
                'customer_name' => $job['users']['name'] ?? 'Guest',
                'file_name' => $job['file_name'],
                'pages' => $job['pages'],
                'total_price' => $job['total_price'],
                'status' => $job['status'],
                'paid' => $job['paid'],
                'created_at' => $job['created_at']
            ];
        }
    }

    echo json_encode(['success' => true, 'transactions' => $transactions]);
}

function getAlerts() {
    $params = [
        'select' => '*',
        'order' => 'created_at.desc',
        'limit' => 50
    ];

    $date = $_GET['date'] ?? '';
    if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $params['created_at'] = 'gte.' . $date . 'T00:00:00Z';
        $params['and'] = '(created_at.lte.' . $date . 'T23:59:59Z)';
    }

    $result = supabaseRequest('GET', '/alerts', null, $params);

    echo json_encode([
        'success' => true,
        'alerts' => $result['error'] ? [] : $result['data']
    ]);
}

function getCoinTransactions() {
    $limit = (int)($_GET['limit'] ?? 20);
    if ($limit > 100) $limit = 100;

    $params = [
        'select' => '*,users(name,email)',
        'order' => 'created_at.desc',
        'limit' => $limit
    ];

    $date = $_GET['date'] ?? '';
    if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $params['created_at'] = 'gte.' . $date . 'T00:00:00Z';
        $params['and'] = '(created_at.lte.' . $date . 'T23:59:59Z)';
    }

    $result = supabaseRequest('GET', '/coin_transactions', null, $params);

    echo json_encode([
        'success' => true,
        'transactions' => $result['error'] ? [] : $result['data']
    ]);
}

function getAllUsers() {
    $result = supabaseRequest('GET', '/users', null, [
        'select' => 'id,name,email,role,coins,created_at',
        'order' => 'created_at.desc'
    ]);

    echo json_encode([
        'success' => true,
        'users' => $result['error'] ? [] : $result['data']
    ]);
}

function markAlertRead() {
    $input = json_decode(file_get_contents('php://input'), true);
    $alertId = $input['alert_id'] ?? '';

    if (empty($alertId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Alert ID required']);
        return;
    }

    supabaseRequest('PATCH', '/alerts?id=eq.' . $alertId, ['is_read' => true]);

    echo json_encode(['success' => true]);
}

function markAllAlertsRead() {
    // Get all unread alerts
    $result = supabaseRequest('GET', '/alerts', null, [
        'is_read' => 'eq.false',
        'select' => 'id'
    ]);

    if (!$result['error'] && !empty($result['data'])) {
        foreach ($result['data'] as $alert) {
            supabaseRequest('PATCH', '/alerts?id=eq.' . $alert['id'], ['is_read' => true]);
        }
    }

    echo json_encode(['success' => true, 'count' => count($result['data'] ?? [])]);
}
