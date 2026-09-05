<?php
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$api_key = "8cf02422-c860-4195-940d-d74a1649efe7";

function fetch_all_live_assignments($key) {
    $url = "https://api.wanikani.com/v2/assignments";
    $results = [];

    while ($url) {
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

        if ($code !== 200) {
            echo "API Error HTTP $code: $res\n";
            break;
        }

        $json = json_decode($res, true);
        $results = array_merge($results, $json['data'] ?? []);
        $url = $json['pages']['next_url'] ?? null;
    }
    return $results;
}

$conn = new mysqli("localhost", "root", "", "wanikani_offline");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error . "\n");
}
$conn->set_charset("utf8mb4");

echo "====================================================\n";
echo "    FULL LIFETIME UPCOMING REVIEWS VERIFICATION    \n";
echo "====================================================\n\n";

echo "Pulling fresh assignments across all pagination pages from WaniKani API...\n";
$live_assignments = fetch_all_live_assignments($api_key);
echo "Fetched " . count($live_assignments) . " total assignment records from Live API.\n\n";

// 1. Group Live API Assignments by Timestamp
$live_batches = [];
foreach ($live_assignments as $la) {
    $d = $la['data'];
    $srs = intval($d['srs_stage']);
    if ($srs >= 1 && $srs <= 8 && !empty($d['started_at']) && !empty($d['available_at'])) {
        $ts = strtotime($d['available_at']);
        $norm_key = gmdate('Y-m-d\TH:00:00.000000\Z', $ts);
        $live_batches[$norm_key] = ($live_batches[$norm_key] ?? 0) + 1;
    }
}

// 2. Group Local MySQL Assignments by Timestamp
$loc_batch_res = $conn->query("
    SELECT available_at, COUNT(*) as cnt
    FROM assignments
    WHERE srs_stage BETWEEN 1 AND 8 
      AND started_at IS NOT NULL
      AND available_at IS NOT NULL
    GROUP BY available_at
    ORDER BY available_at ASC
");

$local_batches = [];
while ($row = $loc_batch_res->fetch_assoc()) {
    $ts = strtotime($row['available_at']);
    $norm_key = gmdate('Y-m-d\TH:00:00.000000\Z', $ts);
    $local_batches[$norm_key] = ($local_batches[$norm_key] ?? 0) + intval($row['cnt']);
}

// 3. Compare Every Single Batch
$now_ts = time();
$all_keys = array_unique(array_merge(array_keys($live_batches), array_keys($local_batches)));
sort($all_keys);

$matched_count = 0;
$mismatch_count = 0;

echo "[1] ALL UPCOMING SCHEDULE BATCHES (Live Assignments vs Local DB):\n";
foreach ($all_keys as $time_key) {
    $live_cnt = $live_batches[$time_key] ?? 0;
    $local_cnt = $local_batches[$time_key] ?? 0;

    $diff_seconds = strtotime($time_key) - $now_ts;
    $hrs = floor($diff_seconds / 3600);
    $mins = floor(($diff_seconds % 3600) / 60);
    $countdown = ($diff_seconds <= 0) ? "DUE NOW" : "in {$hrs}h {$mins}m";

    $is_match = ($live_cnt === $local_cnt);
    $status_str = $is_match ? "✓ MATCH" : "⚠ MISMATCH";

    if ($is_match) {
        $matched_count++;
    } else {
        $mismatch_count++;
    }

    echo sprintf("  %s (%-9s) | Live: %2d items | Local: %2d items -> %s\n",
        $time_key,
        $countdown,
        $live_cnt,
        $local_cnt,
        $status_str
    );
}

echo "\n====================================================\n";
echo "Total Batches Evaluated: " . count($all_keys) . "\n";
echo "Matched: $matched_count | Mismatched: $mismatch_count\n";
if ($mismatch_count === 0 && count($all_keys) > 0) {
    echo "✓ 100% PERFECT MATCH ACROSS ALL UPCOMING WEEKS & MONTHS!\n";
}
echo "====================================================\n";