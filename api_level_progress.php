<?php
header('Content-Type: application/json');
$conn = new mysqli("localhost", "root", "", "wanikani_offline");
$conn->set_charset("utf8mb4");

$level = isset($_GET['level']) ? max(1, min(60, intval($_GET['level']))) : 1;

// Group vocabulary and kana_vocabulary together into 'vocabulary'
$query = "
    SELECT 
        CASE 
            WHEN s.object_type IN ('vocabulary', 'kana_vocabulary') THEN 'vocabulary'
            ELSE s.object_type 
        END AS category,
        COUNT(s.id) AS total,
        SUM(CASE WHEN a.passed_at IS NOT NULL THEN 1 ELSE 0 END) AS passed
    FROM subjects s
    LEFT JOIN assignments a ON s.id = a.subject_id
    WHERE s.level = ?
    GROUP BY category
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $level);
$stmt->execute();
$res = $stmt->get_result();

$data = [
    'radical'    => ['gurud' => 0, 'total' => 0],
    'kanji'      => ['gurud' => 0, 'total' => 0],
    'vocabulary' => ['gurud' => 0, 'total' => 0]
];

while ($row = $res->fetch_assoc()) {
    $cat = $row['category'];
    if (isset($data[$cat])) {
        $data[$cat] = [
            'gurud' => intval($row['passed']),
            'total' => intval($row['total'])
        ];
    }
}

// 90% Rule for Kanji
$total_kanji = $data['kanji']['total'];
$gurud_kanji = $data['kanji']['gurud'];
$required_kanji = ceil($total_kanji * 0.90);
$kanji_needed = max(0, $required_kanji - $gurud_kanji);

echo json_encode([
    'level'        => $level,
    'progress'     => $data,
    'kanji_needed' => $kanji_needed,
    'total_kanji'  => $total_kanji,
    'gurud_kanji'  => $gurud_kanji
]);