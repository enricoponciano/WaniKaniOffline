<?php
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
        curl_close($ch);
        $json = json_decode($res, true);
        $results = array_merge($results, $json['data'] ?? []);
        $url = $json['pages']['next_url'] ?? null;
    }
    return $results;
}

$conn = new mysqli("localhost", "root", "", "wanikani_offline");
$conn->set_charset("utf8mb4");

$live_assignments = fetch_all_live_assignments($api_key);
$now_ts = time();

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

$batches = [];
$total_items = 0;
$due_now_count = 0;

// Filter to started active SRS items and sort chronologically
$valid_items = [];
foreach ($live_assignments as $la) {
    $d = $la['data'];
    $srs = intval($d['srs_stage']);
    if ($srs >= 1 && $srs <= 8 && !empty($d['started_at']) && !empty($d['available_at'])) {
        $valid_items[] = [
            'subject_id' => $d['subject_id'],
            'srs_stage' => $srs,
            'available_at' => $d['available_at']
        ];
    }
}

usort($valid_items, function($a, $b) {
    $cmp = strcmp($a['available_at'], $b['available_at']);
    if ($cmp === 0) return $a['subject_id'] <=> $b['subject_id'];
    return $cmp;
});

foreach ($valid_items as $item) {
    $total_items++;
    $avail_str = $item['available_at'];
    $avail_ts = strtotime($avail_str);
    $diff = $avail_ts - $now_ts;

    $is_due = ($diff <= 0);
    if ($is_due) {
        $due_now_count++;
        $batch_key = "DUE_NOW";
        $header_label = "Available Right Now";
    } else {
        $h = floor($diff / 3600);
        $m = floor(($diff % 3600) / 60);
        $batch_key = $avail_str;
        $header_label = "Incoming Reviews in {$h}h {$m}m";
    }

    if (!isset($batches[$batch_key])) {
        $batches[$batch_key] = [
            'raw_time' => $avail_str,
            'is_due' => $is_due,
            'header_label' => $header_label,
            'items' => []
        ];
    }

    $sub_id = $item['subject_id'];
    $sub_res = $conn->query("SELECT characters, character_image_url, meanings, readings, object_type, level FROM subjects WHERE id = $sub_id");
    $sub = $sub_res ? $sub_res->fetch_assoc() : null;

    $meanings = $sub ? json_decode($sub['meanings'], true) : [];
    $readings = $sub ? json_decode($sub['readings'], true) : [];

    $batches[$batch_key]['items'][] = [
        'id' => $sub_id,
        'type' => $sub['object_type'] ?? 'kanji',
        'characters' => $sub['characters'] ?? '—',
        'image_url' => $sub['character_image_url'] ?? null,
        'meaning' => $meanings[0] ?? '—',
        'reading' => $readings[0] ?? '',
        'level' => $sub['level'] ?? 1,
        'srs_stage' => $item['srs_stage'],
        'stage_name' => $stage_names[$item['srs_stage']]
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WaniKani Live API - Upcoming Reviews</title>
    <style>
        :root {
            --radical: #00aaff;
            --kanji: #f100a1;
            --vocab: #aa00ff;
            --bg: #f4f7f6;
            --card: #ffffff;
            --border: #e5e9eb;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: #333; padding: 2rem 1rem; }
        .container { max-width: 1050px; margin: 0 auto; }

        .header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .btn { background: #fff; border: 1px solid #cbd5e1; padding: 8px 16px; border-radius: 8px; font-weight: 700; text-decoration: none; color: #333; }
        .btn:hover { background: #f8fafc; }

        .summary-banner {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            display: flex;
            justify-content: space-around;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .batch-section {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }

        .batch-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 0.75rem;
            margin-bottom: 1.25rem;
        }

        .batch-title {
            font-size: 1.15rem;
            font-weight: 800;
            color: #1e293b;
        }

        .batch-count-badge {
            background: #e2e8f0;
            color: #475569;
            font-size: 0.85rem;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 800;
            margin-left: 8px;
        }

        .items-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: stretch;
        }

        .item-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 6px 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: inherit;
        }

        .item-tile {
            min-width: 38px;
            height: 38px;
            padding: 0 10px;
            border-radius: 6px;
            color: #fff;
            font-size: 1.2rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: "Hiragino Kaku Gothic Pro", "Yu Gothic", sans-serif;
            white-space: nowrap;
        }
        .tile-radical { background: var(--radical); }
        .tile-kanji { background: var(--kanji); }
        .tile-vocabulary, .tile-kana_vocabulary { background: var(--vocab); }
        .chip-svg { width: 20px; height: 20px; filter: brightness(0) invert(1); }

        .item-meta { display: flex; flex-direction: column; }
        .item-meaning { font-weight: 700; font-size: 0.9rem; color: #1e293b; }
        .item-sub { font-size: 0.75rem; color: #64748b; font-weight: 600; }
        .stage-pill { font-size: 0.7rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; }
    </style>
</head>
<body>

<div class="container">
    <div class="header-bar">
        <div>
            <h1 style="font-size: 1.7rem; font-weight: 800;">Upcoming Reviews (Live API Feed)</h1>
            <div style="color: #64748b; font-size: 0.9rem; margin-top: 2px;">Real-time feed directly from <code>api.wanikani.com/v2</code></div>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="upcoming_reviews.php" class="btn">⚡ Switch to Offline DB View</a>
            <a href="index.php" class="btn">&larr; Dashboard</a>
        </div>
    </div>

    <div class="summary-banner">
        <div>
            <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Due Now</div>
            <div style="font-size: 1.8rem; font-weight: 800; color: #22c55e;"><?= $due_now_count ?></div>
        </div>
        <div>
            <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Total In Spaced Repetition</div>
            <div style="font-size: 1.8rem; font-weight: 800; color: #334155;"><?= $total_items ?></div>
        </div>
        <div>
            <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Server UTC Time</div>
            <div style="font-size: 1.1rem; font-weight: 700; color: #334155; margin-top: 4px;"><?= gmdate('H:i:s') ?> UTC</div>
        </div>
    </div>

    <?php if (empty($batches)): ?>
        <div class="batch-section" style="text-align: center; color: #888; padding: 3rem;">
            No items currently scheduled for review.
        </div>
    <?php else: ?>
        <?php foreach ($batches as $b):
            $count = count($b['items']);
            ?>
            <div class="batch-section">
                <div class="batch-header">
                    <div class="batch-title">
                        <?= $b['header_label'] ?>
                        <span class="batch-count-badge"><?= $count ?> item<?= $count === 1 ? '' : 's' ?></span>
                    </div>
                    <span style="font-size: 0.8rem; color: #888; font-family: monospace;"><?= $b['raw_time'] ?></span>
                </div>

                <div class="items-grid">
                    <?php foreach ($b['items'] as $it): ?>
                        <div class="item-card">
                            <div class="item-tile tile-<?= htmlspecialchars($it['type']) ?>">
                                <?php if ($it['characters']): ?>
                                    <?= htmlspecialchars($it['characters']) ?>
                                <?php elseif ($it['image_url']): ?>
                                    <img src="<?= htmlspecialchars($it['image_url']) ?>" class="chip-svg" alt="radical">
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </div>
                            <div class="item-meta">
                                <div class="item-meaning"><?= htmlspecialchars($it['meaning']) ?></div>
                                <div class="item-sub">
                                    <?php if ($it['reading']): ?><span><?= htmlspecialchars($it['reading']) ?> &bull; </span><?php endif; ?>
                                    <span class="stage-pill"><?= $it['stage_name'] ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

</body>
</html>