<?php
require_once __DIR__ . '/config.php';
jsonHeader();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'get-jobs') {
        $userId = $_GET['user_id'] ?? '';
        if (empty($userId)) { http_response_code(400); echo json_encode(['error' => 'User ID required']); exit; }
        $result = supabaseRequest('GET', '/print_jobs', null, [
            'user_id' => 'eq.' . $userId,
            'select' => '*',
            'status' => 'not.in.(completed,refund_issued)',
            'order' => 'created_at.desc'
        ]);
        echo json_encode(['success' => true, 'data' => $result['error'] ? [] : $result['data']]);

    } elseif ($action === 'get-all-jobs') {
        $userId = $_GET['user_id'] ?? '';
        if (empty($userId)) { http_response_code(400); echo json_encode(['error' => 'User ID required']); exit; }
        $result = supabaseRequest('GET', '/print_jobs', null, [
            'user_id' => 'eq.' . $userId,
            'select' => '*',
            'order' => 'created_at.desc'
        ]);
        echo json_encode(['success' => true, 'data' => $result['error'] ? [] : $result['data']]);

    } elseif ($action === 'get-face-users') {
        $result = supabaseRequest('GET', '/users', null, [
            'select' => 'id,name,face_descriptor',
            'face_descriptor' => 'not.is.null',
            'role' => 'eq.customer'
        ]);
        echo json_encode(['success' => true, 'data' => $result['error'] ? [] : $result['data']]);

    } elseif ($action === 'get-user') {
        $userId = $_GET['id'] ?? '';
        if (empty($userId)) { http_response_code(400); echo json_encode(['error' => 'User ID required']); exit; }
        $result = supabaseRequest('GET', '/users', null, ['id' => 'eq.' . $userId, 'select' => '*']);
        echo json_encode(['success' => true, 'data' => $result['error'] || empty($result['data']) ? null : $result['data'][0]]);

    } elseif ($action === 'get-users-with-face') {
        $result = supabaseRequest('GET', '/users', null, [
            'select' => 'id,name,face_descriptor',
            'face_descriptor' => 'not.is.null'
        ]);
        echo json_encode(['success' => true, 'data' => $result['error'] ? [] : $result['data']]);

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} elseif ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'complete-job') {
    handleCompleteJob();
} elseif ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete-job') {
    handleDeleteJob();
} elseif ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'submit-report') {
    handleSubmitReport();
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function handleCompleteJob() {
    $input = json_decode(file_get_contents('php://input'), true);
    $jobId = $input['job_id'] ?? '';
    if (empty($jobId)) { http_response_code(400); echo json_encode(['error' => 'job_id required']); return; }

    $jobResult = supabaseRequest('GET', '/print_jobs', null, [
        'job_id' => 'eq.' . $jobId,
        'select' => 'id,file_path'
    ]);
    $job = $jobResult['data'][0] ?? null;
    if (!$job) { http_response_code(404); echo json_encode(['error' => 'Job not found']); return; }

    $updateResult = supabaseRequest('PATCH', '/print_jobs?job_id=eq.' . $jobId, [
        'status' => 'completed',
        'print_pin' => null
    ]);

    $filePath = $job['file_path'] ?? '';
    if ($filePath && file_exists($filePath)) {
        @unlink($filePath);
    }

    echo json_encode(['success' => true, 'message' => 'Job completed and cleaned up']);
}

function handleSubmitReport() {
    $input = json_decode(file_get_contents('php://input'), true);
    $type = trim($input['type'] ?? '');
    $description = trim($input['description'] ?? '');
    $jobId = trim($input['job_id'] ?? '');
    $userId = trim($input['user_id'] ?? '');
    $userEmail = trim($input['user_email'] ?? '');

    if (empty($description)) {
        http_response_code(400);
        echo json_encode(['error' => 'Description required']);
        return;
    }

    $typeLabels = [
        'print_error' => 'Print Error',
        'payment_issue' => 'Payment Issue',
        'machine_problem' => 'Machine Problem',
        'quality_issue' => 'Quality Issue',
        'other' => 'Other'
    ];
    $typeLabel = $typeLabels[$type] ?? ($type ? ucfirst($type) : 'General');

    $parts = ['[Report] ' . $typeLabel];
    $parts[] = $description;
    if (!empty($jobId)) $parts[] = 'Job: ' . $jobId;
    if (!empty($userEmail)) $parts[] = 'From: ' . $userEmail;
    $message = implode(' | ', $parts);

    // Prefer the dedicated user_report type, fall back to an allowed machine type
    $alertTypes = [
        'print_error' => 'print_complete',
        'payment_issue' => 'payment_received',
        'machine_problem' => 'paper_jam',
        'quality_issue' => 'print_complete',
        'other' => 'paper_low'
    ];

    $result = supabaseRequest('POST', '/alerts', [
        'type' => 'user_report',
        'message' => $message,
        'severity' => 'warning',
        'is_read' => false
    ]);

    if ($result['error']) {
        $fallbackType = $alertTypes[$type] ?? 'print_complete';
        $result = supabaseRequest('POST', '/alerts', [
            'type' => $fallbackType,
            'message' => $message,
            'severity' => 'warning',
            'is_read' => false
        ]);
    }

    if ($result['error']) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to submit report']);
        return;
    }

    echo json_encode(['success' => true, 'message' => 'Report submitted']);
}

function handleDeleteJob() {
    $input = json_decode(file_get_contents('php://input'), true);
    $jobId = $input['job_id'] ?? '';
    if (empty($jobId)) { http_response_code(400); echo json_encode(['error' => 'job_id required']); return; }

    $jobResult = supabaseRequest('GET', '/print_jobs', null, [
        'job_id' => 'eq.' . $jobId,
        'select' => 'id,file_path'
    ]);
    $job = $jobResult['data'][0] ?? null;
    if (!$job) { http_response_code(404); echo json_encode(['error' => 'Job not found']); return; }

    supabaseRequest('PATCH', '/print_jobs?job_id=eq.' . $jobId, [
        'status' => 'completed',
        'print_pin' => null
    ]);

    $filePath = $job['file_path'] ?? '';
    if ($filePath && file_exists($filePath)) {
        @unlink($filePath);
    }

    echo json_encode(['success' => true, 'message' => 'Job deleted']);
}
