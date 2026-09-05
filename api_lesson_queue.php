<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

$conn = new mysqli("localhost", "root", "", "wanikani_offline");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}
$conn->set_charset("utf8mb4");

// 1. Fetch remaining lesson queue count by type
$count_res = $conn->query("
    SELECT 
        CASE 
            WHEN s.object_type IN ('vocabulary', 'kana_vocabulary') THEN 'vocabulary'
            ELSE s.object_type 
        END AS cat,
        COUNT(*) as cnt
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.srs_stage = 0 AND a.unlocked_at IS NOT NULL
    GROUP BY cat
");

$counts = ['radical' => 0, 'kanji' => 0, 'vocabulary' => 0];
if ($count_res) {
    while ($r = $count_res->fetch_assoc()) {
        if (isset($counts[$r['cat']])) {
            $counts[$r['cat']] = intval($r['cnt']);
        }
    }
}

// 2. Fetch all currently unlocked lesson candidates
$query = "
    SELECT 
        s.id,
        s.object_type,
        s.level,
        s.lesson_position,
        s.characters,
        s.character_image_url,
        s.meanings,
        s.readings,
        s.meaning_mnemonic,
        s.reading_mnemonic,
        s.component_subject_ids
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.srs_stage = 0 AND a.unlocked_at IS NOT NULL
    ORDER BY s.level ASC, s.lesson_position ASC, s.id ASC
";

$res = $conn->query($query);

$radicals = [];
$kanji = [];
$vocab = [];

while ($row = $res->fetch_assoc()) {
    $components = [];
    $comp_ids = json_decode($row['component_subject_ids'], true) ?: [];
    if (!empty($comp_ids)) {
        $id_list = implode(',', array_map('intval', $comp_ids));
        $comp_res = $conn->query("SELECT id, characters, character_image_url, meanings, object_type FROM subjects WHERE id IN ($id_list)");
        if ($comp_res) {
            while ($c = $comp_res->fetch_assoc()) {
                $c_meanings = json_decode($c['meanings'], true) ?: [];
                $components[] = [
                    'id' => $c['id'],
                    'characters' => $c['characters'],
                    'image_url' => $c['character_image_url'],
                    'meaning' => $c_meanings[0] ?? '',
                    'object_type' => $c['object_type']
                ];
            }
        }
    }

    $meanings = json_decode($row['meanings'], true) ?: [];
    $readings = json_decode($row['readings'], true) ?: [];

    $item = [
        'id' => intval($row['id']),
        'object_type' => $row['object_type'],
        'level' => intval($row['level']),
        'characters' => $row['characters'],
        'character_image_url' => $row['character_image_url'],
        'primary_meaning' => $meanings[0] ?? '',
        'all_meanings' => $meanings,
        'primary_reading' => $readings[0] ?? '',
        'all_readings' => $readings,
        'meaning_mnemonic' => $row['meaning_mnemonic'],
        'reading_mnemonic' => $row['reading_mnemonic'],
        'components' => $components
    ];

    if ($row['object_type'] === 'radical') {
        $radicals[] = $item;
    } elseif ($row['object_type'] === 'kanji') {
        $kanji[] = $item;
    } else {
        $vocab[] = $item;
    }
}

// 3. WaniKani Interleaving / Proportion Algorithm for 5-Item Batch
$batch = [];

// Interleave: Pull from vocabulary (prior-level backlog), Kanji, and Radicals proportionally
while (count($batch) < 5 && (!empty($vocab) || !empty($kanji) || !empty($radicals))) {
    if (!empty($vocab)) {
        $batch[] = array_shift($vocab);
        if (count($batch) >= 5) break;
    }
    if (!empty($kanji)) {
        $batch[] = array_shift($kanji);
        if (count($batch) >= 5) break;
    }
    if (!empty($vocab) && count($batch) < 4) {
        $batch[] = array_shift($vocab);
        if (count($batch) >= 5) break;
    }
    if (!empty($radicals)) {
        $batch[] = array_shift($radicals);
        if (count($batch) >= 5) break;
    }
    if (empty($vocab) && empty($kanji) && !empty($radicals)) {
        $batch[] = array_shift($radicals);
    }
}

echo json_encode([
    'success' => true,
    'counts' => $counts,
    'total_queue' => array_sum($counts),
    'batch' => $batch
]);