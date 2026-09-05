<?php
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);
error_reporting(E_ALL);
ini_set('display_errors', 1);

$api_key = "8cf02422-c860-4195-940d-d74a1649efe7";

$conn = new mysqli("localhost", "root", "", "wanikani_offline");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error . "\n");
}
$conn->set_charset("utf8mb4");

function fetch_all_pages($endpoint, $key) {
    $url = "https://api.wanikani.com/v2/" . $endpoint;
    $results = [];

    while ($url) {
        echo "Fetching: $url\n";
        flush();

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $key",
            "Wanikani-Revision: 20170710"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 429) {
            echo "Rate limit reached. Sleeping 10s...\n";
            sleep(10);
            continue;
        }

        if ($code !== 200) {
            echo "API Error HTTP $code: $res\n";
            break;
        }

        $json = json_decode($res, true);
        $items = $json['data'] ?? [];
        $results = array_merge($results, $items);
        $url = $json['pages']['next_url'] ?? null;
    }

    return $results;
}

echo "===============================================\n";
echo "       DIRECT PHP LIVE WANIKANI SYNC           \n";
echo "===============================================\n\n";

// Disable Foreign Key checks for clean overwrite
$conn->query("SET FOREIGN_KEY_CHECKS = 0;");

// 1. SYNC ASSIGNMENTS
echo "[1/2] Syncing All User Assignments from Live API...\n";
$assignments = fetch_all_pages("assignments", $api_key);
echo "Fetched " . count($assignments) . " total assignment records from API.\n";

$conn->query("DELETE FROM assignments;");

$assign_stmt = $conn->prepare("
    INSERT INTO assignments (id, subject_id, srs_stage, unlocked_at, started_at, passed_at, available_at)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

$assign_count = 0;
foreach ($assignments as $a) {
    $id = $a['id'];
    $d = $a['data'];
    $sub_id = $d['subject_id'];
    $srs = intval($d['srs_stage']);
    $unlocked = $d['unlocked_at'];
    $started = $d['started_at'];
    $passed = $d['passed_at'];
    $available = $d['available_at'];

    $assign_stmt->bind_param("iiissss", $id, $sub_id, $srs, $unlocked, $started, $passed, $available);
    $assign_stmt->execute();
    $assign_count++;
}
echo "✓ Successfully written $assign_count fresh assignments to MySQL.\n\n";

// 2. SYNC REVIEW STATISTICS INTO REVIEWS TABLE
echo "[2/2] Syncing Review Statistics (/v2/review_statistics)...\n";
$conn->query("
    CREATE TABLE IF NOT EXISTS reviews (
        id INT PRIMARY KEY,
        assignment_id INT,
        subject_id INT,
        created_at VARCHAR(50),
        incorrect_meaning_answers INT DEFAULT 0,
        incorrect_reading_answers INT DEFAULT 0,
        FOREIGN KEY(subject_id) REFERENCES subjects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$conn->query("DELETE FROM reviews;");

$stats = fetch_all_pages("review_statistics", $api_key);
echo "Fetched " . count($stats) . " review statistics records.\n";

$rev_stmt = $conn->prepare("
    INSERT INTO reviews (id, assignment_id, subject_id, created_at, incorrect_meaning_answers, incorrect_reading_answers)
    VALUES (?, ?, ?, ?, ?, ?)
");

$saved_count = 0;
$mistake_count = 0;

foreach ($stats as $s) {
    $stat_id = $s['id'];
    $updated_at = $s['data_updated_at'] ?? $s['data']['created_at'];
    $d = $s['data'];
    $sub_id = $d['subject_id'];
    $inc_m = intval($d['meaning_incorrect'] ?? 0);
    $inc_r = intval($d['reading_incorrect'] ?? 0);

    $assign_res = $conn->query("SELECT id FROM assignments WHERE subject_id = $sub_id");
    $assign_id = ($assign_row = $assign_res->fetch_assoc()) ? $assign_row['id'] : null;

    $rev_stmt->bind_param("iiisii", $stat_id, $assign_id, $sub_id, $updated_at, $inc_m, $inc_r);
    $rev_stmt->execute();
    $saved_count++;
    if ($inc_m > 0 || $inc_r > 0) {
        $mistake_count++;
    }
}
echo "✓ Saved $saved_count statistics records to MySQL ($mistake_count with mistakes).\n\n";

// Re-enable Foreign Key checks
$conn->query("SET FOREIGN_KEY_CHECKS = 1;");

echo "===============================================\n";
echo "SYNC COMPLETE! Refresh debug_dump.php to verify.\n";
echo "===============================================\n";