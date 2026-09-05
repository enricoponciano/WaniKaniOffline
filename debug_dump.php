<?php
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$api_key = "8cf02422-c860-4195-940d-d74a1649efe7";

function fetch_all_assignments($key) {
    $url = "https://api.wanikani.com/v2/assignments";
    $results = [];
    while ($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $key",
            "Wanikani-Revision: 20170710"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $res = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($res, true);
        $results = array_merge($results, $json['data'] ?? []);
        $url = $json['pages']['next_url'] ?? null;
    }
    return $results;
}

$conn = new mysqli("localhost", "root", "", "wanikani_offline");
$conn->set_charset("utf8mb4");

echo "====================================================\n";
echo "       DEEP SRS STAGE VERIFICATION AUDIT            \n";
echo "====================================================\n\n";

// 1. Fetch live assignments fresh
$live_assignments = fetch_all_assignments($api_key);
$live_by_subject = [];
$live_srs_counts = array_fill(1, 8, 0);

foreach ($live_assignments as $la) {
    $d = $la['data'];
    $st = intval($d['srs_stage']);
    $live_by_subject[$d['subject_id']] = [
        'stage' => $st,
        'unlocked' => $d['unlocked_at'],
        'started' => $d['started_at'],
        'available' => $d['available_at'],
        'passed' => $d['passed_at'],
        'burned' => $d['burned_at'] ?? null
    ];
    if ($st >= 1 && $st <= 8 && !empty($d['started_at'])) {
        $live_srs_counts[$st]++;
    }
}

// 2. Fetch local assignments
$loc_res = $conn->query("SELECT subject_id, srs_stage, unlocked_at, started_at, passed_at, available_at FROM assignments");
$local_by_subject = [];
$local_srs_counts = array_fill(1, 8, 0);

while ($row = $loc_res->fetch_assoc()) {
    $st = intval($row['srs_stage']);
    $sid = intval($row['subject_id']);
    $local_by_subject[$sid] = [
        'stage' => $st,
        'unlocked' => $row['unlocked_at'],
        'started' => $row['started_at'],
        'available' => $row['available_at'],
        'passed' => $row['passed_at']
    ];
    if ($st >= 1 && $st <= 8 && !empty($row['started_at'])) {
        $local_srs_counts[$st]++;
    }
}

echo "[1] SRS STAGE TOTALS (Started items only):\n";
for ($s = 1; $s <= 8; $s++) {
    $match = ($live_srs_counts[$s] === $local_srs_counts[$s]) ? "MATCH" : "MISMATCH";
    echo "  Stage $s: Live = {$live_srs_counts[$s]} | Local = {$local_srs_counts[$s]} --> $match\n";
}
echo "\n";

// 3. Find EXACT Subject ID differences between Live and Local
echo "[2] SPECIFIC SUBJECT DIFFERENCES (Top 10):\n";
$diff_count = 0;
foreach ($live_by_subject as $sid => $live_data) {
    $loc_data = $local_by_subject[$sid] ?? null;
    if (!$loc_data) {
        echo "  - Subject #$sid exists in Live but MISSING in Local DB!\n";
        $diff_count++;
    } elseif ($live_data['stage'] !== $loc_data['stage']) {
        $sub = $conn->query("SELECT characters, object_type FROM subjects WHERE id = $sid")->fetch_assoc();
        $char = $sub['characters'] ?? "ID $sid";
        echo "  - {$char} ({$sub['object_type']}): Live Stage = {$live_data['stage']} | Local DB Stage = {$loc_data['stage']}\n";
        $diff_count++;
    }
    if ($diff_count >= 10) break;
}

if ($diff_count === 0) {
    echo "  ✓ No differences found! Every single subject's SRS stage matches.\n";
}

echo "\n====================================================\n";