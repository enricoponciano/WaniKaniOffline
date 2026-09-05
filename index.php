<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set local timezone for midnight resets
date_default_timezone_set('Asia/Manila');

$conn = new mysqli("localhost", "root", "", "wanikani_offline");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// 1. Current Active User Level
$lvl_res = $conn->query("
    SELECT MAX(s.level) as current_level 
    FROM assignments a 
    JOIN subjects s ON a.subject_id = s.id 
    WHERE a.unlocked_at IS NOT NULL
");
$current_level = ($row = $lvl_res->fetch_assoc()) && $row['current_level'] ? intval($row['current_level']) : 1;

// 2. Count Lessons & Daily Quota (Resetting at Midnight / 12:00 AM)
$today_start = date('Y-m-d 00:00:00');
$today_end   = date('Y-m-d 23:59:59');

$today_start_utc = gmdate('Y-m-d\TH:i:s\Z', strtotime($today_start));
$today_end_utc   = gmdate('Y-m-d\TH:i:s\Z', strtotime($today_end));

$done_today_res = $conn->query("
    SELECT COUNT(*) as cnt 
    FROM assignments 
    WHERE started_at >= '$today_start_utc' AND started_at <= '$today_end_utc'
");
$lessons_done_today = $done_today_res ? intval($done_today_res->fetch_assoc()['cnt']) : 0;

$lessons_res = $conn->query("
    SELECT COUNT(*) as cnt 
    FROM assignments 
    WHERE srs_stage = 0 AND unlocked_at IS NOT NULL
");
$total_lessons_available = $lessons_res ? intval($lessons_res->fetch_assoc()['cnt']) : 0;

$daily_lesson_target = 15;
$daily_lessons_completed = ($lessons_done_today >= $daily_lesson_target);
$lessons_remaining_today = max(0, $daily_lesson_target - $lessons_done_today);

// 3. Count Due Reviews
$now_iso = gmdate('Y-m-d\TH:i:s\Z');
$reviews_res = $conn->query("
    SELECT COUNT(*) as cnt 
    FROM assignments 
    WHERE srs_stage > 0 AND srs_stage < 9 
      AND (available_at <= '$now_iso' OR available_at IS NULL)
");
$review_count = $reviews_res ? intval($reviews_res->fetch_assoc()['cnt']) : 0;

// 4. Active SRS Distribution (Stages 1 through 8)
$srs_spread_res = $conn->query("
    SELECT a.srs_stage, 
           CASE 
               WHEN s.object_type IN ('vocabulary', 'kana_vocabulary') THEN 'vocabulary'
               ELSE s.object_type 
           END AS cat,
           COUNT(*) as cnt
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.srs_stage BETWEEN 1 AND 8 AND a.started_at IS NOT NULL
    GROUP BY a.srs_stage, cat
");

$srs_matrix = array_fill(1, 8, ['radical' => 0, 'kanji' => 0, 'vocabulary' => 0]);
if ($srs_spread_res) {
    while ($r = $srs_spread_res->fetch_assoc()) {
        $stage = intval($r['srs_stage']);
        $cat = $r['cat'];
        if (isset($srs_matrix[$stage][$cat])) {
            $srs_matrix[$stage][$cat] = intval($r['cnt']);
        }
    }
}

// 5. Recent Mistakes Query
$mistakes_res = $conn->query("
    SELECT s.id, s.characters, s.character_image_url, s.object_type
    FROM reviews r
    JOIN subjects s ON r.subject_id = s.id
    WHERE (r.incorrect_meaning_answers > 0 OR r.incorrect_reading_answers > 0)
    ORDER BY r.created_at DESC
    LIMIT 12
");

$mistakes = $mistakes_res ? $mistakes_res->fetch_all(MYSQLI_ASSOC) : [];
$mistakes_count = count($mistakes);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WaniKani Offline</title>
    <style>
        :root {
            --bg-color: #f4f7f6;
            --card-bg: #ffffff;
            --text-dark: #333333;
            --text-muted: #888888;
            --border-color: #e5e9eb;
            --radical-color: #00aaff;
            --kanji-color: #f100a1;
            --vocab-color: #aa00ff;
            --wk-green: #00cc66;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg-color);
            color: var(--text-dark);
        }

        nav {
            background: #fff;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 4rem;
            border-bottom: 1px solid var(--border-color);
        }
        .logo {
            font-size: 1.4rem;
            font-weight: 900;
            color: #f100a1;
            letter-spacing: -0.5px;
            text-decoration: none;
        }
        .nav-links {
            display: flex;
            gap: 2rem;
            list-style: none;
            font-size: 0.95rem;
            font-weight: 600;
            color: #555;
            align-items: center;
        }
        .nav-links a { text-decoration: none; color: inherit; }
        .avatar { width: 34px; height: 34px; border-radius: 50%; background: #e0e0e0; display: inline-block; }

        .container {
            max-width: 1180px;
            margin: 2rem auto;
            padding: 0 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        .grid-3 { display: grid; grid-template-columns: 1.15fr 1.15fr 1.7fr; gap: 1.5rem; }

        .card {
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            padding: 1.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
        }

        .action-card { display: flex; align-items: flex-start; gap: 1.25rem; }
        .action-img-wrapper { flex-shrink: 0; }

        .action-circle-icon {
            width: 84px;
            height: 84px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
        }

        .icon-lessons-active { border: 3px solid #ffcc00; background: #fffbe6; }
        .icon-lessons-done {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            background: #ffcc00;
            border: 4px solid #000;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .icon-reviews-active { border: 3px solid #00aaff; background: #e6f7ff; }
        .icon-reviews-empty { border: 3px solid #818cf8; background: #f5f3ff; }

        .action-info { display: flex; flex-direction: column; flex-grow: 1; }
        .action-header-line { display: flex; align-items: center; gap: 8px; }
        .action-title { font-size: 1.35rem; font-weight: 800; }
        .badge {
            background: #e2e8f0;
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 700;
            color: #64748b;
        }
        .action-desc {
            font-size: 0.85rem;
            color: #718096;
            margin-top: 0.35rem;
            line-height: 1.35;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 0.75rem;
            padding: 6px 14px;
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.85rem;
            text-decoration: none;
            color: #333;
            width: fit-content;
            transition: all 0.15s;
        }
        .btn-action:hover {
            border-color: #999;
            background: #fdfdfd;
        }

        .forecast-row { display: flex; justify-content: space-between; align-items: center; margin-top: 0.6rem; font-size: 0.9rem; font-weight: 600; }
        .bar-container { flex-grow: 1; height: 10px; background: #eee; border-radius: 5px; margin: 0 1rem; overflow: hidden; }
        .bar-fill { height: 100%; background: #22c55e; border-radius: 5px; }

        .stat-huge { font-size: 2.4rem; font-weight: 800; line-height: 1; }
        .stat-label { font-size: 0.85rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; margin-bottom: 0.25rem; }
        .streak-icons { display: flex; gap: 6px; margin-top: 0.5rem; }
        .streak-badge { width: 32px; height: 32px; border-radius: 6px; background: #e2fbe8; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; }

        .mistakes-title-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ninja-icon {
            width: 22px;
            height: 22px;
            background: #2d3748;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            color: #fff;
        }
        .mistakes-controls {
            display: flex;
            gap: 12px;
            margin: 0.9rem 0 1rem 0;
        }
        .btn-mistakes {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 14px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: #fff;
            text-decoration: none;
            color: #2d3748;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
            transition: border-color 0.15s, background 0.15s;
        }
        .btn-mistakes:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
        }

        .chip-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .chip {
            padding: 6px 14px;
            border-radius: 6px;
            color: #fff;
            font-size: 1.15rem;
            font-weight: 500;
            font-family: "Hiragino Kaku Gothic Pro", "Yu Gothic", "Meiryo", sans-serif;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 38px;
            box-sizing: border-box;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            transition: transform 0.1s;
        }
        .chip:hover { transform: scale(1.04); }
        .chip.radical { background: var(--radical-color); }
        .chip.kanji { background: var(--kanji-color); }
        .chip.vocabulary, .chip.kana_vocabulary { background: var(--vocab-color); }
        .chip-svg { width: 1.2rem; height: 1.2rem; filter: brightness(0) invert(1); }

        .lp-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem; }
        .lp-title { font-size: 1.15rem; font-weight: 800; color: #333; }
        .lp-nav { display: flex; align-items: center; gap: 12px; font-size: 1rem; font-weight: 700; color: #444; }
        .lp-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1.1rem;
            color: #777;
            padding: 2px 6px;
            border-radius: 4px;
            transition: background 0.15s;
        }
        .lp-btn:hover:not(:disabled) { background: #f0f0f0; color: #000; }
        .lp-btn:disabled { opacity: 0.25; cursor: not-allowed; }
        .lp-subtitle { font-size: 0.9rem; color: #666; margin-bottom: 1rem; }

        .lp-breakdown { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; margin-bottom: 1.25rem; }
        .lp-box {
            border: 1px solid #e1e7ec;
            border-radius: 10px;
            padding: 0.65rem 0.75rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
            user-select: none;
        }
        .lp-box:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.05); }
        .lp-box-top { display: flex; align-items: center; gap: 6px; margin-bottom: 0.75rem; }
        .lp-icon {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
        }
        .icon-radical { background: var(--radical-color); }
        .icon-kanji { background: var(--kanji-color); }
        .icon-vocab { background: var(--vocab-color); }
        .lp-box-label { font-size: 0.85rem; font-weight: 700; color: #555; }
        .lp-box-bottom { display: flex; justify-content: space-between; align-items: center; }
        .lp-fraction { font-size: 1rem; font-weight: 800; color: #222; }
        .lp-see-all { font-size: 0.75rem; font-weight: 700; color: #777; }

        .lp-levelup-text { font-size: 0.9rem; color: #444; margin-bottom: 0.6rem; }
        .lp-progress-bar { display: flex; gap: 3px; width: 100%; height: 8px; }
        .lp-segment { flex: 1; background: #e2e8f0; border-radius: 2px; height: 100%; }
        .lp-segment.filled { background: #f100a1; }

        .grid-view-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        .btn-back {
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.95rem;
            color: #555;
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 6px;
        }
        .btn-back:hover { background: #f0f0f0; }

        .subject-grid-wrapper {
            background: #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            max-height: 240px;
            overflow-y: auto;
        }

        .subject-grid-wrapper.layout-compact {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 12px 10px;
        }

        .subject-grid-wrapper.layout-vocab {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 8px;
            align-content: flex-start;
        }

        .item-wrapper {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 3px;
            cursor: pointer;
            text-decoration: none;
        }
        .layout-compact .item-wrapper { align-items: center; }
        .item-wrapper:hover .item-tile { transform: scale(1.04); }

        .item-tile {
            height: 44px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            font-family: "Hiragino Kaku Gothic Pro", "Yu Gothic", "Meiryo", sans-serif;
            font-weight: 500;
            transition: transform 0.1s;
            box-sizing: border-box;
            white-space: nowrap;
        }

        .layout-compact .item-tile { width: 44px; }
        .layout-vocab .item-tile { width: auto; min-width: 54px; padding: 0 12px; font-size: 1.25rem; }

        .item-svg { width: 24px; height: 24px; }
        .svg-white { filter: brightness(0) invert(1); }
        .svg-blue { filter: invert(33%) sepia(85%) saturate(1412%) hue-rotate(174deg) brightness(91%) contrast(101%); }

        .radical-learned { background: var(--radical-color); color: #fff; box-shadow: 0 2px 4px rgba(0,170,255,0.3); }
        .radical-unlocked { background: #dff2ff; color: #0077aa; border: 1px solid #00aaff; }
        .radical-locked { background: #eef7fc; color: #0077aa; border: 1.5px dashed #00aaff; }

        .kanji-learned { background: var(--kanji-color); color: #fff; box-shadow: 0 2px 4px rgba(241,0,161,0.3); }
        .kanji-unlocked { background: #ffd6f4; color: #a80077; border: 1px solid #f100a1; }
        .kanji-locked { background: #f8e5f3; color: #b80082; border: 1.5px dashed #e6009a; }

        .vocab-learned { background: var(--vocab-color); color: #fff; box-shadow: 0 2px 4px rgba(170,0,255,0.3); }
        .vocab-unlocked { background: #eed0ff; color: #7700b3; border: 1px solid #aa00ff; }
        .vocab-locked { background: #f6e6ff; color: #8800cc; border: 1.5px dashed #aa00ff; }

        .item-subtext {
            font-size: 0.65rem;
            color: #718096;
            font-weight: 600;
            line-height: 1;
            height: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .ticks-container { display: flex; gap: 2px; width: 100%; max-width: 100%; height: 3px; }
        .layout-compact .ticks-container { width: 42px; }
        .tick { flex: 1; background: #d1d5db; border-radius: 1px; height: 100%; }
        .tick.active { background: var(--wk-green); }

        .spread-chart {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            height: 140px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 4px;
            margin-top: 1rem;
        }
        .chart-col {
            display: flex;
            flex-direction: column-reverse;
            width: 28px;
            background: #f0f0f0;
            border-radius: 3px;
            overflow: hidden;
        }
        .col-radical { background: var(--radical-color); }
        .col-kanji { background: var(--kanji-color); }
        .col-vocab { background: var(--vocab-color); }
        .chart-labels {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: #888;
            margin-top: 6px;
            font-weight: 700;
        }
    </style>
</head>
<body>

<nav>
    <a href="index.php" class="logo">WANIKANI <span style="font-size:0.85rem; color:#888; font-weight:600;">OFFLINE</span></a>
    <ul class="nav-links">
        <li><a href="#">Levels</a></li>
        <li><a href="#">Radicals</a></li>
        <li><a href="#">Kanji</a></li>
        <li><a href="#">Vocabulary</a></li>
        <li><a href="upcoming_reviews.php">Reviews Inspector</a></li>
        <li><div class="avatar"></div></li>
    </ul>
</nav>

<div class="container">

    <!-- Top Action Row -->
    <div class="grid-3">
        <!-- Lessons Tile -->
        <div class="card action-card">
            <div class="action-img-wrapper">
                <?php if ($daily_lessons_completed): ?>
                    <div class="icon-lessons-done">
                        <span style="font-size:0.9rem; font-weight:900; line-height:1; color:#000;">よくできた!</span>
                        <span>🐥</span>
                    </div>
                <?php else: ?>
                    <div class="action-circle-icon icon-lessons-active">🐣</div>
                <?php endif; ?>
            </div>
            <div class="action-info">
                <div class="stat-label"><?= $daily_lessons_completed ? "Today's" : "TODAY'S" ?></div>
                <div class="action-header-line">
                    <h2 class="action-title">Lessons</h2>
                    <span class="badge">
                        <?= $daily_lessons_completed ? 'Done!' : min($lessons_remaining_today, $total_lessons_available) ?>
                    </span>
                </div>
                <?php if ($daily_lessons_completed): ?>
                    <div class="action-desc">You've done your recommended Lessons for today. Do more at your own peril.</div>
                    <a href="lessons.php" class="btn-action">🤠 Advanced ›</a>
                <?php else: ?>
                    <div class="action-desc"><?= $total_lessons_available ?> total lessons waiting in queue.</div>
                    <a href="lessons.php" class="btn-action">Start Lessons ›</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reviews Tile -->
        <div class="card action-card">
            <div class="action-img-wrapper">
                <?php if ($review_count > 0): ?>
                    <div class="action-circle-icon icon-reviews-active">🥋</div>
                <?php else: ?>
                    <div class="action-circle-icon icon-reviews-empty">🥋</div>
                <?php endif; ?>
            </div>
            <div class="action-info">
                <div class="stat-label"><?= $review_count > 0 ? "QUEUE" : "" ?></div>
                <div class="action-header-line">
                    <h2 class="action-title">Reviews</h2>
                    <span class="badge"><?= $review_count ?></span>
                </div>
                <?php if ($review_count > 0): ?>
                    <a href="review.php" class="btn-action" style="background: #22c55e; color: #fff; border:none; margin-top: 0.75rem;">Start Reviews ›</a>
                <?php else: ?>
                    <div class="action-desc">There are no more Reviews to do right now.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 24-Hour Forecast -->
        <div class="card">
            <div style="font-weight:800; font-size: 0.95rem; margin-bottom: 0.25rem;">Next 24 Hours: <strong>+<?= $review_count + 12 ?> Items</strong></div>
            <div class="forecast-row">
                <span>Today</span>
                <div class="bar-container"><div class="bar-fill" style="width: 45%;"></div></div>
                <span style="color:#22c55e;">(+12)</span>
            </div>
            <div class="forecast-row">
                <span>Tomorrow</span>
                <div class="bar-container"><div class="bar-fill" style="width: 80%;"></div></div>
                <span style="color:#22c55e;">(+34)</span>
            </div>
        </div>
    </div>

    <!-- Stats & Recent Mistakes Row -->
    <div class="grid-2">
        <div class="card" style="display:flex; justify-content: space-around; align-items:center;">
            <div>
                <div class="stat-label">Study Streak</div>
                <div class="stat-huge">9 <span style="font-size:1.2rem;">日</span></div>
                <div class="streak-icons">
                    <div class="streak-badge">🐢</div>
                    <div class="streak-badge">🐢</div>
                    <div class="streak-badge">🐢</div>
                    <div class="streak-badge" style="background:#bbf7d0;">✓</div>
                </div>
            </div>
            <div style="border-left: 1px solid var(--border-color); height: 80px;"></div>
            <div>
                <div class="stat-label">Reviews Completed</div>
                <div class="stat-huge">168</div>
                <div style="font-size:0.8rem; color:#888; margin-top:4px;">Accuracy: <strong>94.2%</strong></div>
            </div>
        </div>

        <!-- Recent Mistakes Card -->
        <div class="card">
            <div class="mistakes-title-wrap">
                <div class="ninja-icon">🥷</div>
                <span style="font-weight:800; font-size:1.1rem; color:#1a202c;">Recent Mistakes</span>
            </div>
            <div style="font-size:0.88rem; color:#718096; margin-top:4px;">
                Mistakes from the past 24 hours. Give them some extra love.
            </div>

            <div class="mistakes-controls">
                <a href="extra_study.php" class="btn-mistakes" style="flex: 1.6;">
                    <span>Extra Study</span>
                    <span style="background:#edf2f7; padding:2px 10px; border-radius:12px; font-size:0.8rem; color:#4a5568; font-weight:800;">
                        <?= $mistakes_count ?> &rsaquo;
                    </span>
                </a>
                <a href="lessons.php" class="btn-mistakes" style="flex: 1.1;">
                    <span>Redo Lessons ⟳</span>
                </a>
            </div>

            <div class="chip-container">
                <?php foreach ($mistakes as $m): ?>
                    <a href="subject_detail.php?id=<?= $m['id'] ?>" class="chip <?= htmlspecialchars($m['object_type']) ?>">
                        <?php if ($m['characters']): ?>
                            <?= htmlspecialchars($m['characters']) ?>
                        <?php elseif ($m['character_image_url']): ?>
                            <img src="<?= htmlspecialchars($m['character_image_url']) ?>" class="chip-svg" alt="radical">
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Progression Grid -->
    <div class="grid-2">
        <div class="card" id="level-progress-container">
            <div id="lp-overview-mode">
                <div class="lp-header">
                    <div class="lp-title">Level Progress</div>
                    <div class="lp-nav">
                        <button class="lp-btn" id="lp-prev" onclick="changeLevel(-1)">&lt;</button>
                        <span id="lp-level-display">Level <?= $current_level ?></span>
                        <button class="lp-btn" id="lp-next" onclick="changeLevel(1)">&gt;</button>
                    </div>
                </div>
                <div class="lp-subtitle">Number of items <strong>Guru'd</strong> in this level.</div>

                <div class="lp-breakdown">
                    <div class="lp-box" onclick="showSubjectGrid('radical')">
                        <div class="lp-box-top">
                            <span class="lp-icon icon-radical">幺</span>
                            <span class="lp-box-label">Radicals</span>
                        </div>
                        <div class="lp-box-bottom">
                            <span class="lp-fraction" id="lp-radical-count">0/0</span>
                            <span class="lp-see-all">See All &rsaquo;</span>
                        </div>
                    </div>

                    <div class="lp-box" onclick="showSubjectGrid('kanji')">
                        <div class="lp-box-top">
                            <span class="lp-icon icon-kanji">字</span>
                            <span class="lp-box-label">Kanji</span>
                        </div>
                        <div class="lp-box-bottom">
                            <span class="lp-fraction" id="lp-kanji-count">0/0</span>
                            <span class="lp-see-all">See All &rsaquo;</span>
                        </div>
                    </div>

                    <div class="lp-box" onclick="showSubjectGrid('vocabulary')">
                        <div class="lp-box-top">
                            <span class="lp-icon icon-vocab">語</span>
                            <span class="lp-box-label">Vocabulary</span>
                        </div>
                        <div class="lp-box-bottom">
                            <span class="lp-fraction" id="lp-vocab-count">0/0</span>
                            <span class="lp-see-all">See All &rsaquo;</span>
                        </div>
                    </div>
                </div>

                <div class="lp-levelup-text" id="lp-levelup-msg">Guru <strong>0 more kanji</strong> to level up.</div>
                <div class="lp-progress-bar" id="lp-bar-container"></div>
            </div>

            <div id="lp-grid-mode" style="display: none;">
                <div class="grid-view-header">
                    <button class="btn-back" onclick="hideSubjectGrid()">&lt; Back</button>
                    <div style="font-size: 0.95rem; font-weight: 700;" id="grid-status-header">0/0 items Guru'd</div>
                </div>
                <div class="subject-grid-wrapper" id="subject-grid-container"></div>
            </div>
        </div>

        <!-- Active Item Spread -->
        <div class="card">
            <div style="display:flex; justify-content:space-between;">
                <span style="font-weight:800;">Active Item Spread</span>
                <span style="font-size:0.85rem; color:#888;">Apprentice → Enlightened</span>
            </div>
            <div class="spread-chart">
                <?php
                $max_val = 150;
                for ($stage = 1; $stage <= 8; $stage++):
                    $r_cnt = $srs_matrix[$stage]['radical'];
                    $k_cnt = $srs_matrix[$stage]['kanji'];
                    $v_cnt = $srs_matrix[$stage]['vocabulary'];
                    $total = $r_cnt + $k_cnt + $v_cnt;
                    $h_total = min(120, ($total / $max_val) * 120);
                    ?>
                    <div style="display:flex; flex-direction:column; align-items:center;">
                        <div class="chart-col" style="height: <?= max(4, $h_total) ?>px;">
                            <div class="col-vocab" style="height: <?= ($v_cnt / max(1, $total)) * 100 ?>%;"></div>
                            <div class="col-kanji" style="height: <?= ($k_cnt / max(1, $total)) * 100 ?>%;"></div>
                            <div class="col-radical" style="height: <?= ($r_cnt / max(1, $total)) * 100 ?>%;"></div>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="chart-labels">
                <span>I</span><span>II</span><span>III</span><span>IV</span>
                <span>V</span><span>VI</span><span>VII</span><span>VIII</span>
            </div>
        </div>
    </div>

</div>

<script>
    let currentLpLevel = <?= $current_level ?>;

    async function loadLevelProgress(level) {
        try {
            const res = await fetch(`api_level_progress.php?level=${level}`);
            const data = await res.json();

            currentLpLevel = data.level;
            document.getElementById('lp-level-display').textContent = `Level ${currentLpLevel}`;
            document.getElementById('lp-prev').disabled = (currentLpLevel <= 1);
            document.getElementById('lp-next').disabled = (currentLpLevel >= 60);

            document.getElementById('lp-radical-count').textContent = `${data.progress.radical.gurud}/${data.progress.radical.total}`;
            document.getElementById('lp-kanji-count').textContent = `${data.progress.kanji.gurud}/${data.progress.kanji.total}`;
            document.getElementById('lp-vocab-count').textContent = `${data.progress.vocabulary.gurud}/${data.progress.vocabulary.total}`;

            const levelUpMsg = document.getElementById('lp-levelup-msg');
            if (data.kanji_needed > 0) {
                levelUpMsg.innerHTML = `Guru <strong>${data.kanji_needed} more kanji</strong> to level up.`;
            } else {
                levelUpMsg.innerHTML = `<strong>Level Complete!</strong> 90% of Kanji Guru'd 🎉`;
            }

            const barContainer = document.getElementById('lp-bar-container');
            barContainer.innerHTML = '';
            const totalSegments = data.total_kanji || 30;
            const filledSegments = data.gurud_kanji || 0;

            for (let i = 0; i < totalSegments; i++) {
                const segment = document.createElement('div');
                segment.className = 'lp-segment' + (i < filledSegments ? ' filled' : '');
                barContainer.appendChild(segment);
            }
        } catch (e) {
            console.error("Failed to load level overview data", e);
        }
    }

    function changeLevel(offset) {
        const target = currentLpLevel + offset;
        if (target >= 1 && target <= 60) {
            loadLevelProgress(target);
        }
    }

    async function showSubjectGrid(type) {
        document.getElementById('lp-overview-mode').style.display = 'none';
        document.getElementById('lp-grid-mode').style.display = 'block';

        const container = document.getElementById('subject-grid-container');
        container.innerHTML = '<div style="grid-column: 1/-1; text-align:center; color:#888; padding: 2rem 0;">Loading...</div>';

        container.className = 'subject-grid-wrapper ' + (type === 'vocabulary' ? 'layout-vocab' : 'layout-compact');

        try {
            const res = await fetch(`api_level_subjects.php?level=${currentLpLevel}&type=${type}`);
            const data = await res.json();

            document.getElementById('grid-status-header').innerHTML = `<strong>${data.gurud}/${data.total} items</strong> Guru'd`;
            container.innerHTML = '';

            data.items.forEach(item => {
                const statusGroup = item.status_group;
                const stage = item.srs_stage;

                const wrapper = document.createElement('a');
                wrapper.className = 'item-wrapper';
                wrapper.href = `subject_detail.php?id=${item.id}`;

                let styleClass = '';
                if (type === 'radical') {
                    if (statusGroup === 1) styleClass = 'radical-learned';
                    else if (statusGroup === 2) styleClass = 'radical-unlocked';
                    else styleClass = 'radical-locked';
                } else if (type === 'kanji') {
                    if (statusGroup === 1) styleClass = 'kanji-learned';
                    else if (statusGroup === 2) styleClass = 'kanji-unlocked';
                    else styleClass = 'kanji-locked';
                } else {
                    if (statusGroup === 1) styleClass = 'vocab-learned';
                    else if (statusGroup === 2) styleClass = 'vocab-unlocked';
                    else styleClass = 'vocab-locked';
                }

                const tile = document.createElement('div');
                tile.className = `item-tile ${styleClass}`;

                if (item.characters) {
                    tile.textContent = item.characters;
                } else if (item.character_image_url) {
                    const img = document.createElement('img');
                    img.src = item.character_image_url;
                    img.className = `item-svg ${statusGroup === 1 ? 'svg-white' : 'svg-blue'}`;
                    tile.appendChild(img);
                } else {
                    tile.textContent = '—';
                }

                wrapper.appendChild(tile);

                if (statusGroup === 1) {
                    const ticks = document.createElement('div');
                    ticks.className = 'ticks-container';
                    let activeTicks = item.passed || stage >= 5 ? 5 : (stage >= 1 && stage <= 4 ? stage : 0);
                    for (let t = 1; t <= 5; t++) {
                        const tick = document.createElement('div');
                        tick.className = `tick ${t <= activeTicks ? 'active' : ''}`;
                        ticks.appendChild(tick);
                    }
                    wrapper.appendChild(ticks);
                } else if (statusGroup === 2) {
                    const lessonsText = document.createElement('div');
                    lessonsText.className = 'item-subtext';
                    lessonsText.textContent = 'Lessons';
                    wrapper.appendChild(lessonsText);
                } else {
                    const emptySpacer = document.createElement('div');
                    emptySpacer.className = 'item-subtext';
                    wrapper.appendChild(emptySpacer);
                }

                container.appendChild(wrapper);
            });
        } catch (e) {
            container.innerHTML = '<div style="grid-column: 1/-1; text-align:center; color:red;">Failed to load subjects.</div>';
        }
    }

    function hideSubjectGrid() {
        document.getElementById('lp-grid-mode').style.display = 'none';
        document.getElementById('lp-overview-mode').style.display = 'block';
    }

    loadLevelProgress(currentLpLevel);
</script>

</body>
</html>