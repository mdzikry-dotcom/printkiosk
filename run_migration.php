<?php
// Run migration: add print_pin column to print_jobs table
// This script tries multiple approaches to connect to the database.

require_once __DIR__ . '/config.php';

echo "Attempting to add print_pin column to print_jobs table...\n";

// Approach 1: Try using the existing supabaseRequest to see if we can detect the issue
echo "\n[1] Checking current database state...\n";
$result = supabaseRequest('GET', '/print_jobs', null, ['select' => 'print_pin', 'limit' => 1]);
if (!$result['error']) {
    echo "  print_pin column already exists! No migration needed.\n";
    exit(0);
} else {
    echo "  print_pin column is missing (expected). Error: " . ($result['message'] ?? 'Unknown') . "\n";
}

// Approach 2: Try creating a temporary SQL migration via a PHP trick
// We can try using raw pg_connect if DNS/network allows
echo "\n[2] Trying direct PostgreSQL connection...\n";
$host = 'db.ninsrrallzwmkqyosqeu.supabase.co';
$port = 5432;
$user = 'postgres';
$pass = SUPABASE_KEY;

// Try with IPv6 literal
$ipv6 = '2406:da12:5ca:b702:b6f8:c76b:c836:79de';
$dsn = "pgsql:host=[$ipv6];port=$port;dbname=postgres";
try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    echo "  Connected via IPv6! Running migration...\n";
    $pdo->exec("ALTER TABLE print_jobs ADD COLUMN IF NOT EXISTS print_pin TEXT;");
    echo "  print_pin column added successfully!\n";
    
    // Also update pin_expires_at if it doesn't exist
    $pdo->exec("ALTER TABLE print_jobs ADD COLUMN IF NOT EXISTS pin_expires_at TIMESTAMPTZ;");
    echo "  pin_expires_at confirmed.\n";
    
    $pdo = null;
    exit(0);
} catch (Exception $e) {
    echo "  IPv6 PDO failed: " . $e->getMessage() . "\n";
}

// Try pg_connect with IPv6
try {
    $conn = pg_connect("host=[$ipv6] port=$port dbname=postgres user=$user password=$pass connect_timeout=5");
    if ($conn) {
        echo "  Connected via pg_connect IPv6! Running migration...\n";
        pg_query($conn, "ALTER TABLE print_jobs ADD COLUMN IF NOT EXISTS print_pin TEXT;");
        pg_query($conn, "ALTER TABLE print_jobs ADD COLUMN IF NOT EXISTS pin_expires_at TIMESTAMPTZ;");
        echo "  Columns added successfully!\n";
        pg_close($conn);
        exit(0);
    }
} catch (Exception $e) {
    echo "  pg_connect IPv6 failed: " . $e->getMessage() . "\n";
}

// Approach 3: Try using the Supabase Management API
echo "\n[3] Trying Supabase Management API...\n";
$mgmtUrl = 'https://api.supabase.com/v1/projects/ninsrrallzwmkqyosqeu/database/query';
$sql = json_encode(['query' => 'ALTER TABLE print_jobs ADD COLUMN IF NOT EXISTS print_pin TEXT;']);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $mgmtUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $sql);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Content-Type: application/json'
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($httpCode === 200 || $httpCode === 201) {
    echo "  Management API success!\n";
    exit(0);
} else {
    echo "  Management API failed (HTTP $httpCode): " . ($error ?: 'Check response') . "\n";
    if ($response) echo "  Response: " . substr($response, 0, 200) . "\n";
}

echo "\n============================================================\n";
echo "Could not run migration automatically.\n";
echo "Please run this SQL in Supabase SQL Editor (https://supabase.com/dashboard/project/ninsrrallzwmkqyosqeu):\n";
echo "============================================================\n";
echo "ALTER TABLE print_jobs ADD COLUMN IF NOT EXISTS print_pin TEXT;\n";
echo "ALTER TABLE print_jobs ADD COLUMN IF NOT EXISTS pin_expires_at TIMESTAMPTZ;\n";
echo "============================================================\n";
