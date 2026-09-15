<?php
require_once __DIR__ . '/config.php';
jsonHeader();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'status') {
        getPrinterStatus();
    } elseif ($action === 'pending-jobs') {
        getPendingJobs();
    } elseif ($action === 'ready-jobs') {
        getReadyJobs();
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} elseif ($method === 'POST' && isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'update-status') {
        updatePrinterStatus();
    } elseif ($action === 'update-job') {
        updateJobStatus();
    } elseif ($action === 'simulate-jam') {
        simulateJam();
    } elseif ($action === 'clear-jam') {
        clearJam();
    } elseif ($action === 'set-levels') {
        setSupplyLevels();
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function getPrinterStatus() {
    $result = supabaseRequest('GET', '/printer_status', null, [
        'select' => '*',
        'order' => 'last_checked.desc',
        'limit' => 1
    ]);

    if ($result['error'] || empty($result['data'])) {
        // Return default status if no record
        echo json_encode([
            'success' => true,
            'printer' => [
                'paper_level' => 100,
                'toner_black' => 100,
                'toner_cyan' => 100,
                'toner_magenta' => 100,
                'toner_yellow' => 100,
                'status' => 'online',
                'jam_type' => null,
                'last_checked' => date('c')
            ]
        ]);
        return;
    }

    echo json_encode(['success' => true, 'printer' => $result['data'][0]]);
}

function updatePrinterStatus() {
    $session = validateSession();
    if (!$session || $session['users']['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $updateData = [];
    if (isset($input['status'])) $updateData['status'] = $input['status'];
    if (isset($input['jam_type'])) $updateData['jam_type'] = $input['jam_type'];
    $updateData['last_checked'] = date('c');

    if (empty($updateData)) {
        http_response_code(400);
        echo json_encode(['error' => 'No data to update']);
        return;
    }

    // Get current printer status ID
    $currentResult = supabaseRequest('GET', '/printer_status', null, [
        'select' => 'id',
        'order' => 'last_checked.desc',
        'limit' => 1
    ]);

    if ($currentResult['error'] || empty($currentResult['data'])) {
        // Insert new
        $insertData = array_merge([
            'paper_level' => 100,
            'toner_black' => 100,
            'toner_cyan' => 100,
            'toner_magenta' => 100,
            'toner_yellow' => 100
        ], $updateData);
        supabaseRequest('POST', '/printer_status', $insertData);
    } else {
        $printerId = $currentResult['data'][0]['id'];
        supabaseRequest('PATCH', '/printer_status?id=eq.' . $printerId, $updateData);
    }

    echo json_encode(['success' => true]);
}

function simulateJam() {
    $session = validateSession();
    if (!$session || $session['users']['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $jamType = $input['jam_type'] ?? 'minor';

    if (!in_array($jamType, ['minor', 'major'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Jam type must be minor or major']);
        return;
    }

    $status = ($jamType === 'minor') ? 'jam_minor' : 'jam_major';

    $currentResult = supabaseRequest('GET', '/printer_status', null, [
        'select' => 'id',
        'order' => 'last_checked.desc',
        'limit' => 1
    ]);

    $updateData = [
        'status' => $status,
        'jam_type' => $jamType,
        'last_checked' => date('c')
    ];

    if ($currentResult['error'] || empty($currentResult['data'])) {
        supabaseRequest('POST', '/printer_status', array_merge([
            'paper_level' => 100,
            'toner_black' => 100,
            'toner_cyan' => 100,
            'toner_magenta' => 100,
            'toner_yellow' => 100
        ], $updateData));
    } else {
        supabaseRequest('PATCH', '/printer_status?id=eq.' . $currentResult['data'][0]['id'], $updateData);
    }

    // Create alert
    $msg = $jamType === 'minor' ? 'Minor paper jam detected - auto reprint queued' : 'Major paper jam detected - refund being processed';
    supabaseRequest('POST', '/alerts', [
        'type' => 'paper_jam',
        'message' => $msg,
        'severity' => $jamType === 'major' ? 'critical' : 'warning',
        'is_read' => false
    ]);

    // If major jam, refund all printing jobs
    if ($jamType === 'major') {
        $jobsResult = supabaseRequest('GET', '/print_jobs', null, [
            'status' => 'eq.printing',
            'select' => 'id,job_id,user_id,total_price'
        ]);

        if (!$jobsResult['error'] && !empty($jobsResult['data'])) {
            foreach ($jobsResult['data'] as $job) {
                supabaseRequest('PATCH', '/print_jobs?id=eq.' . $job['id'], [
                    'status' => 'refund_issued',
                    'updated_at' => date('c')
                ]);

                // Refund coins if spent
                if ($job['user_id']) {
                    $userResult = supabaseRequest('GET', '/users', null, [
                        'id' => 'eq.' . $job['user_id'],
                        'select' => 'coins'
                    ]);
                    if (!$userResult['error'] && !empty($userResult['data'])) {
                        $currentCoins = $userResult['data'][0]['coins'];
                        // Estimate coins spent (full price worth)
                        $coinsRefund = floor($job['total_price'] * 100 / 100);
                        supabaseRequest('PATCH', '/users?id=eq.' . $job['user_id'], [
                            'coins' => $currentCoins + $coinsRefund
                        ]);
                    }
                }
            }
        }
    }

    echo json_encode(['success' => true, 'jam_type' => $jamType, 'message' => $msg]);
}

function clearJam() {
    $session = validateSession();
    if (!$session || $session['users']['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        return;
    }

    $currentResult = supabaseRequest('GET', '/printer_status', null, [
        'select' => 'id',
        'order' => 'last_checked.desc',
        'limit' => 1
    ]);

    if (!$currentResult['error'] && !empty($currentResult['data'])) {
        supabaseRequest('PATCH', '/printer_status?id=eq.' . $currentResult['data'][0]['id'], [
            'status' => 'online',
            'jam_type' => null,
            'last_checked' => date('c')
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Jam cleared, printer back online']);
}

function setSupplyLevels() {
    $session = validateSession();
    if (!$session || $session['users']['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $updateData = ['last_checked' => date('c')];
    if (isset($input['paper_level'])) $updateData['paper_level'] = max(0, min(100, (int)$input['paper_level']));
    if (isset($input['toner_black'])) $updateData['toner_black'] = max(0, min(100, (int)$input['toner_black']));
    if (isset($input['toner_cyan'])) $updateData['toner_cyan'] = max(0, min(100, (int)$input['toner_cyan']));
    if (isset($input['toner_magenta'])) $updateData['toner_magenta'] = max(0, min(100, (int)$input['toner_magenta']));
    if (isset($input['toner_yellow'])) $updateData['toner_yellow'] = max(0, min(100, (int)$input['toner_yellow']));

    $currentResult = supabaseRequest('GET', '/printer_status', null, [
        'select' => 'id',
        'order' => 'last_checked.desc',
        'limit' => 1
    ]);

    if ($currentResult['error'] || empty($currentResult['data'])) {
        supabaseRequest('POST', '/printer_status', array_merge([
            'paper_level' => 100,
            'toner_black' => 100,
            'toner_cyan' => 100,
            'toner_magenta' => 100,
            'toner_yellow' => 100
        ], $updateData));
    } else {
        supabaseRequest('PATCH', '/printer_status?id=eq.' . $currentResult['data'][0]['id'], $updateData);
    }

    // Check for low levels and create alerts
    if (isset($updateData['paper_level']) && $updateData['paper_level'] < 20) {
        supabaseRequest('POST', '/alerts', [
            'type' => 'paper_low',
            'message' => 'Paper level is low (' . $updateData['paper_level'] . '%)',
            'severity' => 'warning',
            'is_read' => false
        ]);
    }

    foreach (['toner_black', 'toner_cyan', 'toner_magenta', 'toner_yellow'] as $toner) {
        if (isset($updateData[$toner]) && $updateData[$toner] < 20) {
            supabaseRequest('POST', '/alerts', [
                'type' => 'toner_low',
                'message' => ucfirst(str_replace('_', ' ', $toner)) . ' toner is low (' . $updateData[$toner] . '%)',
                'severity' => 'warning',
                'is_read' => false
            ]);
        }
    }

    echo json_encode(['success' => true]);
}

function getPendingJobs() {
    $session = validateSession();
    if (!$session || $session['users']['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        return;
    }

    $result = supabaseRequest('GET', '/print_jobs', null, [
        'status' => 'eq.pending',
        'select' => '*',
        'order' => 'created_at.asc'
    ]);

    echo json_encode([
        'success' => true,
        'jobs' => $result['error'] ? [] : $result['data']
    ]);
}

function getReadyJobs() {
    $session = validateSession();
    if (!$session || $session['users']['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        return;
    }

    $result = supabaseRequest('GET', '/print_jobs', null, [
        'status' => 'eq.ready_to_print',
        'select' => '*',
        'order' => 'created_at.asc'
    ]);

    echo json_encode([
        'success' => true,
        'jobs' => $result['error'] ? [] : $result['data']
    ]);
}

function updateJobStatus() {
    $session = validateSession();
    if (!$session || $session['users']['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $jobId = $input['job_id'] ?? '';
    $newStatus = $input['status'] ?? '';

    if (empty($jobId) || empty($newStatus)) {
        http_response_code(400);
        echo json_encode(['error' => 'Job ID and status required']);
        return;
    }

    $allowedStatuses = ['pending', 'ready_to_print', 'printing', 'completed', 'refund_issued'];
    if (!in_array($newStatus, $allowedStatuses)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid status']);
        return;
    }

    $result = supabaseRequest('PATCH', '/print_jobs?job_id=eq.' . $jobId, [
        'status' => $newStatus,
        'updated_at' => date('c')
    ]);

    if ($result['error']) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update job status']);
        return;
    }

    // If completed, create alert and cleanup file
    if ($newStatus === 'completed') {
        supabaseRequest('POST', '/alerts', [
            'type' => 'print_complete',
            'message' => 'Print job ' . $jobId . ' completed successfully',
            'severity' => 'info',
            'is_read' => false
        ]);

        // Cleanup file
        $jobResult = supabaseRequest('GET', '/print_jobs', null, [
            'job_id' => 'eq.' . $jobId,
            'select' => 'file_path'
        ]);
        if (!$jobResult['error'] && !empty($jobResult['data'])) {
            $filePath = $jobResult['data'][0]['file_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    echo json_encode(['success' => true, 'message' => 'Job status updated to ' . $newStatus]);
}
