<?php
// Supabase Configuration
define('SUPABASE_URL', 'https://ninsrrallzwmkqyosqeu.supabase.co');
define('SUPABASE_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im5pbnNycmFsbHp3bWtxeW9zcWV1Iiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc4Mzg2MDk1MiwiZXhwIjoyMDk5NDM2OTUyfQ.JabQJz9kRWtZqY7FVJD14yOaKT0phfXTQekGUzYe5ZA');
define('SUPABASE_REST_URL', SUPABASE_URL . '/rest/v1');

// App Configuration
define('APP_NAME', 'PrintKiosk');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_SIZE', 20 * 1024 * 1024); // 20MB
define('ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']);
define('SESSION_DURATION', 86400 * 7); // 7 days

// Email Verification (Gmail SMTP)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', getenv('SMTP_USER') ?: 'printkiosk.sender@gmail.com');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'vewuljgrgldslwwv');
define('SMTP_FROM_NAME', 'PrintKiosk');
define('VERIFICATION_CODE_LENGTH', 6);
define('VERIFICATION_CODE_TTL', 600); // 10 minutes
define('RESET_TOKEN_TTL', 1800); // 30 minutes

// Pricing
define('PRICE_BW_PER_PAGE', 0.20);
define('PRICE_COLOR_PER_PAGE', 1.10);
define('PRICE_A3_MULTIPLIER', 2.0);
define('PRICE_DOUBLE_SIDED_MULTIPLIER', 0.7);
define('COINS_PER_RM', 1);
define('COINS_TO_RM', 100);

// CORS Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Session-Token');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// JSON default header
function jsonHeader() {
    header('Content-Type: application/json; charset=utf-8');
}

// Validate email: must be a Gmail address
function isValidGmail($email) {
    return preg_match('/^[a-zA-Z0-9._%+\-]+@gmail\.com$/i', $email) === 1;
}

// Validate Malaysian mobile number (010-019, optional +60 / 0 prefix)
function isValidMalaysianPhone($phone) {
    $p = preg_replace('/[\s\-]/', '', trim($phone));
    return preg_match('/^(?:\+?60|0)?1[0-9]{8,9}$/', $p) === 1;
}

// Normalize phone to +60XXXXXXXXX form
function normalizePhone($phone) {
    $p = preg_replace('/[\s\-]/', '', trim($phone));
    if (preg_match('/^0(1[0-9]{8,9})$/', $p, $m)) return '+60' . $m[1];
    if (preg_match('/^(60)(1[0-9]{8,9})$/', $p, $m)) return '+' . $m[1] . $m[2];
    if (preg_match('/^\+?(6?0)?(1[0-9]{8,9})$/', $p, $m)) return '+60' . $m[2];
    return $p;
}

// Generate numeric verification code
function generateVerificationCode() {
    return str_pad((string)random_int(0, 999999), VERIFICATION_CODE_LENGTH, '0', STR_PAD_LEFT);
}

