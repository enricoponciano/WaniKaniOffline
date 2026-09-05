<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Keep JSON clean

$conn = new mysqli("localhost", "root", "", "wanikani_offline");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}
$conn->set_charset("utf8mb4");

$subject_id = isset($_POST['subject_id']) ? intval($_POST['subject_id']) : 0;
$incorrect_meanings = isset($_POST['incorrect_meanings']) ? max(0, intval($_POST['incorrect_meanings'])) : 0;
$incorrect_readings = isset($_POST['incorrect_readings']) ? max(0, intval($_POST['incorrect_readings'])) : 0;

if (!$subject_id) {
    echo json_encode(['success' => false, 'error' => 'Missing subject_id']);
    exit;
}

// 1. Fetch Subject & Current Assignment Details
$stmt = $conn->prepare("
    SELECT a.id as assignment_id, a.srs_stage, a.passed_at, a.started_at, s.level, s.object_type
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.subject_id = ?
");
$stmt->bind_param("i", $subject_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Assignment not found']);
    exit;
}

$old_stage = intval($data['srs_stage']);
$passed_at = $data['passed_at'];
$started_at = $data['started_at'];
$subject_level = intval($data['level']);
$object_type = $data['object_type'];
$total_mistakes = $incorrect_meanings + $incorrect_readings;

$now_utc = gmdate('Y-m-d\TH:i:s\Z');
if (empty($started_at)) {
    $started_at = $now_utc;
}

// 2. Official WaniKani SRS Calculation
if ($total_mistakes === 0) {
    // Promotion (+1 Stage)
    $new_stage = min(9, $old_stage + 1);
} else {
    // Official Penalty Formula
    $incorrect_adj = ceil($total_mistakes / 2);
    $penalty_factor = ($old_stage >= 5) ? 2 : 1;
    $drop = $incorrect_adj * $penalty_factor;
    $new_stage = max(1, $old_stage - $drop);
}

// 3. Spaced Repetition Interval (Seconds)
$intervals = [
    1 => 4 * 3600,            // App 1 -> 4h
    2 => 8 * 3600,            // App 2 -> 8h
    3 => 23 * 3600,           // App 3 -> 23h
    4 => 47 * 3600,           // App 4 -> 47h
    5 => 167 * 3600,          // Guru 1 -> 1 week - 1h (167h)
    6 => 335 * 3600,          // Guru 2 -> 2 weeks - 1h (335h)
    7 => 719 * 3600,          // Master -> 1 month - 1h (719h)
    8 => 2879 * 3600,         // Enlightened -> 4 months - 1h (2879h)
    9 => null                 // Burned -> Never
];

// Accelerated Apprentice intervals for Levels 1 & 2
if ($subject_level <= 2) {
    $intervals[1] = 2 * 3600;
    $intervals[2] = 4 * 3600;
    $intervals[3] = 8 * 3600;
    $intervals[4] = 23 * 3600;
}

$available_at = null;
if ($new_stage < 9 && isset($intervals[$new_stage])) {
    $available_at = gmdate('Y-m-d\TH:i:s\Z', time() + $intervals[$new_stage]);
}

// Check Guru threshold
$just_passed = false;
if ($new_stage >= 5 && empty($passed_at)) {
    $passed_at = $now_utc;
    $just_passed = true;
}

// 4. Update Assignment Table
$update_stmt = $conn->prepare("
    UPDATE assignments 
    SET srs_stage = ?, available_at = ?, passed_at = ?, started_at = ?
    WHERE subject_id = ?
");
$update_stmt->bind_param("isssi", $new_stage, $available_at, $passed_at, $started_at, $subject_id);
$update_stmt->execute();

// 5. Log Review Record (For recent mistakes & stats tracking)
$rev_stmt = $conn->prepare("
    INSERT INTO reviews (id, assignment_id, subject_id, created_at, incorrect_meaning_answers, incorrect_reading_answers)
    VALUES (NULL, ?, ?, ?, ?, ?)
");
$rev_stmt->bind_param("iisii", $data['assignment_id'], $subject_id, $now_utc, $incorrect_meanings, $incorrect_readings);
$rev_stmt->execute();

// 6. Check Progression Cascades on Passing (Guru I)
$unlocked_new_items = [];
if ($just_passed && $object_type === 'radical') {
    // Check if any Kanji have all component radicals Guru'd
    $kanji_res = $conn->query("
        SELECT s.id, s.component_subject_ids 
        FROM subjects s 
        LEFT JOIN assignments a ON s.id = a.subject_id 
        WHERE s.object_type = 'kanji' AND s.level = $subject_level AND (a.unlocked_at IS NULL OR a.id IS NULL)
    ");

    while ($k = $kanji_res->fetch_assoc()) {
        $components = json_decode($k['component_subject_ids'], true) ?: [];
        if (!empty($components)) {
            $comp_ids = implode(',', array_map('intval', $components));
            $check_res = $conn->query("
                SELECT COUNT(*) as cnt 
                FROM assignments 
                WHERE subject_id IN ($comp_ids) AND srs_stage >= 5
            ");
            $passed_components = $check_res ? intval($check_res->fetch_assoc()['cnt']) : 0;

            if ($passed_components === count($components)) {
                // All radical components Guru'd -> Unlock Kanji in Lessons
                $conn->query("
                    INSERT INTO assignments (subject_id, srs_stage, unlocked_at) 
                    VALUES ({$k['id']}, 0, '$now_utc')
                    ON DUPLICATE KEY UPDATE unlocked_at = '$now_utc'
                ");
                $unlocked_new_items[] = $k['id'];
            }
        }
    }
}

echo json_encode([
    'success' => true,
    'subject_id' => $subject_id,
    'old_stage' => $old_stage,
    'new_stage' => $new_stage,
    'available_at' => $available_at,
    'passed_at' => $passed_at,
    'unlocked_items' => $unlocked_new_items
]);