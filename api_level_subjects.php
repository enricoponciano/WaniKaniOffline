<?php
header('Content-Type: application/json');
$conn = new mysqli("localhost", "root", "", "wanikani_offline");
$conn->set_charset("utf8mb4");

$level = isset($_GET['level']) ? max(1, min(60, intval($_GET['level']))) : 1;
$type = isset($_GET['type']) ? $_GET['type'] : 'radical';

$type_filter = ($type === 'vocabulary')
    ? "s.object_type IN ('vocabulary', 'kana_vocabulary')"
    : "s.object_type = '" . $conn->real_escape_string($type) . "'";

// Tier 1: In Progress (srs_stage >= 1) -> status_group = 1
// Tier 2: Unlocked in Lessons (srs_stage = 0 AND unlocked_at IS NOT NULL) -> status_group = 2
// Tier 3: Locked (unlocked_at IS NULL) -> status_group = 3
$query = "
    SELECT 
        s.id,
        s.characters,
        s.character_image_url,
        s.meanings,
        s.readings,
        s.lesson_position,
        COALESCE(a.srs_stage, 0) AS srs_stage,
        a.unlocked_at,
        CASE WHEN a.passed_at IS NOT NULL THEN 1 ELSE 0 END AS passed,
        CASE 
            WHEN a.srs_stage > 0 OR a.passed_at IS NOT NULL THEN 1
            WHEN a.unlocked_at IS NOT NULL THEN 2
            ELSE 3
        END AS status_group
    FROM subjects s
    LEFT JOIN assignments a ON s.id = a.subject_id
    WHERE s.level = $level AND $type_filter
    ORDER BY status_group ASC, s.lesson_position ASC, s.id ASC
";

$res = $conn->query($query);
$items = [];
$gurud_count = 0;

while ($row = $res->fetch_assoc()) {
    if ($row['passed'] == 1 || $row['srs_stage'] >= 5) {
        $gurud_count++;
    }
    $items[] = [
        'id' => intval($row['id']),
        'characters' => $row['characters'],
        'character_image_url' => $row['character_image_url'],
        'meanings' => json_decode($row['meanings']),
        'srs_stage' => intval($row['srs_stage']),
        'unlocked' => !empty($row['unlocked_at']),
        'passed' => (bool)$row['passed'],
        'status_group' => intval($row['status_group'])
    ];
}

echo json_encode([
    'level' => $level,
    'type' => $type,
    'total' => count($items),
    'gurud' => $gurud_count,
    'items' => $items
]);