// Minimal SMTP client (no dependencies) - sends via Gmail SMTP with STARTTLS
function smtpSend($to, $subject, $htmlBody) {
    if (empty(SMTP_USER) || empty(SMTP_PASS)) return false;

    $sock = stream_socket_client('tcp://' . SMTP_HOST . ':' . SMTP_PORT, $errno, $errstr, 20);
    if (!$sock) return false;

    $readReply = function ($sock) {
        $data = '';
        while ($line = fgets($sock, 515)) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return $data;
    };

    $cmd = function ($sock, $c) use ($readReply) {
        fwrite($sock, $c . "\r\n");
        return $readReply($sock);
    };

    try {
        $readReply($sock); // greeting

        $cmd($sock, 'EHLO localhost');

        $r = $cmd($sock, 'STARTTLS');
        if (strpos($r, '220') !== 0) { fclose($sock); return false; }
        $ok = stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$ok) { fclose($sock); return false; }

        $cmd($sock, 'EHLO localhost');

        $r = $cmd($sock, 'AUTH LOGIN');
        if (strpos($r, '334') !== 0) { fclose($sock); return false; }
        $cmd($sock, base64_encode(SMTP_USER));
        $r = $cmd($sock, base64_encode(SMTP_PASS));
        if (strpos($r, '235') !== 0) { fclose($sock); return false; }

        $r = $cmd($sock, 'MAIL FROM:<' . SMTP_USER . '>');
        if (strpos($r, '250') !== 0) { fclose($sock); return false; }

        $r = $cmd($sock, 'RCPT TO:<' . $to . '>');
        if (strpos($r, '250') !== 0) { fclose($sock); return false; }

        $r = $cmd($sock, 'DATA');
        if (strpos($r, '354') !== 0) { fclose($sock); return false; }

        $subjectClean = preg_replace('/[\r\n]+/', ' ', $subject);
        $headers = 'From: ' . SMTP_FROM_NAME . ' <' . SMTP_USER . '>' . "\r\n"
            . 'To: ' . $to . "\r\n"
            . 'Subject: ' . $subjectClean . "\r\n"
            . 'MIME-Version: 1.0' . "\r\n"
            . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
            . 'Date: ' . date('r') . "\r\n";

        $bodyData = str_replace("\r\n.", "\r\n..", str_replace("\n", "\r\n", $htmlBody));
        fwrite($sock, $headers . "\r\n" . $bodyData . "\r\n.\r\n");
        $readReply($sock);

        $cmd($sock, 'QUIT');
        fclose($sock);
        return true;
    } catch (Exception $e) {
        @fclose($sock);
        return false;
    }
}

// Send verification email via Gmail SMTP
function sendVerificationEmail($to, $code, $name) {
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;">'
        . '<h2 style="color:#0f172a;margin:0 0 8px;">Verify your email</h2>'
        . '<p style="color:#475569;font-size:14px;">Hi ' . htmlspecialchars($name) . ',</p>'
        . '<p style="color:#475569;font-size:14px;line-height:1.6;">Use the code below to complete your ' . APP_NAME . ' registration. This code expires in 10 minutes.</p>'
        . '<div style="text-align:center;margin:24px 0;">'
        . '<span style="display:inline-block;font-size:28px;font-weight:700;letter-spacing:6px;color:#0f172a;background:#f1f5f9;padding:14px 24px;border-radius:10px;">' . htmlspecialchars($code) . '</span>'
        . '</div>'
        . '<p style="color:#94a3b8;font-size:12px;">If you did not request this, you can safely ignore this email.</p>'
        . '</div>';

    return smtpSend($to, APP_NAME . ' - Verify your email', $html);
}

// CURL helper for Supabase REST API
function supabaseRequest($method, $endpoint, $data = null, $params = []) {
    $url = SUPABASE_REST_URL . $endpoint;
    if (!empty($params)) {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $url .= '?' . $query;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation'
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['error' => true, 'message' => $error, 'http_code' => 0];
    }

    $decoded = json_decode($response, true);
    if ($decoded === null && $response !== '') {
        return ['error' => $httpCode >= 400, 'data' => $response, 'http_code' => $httpCode];
    }

    if ($httpCode >= 400) {
        $errMsg = is_array($decoded) ? ($decoded['message'] ?? ($decoded['error'] ?? json_encode($decoded))) : ($decoded ?? 'Unknown error');
        return ['error' => true, 'message' => $errMsg, 'http_code' => $httpCode, 'data' => $decoded];
    }

    return ['error' => false, 'data' => $decoded, 'http_code' => $httpCode];
}

// Generate job ID: PRN-YYYYMMDD-XXXX
function generateJobId() {
    $date = date('Ymd');
    $rand = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    return 'PRN-' . $date . '-' . $rand;
}

// Generate session token
function generateSessionToken() {
    return bin2hex(random_bytes(32));
}

