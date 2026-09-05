<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = new mysqli("localhost", "root", "", "wanikani_offline");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Fast Test Action Controls
$action_msg = "";
if (isset($_POST['action'])) {
    if ($_POST['action'] === 'make_all_due') {
        $now_iso = gmdate('Y-m-d\TH:i:s\Z');
        $conn->query("UPDATE assignments SET available_at = '$now_iso' WHERE srs_stage BETWEEN 1 AND 8 AND started_at IS NOT NULL");
        $action_msg = "All in-progress items set to DUE NOW!";
    } elseif ($_POST['action'] === 'make_batch_due' && !empty($_POST['target_batch'])) {
        $tb = $conn->real_escape_string($_POST['target_batch']);
        $now_iso = gmdate('Y-m-d\TH:i:s\Z');
        $conn->query("UPDATE assignments SET available_at = '$now_iso' WHERE available_at = '$tb'");
        $action_msg = "Selected batch set to DUE NOW!";
    }
}

// Fetch all started active assignments (Stages 1-8)
$query = "
    SELECT 
        s.id,
        s.object_type,
        s.characters,
        s.character_image_url,
        s.meanings,
        s.readings,
        s.level,
        a.srs_stage,
        a.available_at
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.srs_stage BETWEEN 1 AND 8 
      AND a.started_at IS NOT NULL
      AND a.available_at IS NOT NULL
    ORDER BY a.available_at ASC, s.id ASC
";

$res = $conn->query($query);
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

while ($row = $res->fetch_assoc()) {
    $total_items++;
    $avail_str = $row['available_at'];
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
            'diff_seconds' => $diff,
            'items' => []
        ];
    }

    $meanings = json_decode($row['meanings'], true) ?: [];
    $readings = json_decode($row['readings'], true) ?: [];

    $batches[$batch_key]['items'][] = [
        'id' => $row['id'],
        'type' => $row['object_type'],
        'characters' => $row['characters'],
        'image_url' => $row['character_image_url'],
        'meaning' => $meanings[0] ?? '—',
        'reading' => $readings[0] ?? '',
        'level' => $row['level'],
        'srs_stage' => intval($row['srs_stage']),
        'stage_name' => $stage_names[intval($row['srs_stage'])],
        'available_at' => $avail_str
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WaniKani Offline - Upcoming Reviews</title>
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
        .btn { background: #fff; border: 1px solid #cbd5e1; padding: 8px 16px; border-radius: 8px; font-weight: 700; text-decoration: none; color: #333; display: inline-flex; align-items: center; gap: 6px; }
        .btn:hover { background: #f8fafc; }
        .btn-green { background: #22c55e; color: #fff; border: none; cursor: pointer; }
        .btn-green:hover { background: #16a34a; }

        .summary-banner {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            display: flex;
            justify-content: space-between;
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
            transition: transform 0.1s, border-color 0.1s;
        }
        .item-card:hover { transform: translateY(-2px); border-color: #cbd5e1; }

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
            <h1 style="font-size: 1.7rem; font-weight: 800;">Upcoming Reviews (Offline Database)</h1>
            <div style="color: #64748b; font-size: 0.9rem; margin-top: 2px;">Chronological review timeline from local MySQL</div>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="live_upcoming.php" class="btn">⚡ Switch to Live API View</a>
            <a href="index.php" class="btn">&larr; Dashboard</a>
        </div>
    </div>

    <?php if ($action_msg): ?>
        <div style="background:#dcfce7; color:#15803d; padding:10px 14px; border-radius:8px; margin-bottom:1.5rem; font-weight:700;">
            <?= htmlspecialchars($action_msg) ?>
        </div>
    <?php endif; ?>

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
            <form method="POST">
                <input type="hidden" name="action" value="make_all_due">
                <button type="submit" class="btn btn-green">⚡ Make ALL Due Now</button>
            </form>
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
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 0.8rem; color: #888; font-family: monospace;"><?= $b['raw_time'] ?></span>
                        <?php if (!$b['is_due']): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="make_batch_due">
                                <input type="hidden" name="target_batch" value="<?= htmlspecialchars($b['raw_time']) ?>">
                                <button type="submit" class="btn" style="padding: 4px 8px; font-size: 0.75rem;">⚡ Trigger Batch</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="items-grid">
                    <?php foreach ($b['items'] as $it): ?>
                        <a href="subject_detail.php?id=<?= $it['id'] ?>" class="item-card">
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
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

</body>
</html>