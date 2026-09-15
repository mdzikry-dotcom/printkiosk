<?php
require_once __DIR__ . '/config.php';
jsonHeader();

$method = $_SERVER['REQUEST_METHOD'];

// Handle PIN lookup via GET
if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'lookup-pin') {
    $pin = $_GET['pin'] ?? '';
    if (empty($pin) || !preg_match('/^\d{6}$/', $pin)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid PIN']);
        exit;
    }
    $result = supabaseRequest('GET', '/print_jobs', null, [
        'print_pin' => 'eq.' . $pin,
        'select' => '*',
        'status' => 'not.in.(completed,refund_issued)',
        'order' => 'created_at.asc'
    ]);
    if ($result['error'] || empty($result['data'])) {
        echo json_encode(['success' => false, 'error' => 'No job found with this PIN']);
        exit;
    }
    echo json_encode(['success' => true, 'jobs' => $result['data'], 'job' => $result['data'][0]]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$session = validateSession();
$userId = null;
if ($session) {
    $userId = $session['user_id'];
}

// Handle multipart upload
if (empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];

// Validate
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload error code: ' . $file['error']]);
    exit;
}

if ($file['size'] > MAX_FILE_SIZE) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large. Maximum 20MB allowed.']);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ALLOWED_EXTENSIONS)) {
    http_response_code(400);
    echo json_encode(['error' => 'File type not allowed. Allowed: PDF, DOC, DOCX, JPG, PNG']);
    exit;
}

// Create uploads directory if needed
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Save file
$safeName = uniqid('print_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
$destPath = UPLOAD_DIR . $safeName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file']);
    exit;
}

// Determine file type category
$imageTypes = ['jpg', 'jpeg', 'png'];
$isImage = in_array($ext, $imageTypes);
$fileType = $isImage ? 'image' : 'document';

// Detect pages for PDF/DOCX files automatically
$detectedPages = detectFilePages($destPath, $ext);

// Get settings from request (use detected pages if not manually specified)
$colorMode = $_POST['color_mode'] ?? 'bw';
$paperSize = $_POST['paper_size'] ?? 'a4';
$sides = $_POST['sides'] ?? 'single';
$copies = (int)($_POST['copies'] ?? 1);
$pages = (int)($_POST['pages'] ?? $detectedPages);
$brightness = (int)($_POST['brightness'] ?? 0);
$contrast = (int)($_POST['contrast'] ?? 0);
$cropMode = $_POST['crop_mode'] ?? 'none';
$finish = $_POST['finish'] ?? 'none';

// Validate
if (!in_array($colorMode, ['bw', 'color'])) $colorMode = 'bw';
if (!in_array($paperSize, ['a4', 'a3'])) $paperSize = 'a4';
if (!in_array($sides, ['single', 'double'])) $sides = 'single';
if ($copies < 1) $copies = 1;
if ($pages < 1) $pages = 1;

$totalPrice = calculatePrice($pages, $colorMode, $paperSize, $sides, $copies);

// Generate random 6-digit OTP print PIN (use provided PIN if valid, so multi-file orders share one PIN)
$requestedPin = trim($_POST['print_pin'] ?? '');
$printPin = preg_match('/^\d{6}$/', $requestedPin) ? $requestedPin : str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$jobId = generateJobId();

// Create print job record
$jobData = [
    'job_id' => $jobId,
    'user_id' => $userId,
    'session_id' => bin2hex(random_bytes(16)),
    'file_name' => $file['name'],
    'file_path' => $destPath,
    'file_type' => $fileType,
    'pages' => $pages,
    'color_mode' => $colorMode,
    'paper_size' => $paperSize,
    'sides' => $sides,
    'copies' => $copies,
    'brightness' => $brightness,
    'contrast' => $contrast,
    'crop_mode' => $cropMode,
    'finish' => $finish,
    'total_price' => $totalPrice,
    'status' => 'pending',
    'paid' => false,
    'print_pin' => $printPin
];

$result = supabaseRequest('POST', '/print_jobs', $jobData);

if ($result['error']) {
    unlink($destPath);
    $errMsg = $result['message'] ?? ($result['data']['message'] ?? 'Database insert failed');
    $errMsg .= ' (HTTP ' . ($result['http_code'] ?? '?') . ')';
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create print job: ' . $errMsg]);
    exit;
}

echo json_encode([
    'success' => true,
    'job' => $result['data'][0],
    'print_pin' => $printPin,
    'pages_detected' => $detectedPages,
    'message' => 'File uploaded successfully'
]);
