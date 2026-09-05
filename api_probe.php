<?php
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$api_key = "8cf02422-c860-4195-940d-d74a1649efe7";

function test_endpoint($url, $key) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $key",
        "Wanikani-Revision: 20170710"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ["code" => $code, "body" => json_decode($res, true), "raw" => $res];
}

echo "====================================================\n";
echo "           WANIKANI API PROBE TEST                  \n";
echo "====================================================\n\n";

// Test 1: Plain Reviews without any query filters
echo "--- TEST 1: Plain /v2/reviews (First 5 items) ---\n";
$t1 = test_endpoint("https://api.wanikani.com/v2/reviews", $api_key);
echo "HTTP Code: " . $t1['code'] . "\n";
echo "Total Count in Response: " . count($t1['body']['data'] ?? []) . "\n";
if (!empty($t1['body']['data'])) {
    echo "First item sample:\n";
    print_r($t1['body']['data'][0]);
} else {
    echo "Raw response:\n" . substr($t1['raw'], 0, 500) . "\n";
}
echo "\n";

// Test 2: Review Statistics (/v2/review_statistics)
echo "--- TEST 2: /v2/review_statistics (First 3 items) ---\n";
$t2 = test_endpoint("https://api.wanikani.com/v2/review_statistics", $api_key);
echo "HTTP Code: " . $t2['code'] . "\n";
echo "Total Count in Response: " . count($t2['body']['data'] ?? []) . "\n";
if (!empty($t2['body']['data'])) {
    echo "First item sample:\n";
    print_r($t2['body']['data'][0]);
} else {
    echo "Raw response:\n" . substr($t2['raw'], 0, 500) . "\n";
}
echo "\n";

// Test 3: Recent Review Statistics with errors
echo "--- TEST 3: /v2/review_statistics (Items with incorrect count > 0) ---\n";
$err_items = [];
if (!empty($t2['body']['data'])) {
    foreach ($t2['body']['data'] as $stat) {
        $d = $stat['data'];
        $m_err = $d['meaning_incorrect'] ?? 0;
        $r_err = $d['reading_incorrect'] ?? 0;
        if ($m_err > 0 || $r_err > 0) {
            $err_items[] = [
                'subject_id' => $d['subject_id'],
                'subject_type' => $d['subject_type'],
                'meaning_incorrect' => $m_err,
                'reading_incorrect' => $r_err,
                'meaning_current_streak' => $d['meaning_current_streak'] ?? null,
                'reading_current_streak' => $d['reading_current_streak'] ?? null
            ];
        }
    }
}
echo "Found " . count($err_items) . " subjects with mistake history.\n";
if (!empty($err_items)) {
    echo "Sample mistake records:\n";
    print_r(array_slice($err_items, 0, 5));
}

echo "\n====================================================\n";