<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$api_key = "8cf02422-c860-4195-940d-d74a1649efe7";

// ----------------------------------------------------
// 1. FETCH LOCAL MYSQL DATA
// ----------------------------------------------------
$conn = new mysqli("localhost", "root", "", "wanikani_offline");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Local Active Level
$lvl_res = $conn->query("
    SELECT MAX(s.level) as current_level 
    FROM assignments a 
    JOIN subjects s ON a.subject_id = s.id 
    WHERE a.unlocked_at IS NOT NULL
");
$local_level = ($row = $lvl_res->fetch_assoc()) && $row['current_level'] ? intval($row['current_level']) : 1;

$level = isset($_GET['level']) ? max(1, min(60, intval($_GET['level']))) : $local_level;

// Local Progress Breakdown
$local_prog_query = "
    SELECT 
        CASE 
            WHEN s.object_type IN ('vocabulary', 'kana_vocabulary') THEN 'vocabulary'
            ELSE s.object_type 
        END AS cat,
        COUNT(s.id) AS total,
        SUM(CASE WHEN a.passed_at IS NOT NULL OR a.srs_stage >= 5 THEN 1 ELSE 0 END) AS gurud
    FROM subjects s
    LEFT JOIN assignments a ON s.id = a.subject_id
    WHERE s.level = $level
    GROUP BY cat
";
$local_prog_res = $conn->query($local_prog_query);
$local_progress = [
    'radical'    => ['gurud' => 0, 'total' => 0],
    'kanji'      => ['gurud' => 0, 'total' => 0],
    'vocabulary' => ['gurud' => 0, 'total' => 0]
];
while ($r = $local_prog_res->fetch_assoc()) {
    $cat = $r['cat'];
    if (isset($local_progress[$cat])) {
        $local_progress[$cat] = ['gurud' => intval($r['gurud']), 'total' => intval($r['total'])];
    }
}

// Local SRS Spread Matrix (Stages 1–8)
$local_spread_res = $conn->query("
    SELECT srs_stage, COUNT(*) as cnt 
    FROM assignments 
    WHERE srs_stage BETWEEN 1 AND 8 
    GROUP BY srs_stage
");
$local_srs = array_fill(1, 8, 0);
while ($r = $local_spread_res->fetch_assoc()) {
    $local_srs[intval($r['srs_stage'])] = intval($r['cnt']);
}

// Local Queue
$now_iso = gmdate('Y-m-d\TH:i:s\Z');
$local_reviews_due = $conn->query("
    SELECT COUNT(*) as cnt 
    FROM assignments 
    WHERE srs_stage > 0 AND srs_stage < 9 
      AND (available_at <= '$now_iso' OR available_at IS NULL)
")->fetch_assoc()['cnt'];

$local_lessons_due = $conn->query("
    SELECT COUNT(*) as cnt 
    FROM assignments 
    WHERE srs_stage = 0 AND unlocked_at IS NOT NULL
")->fetch_assoc()['cnt'];


// ----------------------------------------------------
// 2. FETCH LIVE WANIKANI API DATA
// ----------------------------------------------------
function fetch_wk_api($endpoint, $key) {
    $ch = curl_init("https://api.wanikani.com/v2/" . $endpoint);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $key",
        "Wanikani-Revision: 20170710"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        return json_decode($response, true);
    }
    return null;
}

// Live Summary
$live_summary = fetch_wk_api("summary", $api_key);
$live_reviews_due = 0;
$live_lessons_due = 0;

if ($live_summary && isset($live_summary['data'])) {
    $reviews_data = $live_summary['data']['reviews'][0] ?? null;
    if ($reviews_data) {
        $live_reviews_due = count($reviews_data['subject_ids']);
    }
    $lessons_data = $live_summary['data']['lessons'][0] ?? null;
    if ($lessons_data) {
        $live_lessons_due = count($lessons_data['subject_ids']);
    }
}

// Live Level Progression endpoint
$live_level_prog = fetch_wk_api("level_progressions", $api_key);
$live_active_level = $local_level;
if ($live_level_prog && isset($live_level_prog['data'])) {
    foreach ($live_level_prog['data'] as $lp) {
        if (empty($lp['data']['abandoned_at'])) {
            $live_active_level = max($live_active_level, $lp['data']['level']);
        }
    }
}

// Live Assignments for current level calculation & SRS stage breakdown
$live_assignments = fetch_wk_api("assignments?levels=$level", $api_key);
$live_subjects = fetch_wk_api("subjects?levels=$level&hidden=false", $api_key);

$live_progress = [
    'radical'    => ['gurud' => 0, 'total' => 0],
    'kanji'      => ['gurud' => 0, 'total' => 0],
    'vocabulary' => ['gurud' => 0, 'total' => 0]
];

// Map live subjects by type
if ($live_subjects && isset($live_subjects['data'])) {
    foreach ($live_subjects['data'] as $s) {
        $type = $s['object'];
        $cat = in_array($type, ['vocabulary', 'kana_vocabulary']) ? 'vocabulary' : $type;
        if (isset($live_progress[$cat])) {
            $live_progress[$cat]['total']++;
        }
    }
}

// Map live assignments passed
if ($live_assignments && isset($live_assignments['data'])) {
    foreach ($live_assignments['data'] as $a) {
        $d = $a['data'];
        $type = $d['subject_type'];
        $cat = in_array($type, ['vocabulary', 'kana_vocabulary']) ? 'vocabulary' : $type;
        if (!empty($d['passed_at']) || $d['srs_stage'] >= 5) {
            if (isset($live_progress[$cat])) {
                $live_progress[$cat]['gurud']++;
            }
        }
    }
}

// Live SRS distribution (Query all in-progress assignments)
$all_live_in_progress = fetch_wk_api("assignments?srs_stages=1,2,3,4,5,6,7,8", $api_key);
$live_srs = array_fill(1, 8, 0);
if ($all_live_in_progress && isset($all_live_in_progress['data'])) {
    foreach ($all_live_in_progress['data'] as $a) {
        $stage = intval($a['data']['srs_stage']);
        if ($stage >= 1 && $stage <= 8) {
            $live_srs[$stage]++;
        }
    }
}

$stage_names = [
    1 => 'Apprentice I',
    2 => 'Apprentice II',
    3 => 'Apprentice III',
    4 => 'Apprentice IV',
    5 => 'Guru I',
    6 => 'Guru II',
    7 => 'Master',
    8 => 'Enlightened'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WaniKani Live API vs. Offline DB Comparator</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f7f6; color: #333; margin: 0; padding: 2rem; }
        .container { max-width: 1100px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .btn { background: #fff; border: 1px solid #cbd5e1; padding: 8px 16px; border-radius: 8px; font-weight: 700; text-decoration: none; color: #333; }
        .btn:hover { background: #f8fafc; }

        .level-picker { display: flex; gap: 8px; align-items: center; margin-bottom: 1.5rem; }
        .level-picker select { padding: 6px 12px; font-size: 1rem; border-radius: 6px; border: 1px solid #cbd5e1; font-weight: 700; }

        .card { background: #fff; border-radius: 12px; border: 1px solid #e5e9eb; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 6px rgba(0,0,0,0.02); }
        .section-title { font-size: 1.15rem; font-weight: 800; margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 14px; text-align: left; border-bottom: 1px solid #f0f3f5; font-size: 0.95rem; }
        th { background: #f8fafc; font-weight: 700; color: #64748b; font-size: 0.85rem; text-transform: uppercase; }

        .match-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 800; }
        .badge-perfect { background: #dcfce7; color: #15803d; }
        .badge-diff { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div>
            <h1 style="font-size: 1.8rem; font-weight: 800;">WaniKani Live API vs Offline Comparator</h1>
            <div style="color: #666; font-size: 0.9rem; margin-top: 4px;">Live Verification against api.wanikani.com/v2</div>
        </div>
        <div>
            <a href="index.php" class="btn">&larr; Return to Dashboard</a>
        </div>
    </div>

    <form method="GET" class="level-picker">
        <label for="lvl" style="font-weight: 700;">Inspect Level:</label>
        <select name="level" id="lvl" onchange="this.form.submit()">
            <?php for ($i = 1; $i <= 60; $i++): ?>
                <option value="<?= $i ?>" <?= $i === $level ? 'selected' : '' ?>>Level <?= $i ?></option>
            <?php endfor; ?>
        </select>
        <span style="color: #888; font-size: 0.85rem; margin-left: 8px;">(Active Level: Live <strong><?= $live_active_level ?></strong> | Local <strong><?= $local_level ?></strong>)</span>
    </form>

    <!-- 1. Level Progression Comparison -->
    <div class="card">
        <div class="section-title">
            <span>Level <?= $level ?> Progress Breakdown</span>
        </div>
        <table>
            <thead>
            <tr>
                <th>Category</th>
                <th>Live API (Passed / Total)</th>
                <th>Local Offline DB (Guru'd / Total)</th>
                <th>Integrity Match</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach (['radical' => 'Radicals', 'kanji' => 'Kanji', 'vocabulary' => 'Vocabulary'] as $cat_key => $label):
                $live_str = "{$live_progress[$cat_key]['gurud']} / {$live_progress[$cat_key]['total']}";
                $local_str = "{$local_progress[$cat_key]['gurud']} / {$local_progress[$cat_key]['total']}";
                $matches = ($live_str === $local_str);
                ?>
                <tr>
                    <td><strong><?= $label ?></strong></td>
                    <td><?= $live_str ?></td>
                    <td><?= $local_str ?></td>
                    <td>
                            <span class="match-badge <?= $matches ? 'badge-perfect' : 'badge-diff' ?>">
                                <?= $matches ? '✓ 100% MATCH' : '⚠ DIFFERENCE DETECTED' ?>
                            </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- 2. Active Queue Comparison -->
    <div class="card">
        <div class="section-title">
            <span>Live Action Queues</span>
        </div>
        <table>
            <thead>
            <tr>
                <th>Action</th>
                <th>Live API Count</th>
                <th>Local DB Count</th>
                <th>Match</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td><strong>Reviews Due Now</strong></td>
                <td><?= $live_reviews_due ?></td>
                <td><?= $local_reviews_due ?></td>
                <td>
                        <span class="match-badge <?= ($live_reviews_due === intval($local_reviews_due)) ? 'badge-perfect' : 'badge-diff' ?>">
                            <?= ($live_reviews_due === intval($local_reviews_due)) ? '✓ MATCH' : 'DIFF' ?>
                        </span>
                </td>
            </tr>
            <tr>
                <td><strong>Available Lessons</strong></td>
                <td><?= $live_lessons_due ?></td>
                <td><?= $local_lessons_due ?></td>
                <td>
                        <span class="match-badge <?= ($live_lessons_due === intval($local_lessons_due)) ? 'badge-perfect' : 'badge-diff' ?>">
                            <?= ($live_lessons_due === intval($local_lessons_due)) ? '✓ MATCH' : 'DIFF' ?>
                        </span>
                </td>
            </tr>
            </tbody>
        </table>
    </div>

    <!-- 3. Active SRS Spread Matrix -->
    <div class="card">
        <div class="section-title">
            <span>Spaced Repetition Stages (Item Spread 1–8)</span>
        </div>
        <table>
            <thead>
            <tr>
                <th>SRS Stage</th>
                <th>Live API Count</th>
                <th>Local Offline Count</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php for ($s = 1; $s <= 8; $s++):
                $is_same = ($live_srs[$s] === $local_srs[$s]);
                ?>
                <tr>
                    <td><strong><?= $stage_names[$s] ?> (Stage <?= $s ?>)</strong></td>
                    <td><?= $live_srs[$s] ?> items</td>
                    <td><?= $local_srs[$s] ?> items</td>
                    <td>
                            <span class="match-badge <?= $is_same ? 'badge-perfect' : 'badge-diff' ?>">
                                <?= $is_same ? '✓ MATCH' : 'DIFF' ?>
                            </span>
                    </td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>