// Validate session from header
function validateSession() {
    $headers = getallheaders();
    $token = $headers['X-Session-Token'] ?? ($_GET['token'] ?? '');

    if (empty($token)) {
        return null;
    }

    $result = supabaseRequest('GET', '/sessions', null, [
        'session_token' => 'eq.' . $token,
        'select' => '*,users(id,name,email,role,coins)'
    ]);

    if ($result['error'] || empty($result['data']) || count($result['data']) === 0) {
        return null;
    }

    $session = $result['data'][0];

    // Check expiry
    if (strtotime($session['expires_at']) < time()) {
        supabaseRequest('DELETE', '/sessions', null, ['session_token' => 'eq.' . $token]);
        return null;
    }

    return $session;
}

// Calculate print price
function calculatePrice($pages, $colorMode, $paperSize, $sides, $copies) {
    $pagePrice = ($colorMode === 'color') ? PRICE_COLOR_PER_PAGE : PRICE_BW_PER_PAGE;
    $sizeMultiplier = ($paperSize === 'a3') ? PRICE_A3_MULTIPLIER : 1.0;
    $sidesMultiplier = ($sides === 'double') ? PRICE_DOUBLE_SIDED_MULTIPLIER : 1.0;

    $effectivePages = $pages * $sidesMultiplier;
    $pricePerCopy = $effectivePages * $pagePrice * $sizeMultiplier;
    $total = $pricePerCopy * $copies;

    return round($total, 2);
}

// Detect page count from PDF or DOCX file
function detectFilePages($filePath, $ext) {
    if (!file_exists($filePath)) return 1;

    if ($ext === 'pdf') {
        $content = file_get_contents($filePath, false, null, 0, 3000000);
        if ($content === false) return 1;
        if (preg_match('/\/Type\s*\/Pages[\s\S]*?\/Count\s+(\d+)/i', $content, $m)) {
            $count = (int)$m[1];
            if ($count > 0) return $count;
        }
        $count = preg_match_all('/\/Type\s*\/Page\b(?!\s*\/Pages)/i', $content, $matches);
        if ($count > 0) return $count;
        $count = preg_match_all('/\/Page\b(?!\s*\/(?:Pages|Label))/i', $content, $matches);
        return $count > 0 ? $count : 1;
    }

    if ($ext === 'docx' || $ext === 'doc') {
        // DOCX is a ZIP file with XML inside
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($filePath) === true) {
                // Try app.xml first (has explicit page count)
                $appXml = $zip->getFromName('docProps/app.xml');
                if ($appXml !== false) {
                    $xml = simplexml_load_string($appXml);
                    if ($xml !== false) {
                        $ns = $xml->getNamespaces(true);
                        $pages = (int)$xml->Pages;
                        if ($pages > 0) { $zip->close(); return $pages; }
                        // Try with namespace
                        if (isset($ns[''])) {
                            $pages = (int)$xml->children($ns[''])->Pages;
                            if ($pages > 0) { $zip->close(); return $pages; }
                        }
                    }
                }
                // Fallback: count paragraphs in document.xml
                $docXml = $zip->getFromName('word/document.xml');
                if ($docXml !== false) {
                    $paraCount = substr_count($docXml, '<w:p ');
                    if ($paraCount > 0) {
                        // Rough estimate: ~30 paragraphs per page
                        $zip->close();
                        return max(1, ceil($paraCount / 30));
                    }
                }
                $zip->close();
            }
        }
        return 1;
    }

    // Images and other files default to 1 page
    return 1;
}

// Delete file after job completed
function cleanupCompletedFiles() {
    $result = supabaseRequest('GET', '/print_jobs', null, [
        'status' => 'eq.completed',
        'select' => 'file_path'
    ]);

    if ($result['error'] || empty($result['data'])) return;

    foreach ($result['data'] as $job) {
        $filePath = $job['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}
