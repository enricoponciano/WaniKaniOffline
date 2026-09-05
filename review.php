<?php
$conn = new mysqli("localhost", "root", "", "wanikani_offline");
$conn->set_charset("utf8mb4");

$now_iso = gmdate('Y-m-d\TH:i:s\Z');

// Fetch all available review items
$query = "
    SELECT 
        s.id, s.object_type, s.characters, s.character_image_url,
        s.meanings, s.readings, s.audio_urls,
        a.srs_stage, a.id as assignment_id
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.srs_stage > 0 AND a.srs_stage < 9
      AND (a.available_at <= '$now_iso' OR a.available_at IS NULL)
    ORDER BY a.srs_stage ASC, RAND()
";

$res = $conn->query($query);
$queue = [];

while ($row = $res->fetch_assoc()) {
    $queue[] = [
        'id' => intval($row['id']),
        'type' => $row['object_type'],
        'characters' => $row['characters'],
        'character_image_url' => $row['character_image_url'],
        'meanings' => json_decode($row['meanings'], true) ?: [],
        'readings' => json_decode($row['readings'], true) ?: [],
        'audio_urls' => json_decode($row['audio_urls'], true) ?: [],
        'srs_stage' => intval($row['srs_stage'])
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WaniKani Offline - Reviews</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root {
            --radical: #00aaff;
            --kanji: #f100a1;
            --vocab: #aa00ff;
            --bg-dark: #232629;
            --correct-green: #88cc00;
            --wrong-red: #ff0033;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg-dark); color: #fff; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }

        /* Top Progress Bar */
        .progress-header { height: 48px; background: #1a1c1e; display: flex; align-items: center; justify-content: space-between; padding: 0 1.5rem; border-bottom: 1px solid #333; }
        .queue-count { font-size: 1.1rem; font-weight: 800; color: #fff; }
        .btn-exit { color: #888; text-decoration: none; font-weight: 700; font-size: 0.9rem; }
        .btn-exit:hover { color: #fff; }

        /* Main Character Display */
        .stage-area { flex-grow: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; position: relative; }
        .character-card { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 6.5rem; font-family: "Hiragino Kaku Gothic Pro", "Yu Gothic", sans-serif; font-weight: 500; }
        .bg-radical { background: var(--radical); }
        .bg-kanji { background: var(--kanji); }
        .bg-vocabulary, .bg-kana_vocabulary { background: var(--vocab); }
        .char-svg { width: 110px; height: 110px; filter: brightness(0) invert(1); }

        /* Input Controls Area */
        .input-area { background: #33373b; padding: 1.5rem; display: flex; flex-direction: column; align-items: center; border-top: 1px solid #444; }
        .prompt-label { font-size: 1.15rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.6rem; color: #ddd; }
        .input-wrapper { width: 100%; max-width: 550px; position: relative; }
        .review-input {
            width: 100%;
            height: 54px;
            background: #fff;
            border: 3px solid transparent;
            border-radius: 8px;
            font-size: 1.5rem;
            text-align: center;
            font-weight: 600;
            color: #222;
            outline: none;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
            transition: border-color 0.15s, background 0.15s;
        }
        .review-input.correct { background: #e6f9e6; border-color: var(--correct-green); color: #006600; }
        .review-input.incorrect { background: #ffe6e6; border-color: var(--wrong-red); color: #990000; }

        /* Completion Screen */
        .complete-panel { display: none; flex-direction: column; align-items: center; justify-content: center; height: 100%; text-align: center; padding: 2rem; }
        .complete-panel h1 { font-size: 2.8rem; margin-bottom: 0.5rem; }
        .btn-return { margin-top: 2rem; background: var(--correct-green); color: #fff; font-weight: 800; padding: 12px 28px; border-radius: 8px; text-decoration: none; font-size: 1.1rem; }
    </style>
</head>
<body>

<div class="progress-header">
    <a href="index.php" class="btn-exit">&larr; Home</a>
    <div class="queue-count"><span id="remaining-count"><?= count($queue) ?></span> Remaining</div>
</div>

<div class="stage-area" id="stage-box">
    <div id="character-display" class="character-card bg-kanji">...</div>
</div>

<div class="input-area" id="input-box">
    <div class="prompt-label" id="prompt-label">Kanji Meaning</div>
    <div class="input-wrapper">
        <input type="text" id="user-input" class="review-input" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" placeholder="Your Response">
    </div>
</div>

<div class="complete-panel" id="complete-box">
    <h1>All Done! 🎉</h1>
    <p style="color: #aaa; font-size: 1.2rem;">You've completed all active reviews in your queue.</p>
    <a href="index.php" class="btn-return">Return to Dashboard</a>
</div>

<script>
    const reviewQueue = <?= json_encode($queue) ?>;

    // Romaji to Hiragana conversion mapping
    const romajiMap = {
        'a':'あ','i':'い','u':'う','e':'え','o':'お',
        'ka':'か','ki':'き','ku':'く','ke':'け','ko':'こ',
        'sa':'さ','shi':'し','si':'し','su':'す','se':'せ','so':'そ',
        'ta':'た','chi':'ち','ti':'ち','tsu':'つ','tu':'つ','te':'て','to':'と',
        'na':'な','ni':'に','nu':'ぬ','ne':'ね','no':'の',
        'ha':'は','hi':'ひ','fu':'ふ','hu':'ふ','he':'へ','ho':'ほ',
        'ma':'ま','mi':'み','mu':'む','me':'め','mo':'も',
        'ya':'や','yu':'ゆ','yo':'よ',
        'ra':'ら','ri':'り','ru':'る','re':'れ','ro':'ろ',
        'wa':'わ','wo':'を','nn':'ん','n':'ん',
        'ga':'が','gi':'ぎ','gu':'ぐ','ge':'げ','go':'ご',
        'za':'ざ','ji':'じ','zi':'じ','zu':'ず','ze':'ぜ','zo':'ぞ',
        'da':'だ','di':'ぢ','du':'づ','de':'で','do':'ど',
        'ba':'ば','bi':'び','bu':'ぶ','be':'べ','bo':'ぼ',
        'pa':'ぱ','pi':'ぴ','pu':'ぷ','pe':'ぺ','po':'ぽ',
        'kya':'きゃ','kyu':'きゅ','kyo':'きょ',
        'sha':'しゃ','shu':'しゅ','sho':'しょ',
        'cha':'ちゃ','chu':'ちゅ','cho':'ちょ',
        'nya':'にゃ','nyu':'にゅ','nyo':'にょ',
        'hya':'ひゃ','hyu':'ひゅ','hyo':'ひょ',
        'mya':'みゃ','myu':'みゅ','myo':'みょ',
        'rya':'りゃ','ryu':'りゅ','ryo':'りょ',
        'gya':'ぎゃ','gyu':'ぎゅ','gyo':'ぎょ',
        'ja':'じゃ','ju':'じゅ','jo':'じょ',
        'bya':'びゃ','byu':'びゅ','byo':'びょ',
        'pya':'ぴゃ','pyu':'ぴゅ','pyo':'ぴょ'
    };

    // Build question tasks (meanings and readings)
    let tasks = [];
    let itemErrors = {};

    reviewQueue.forEach(item => {
        itemErrors[item.id] = { incorrect_meanings: 0, incorrect_readings: 0, meaning_done: false, reading_done: false };
        tasks.push({ item: item, questionType: 'meaning' });
        if (item.type !== 'radical' && item.readings && item.readings.length > 0) {
            tasks.push({ item: item, questionType: 'reading' });
        }
    });

    // Shuffle tasks
    tasks.sort(() => Math.random() - 0.5);

    let currentTaskIndex = 0;
    let isGrading = false;

    const charDisplay = document.getElementById('character-display');
    const promptLabel = document.getElementById('prompt-label');
    const inputField = document.getElementById('user-input');
    const remainingCount = document.getElementById('remaining-count');

    function renderCurrentTask() {
        if (tasks.length === 0) {
            document.getElementById('stage-box').style.display = 'none';
            document.getElementById('input-box').style.display = 'none';
            document.getElementById('complete-box').style.display = 'flex';
            return;
        }

        const currentTask = tasks[currentTaskIndex];
        const item = currentTask.item;
        const type = item.type;
        const qType = currentTask.questionType;

        // Remaining distinct items
        const remainingDistinct = new Set(tasks.map(t => t.item.id)).size;
        remainingCount.textContent = remainingDistinct;

        // Styling
        charDisplay.className = `character-card bg-${type}`;
        if (item.characters) {
            charDisplay.textContent = item.characters;
        } else if (item.character_image_url) {
            charDisplay.innerHTML = `<img src="${item.character_image_url}" class="char-svg" alt="glyph">`;
        }

        // Prompt Header
        const formattedType = type === 'radical' ? 'Radical' : (type === 'kanji' ? 'Kanji' : 'Vocabulary');
        promptLabel.textContent = `${formattedType} ${qType.charAt(0).toUpperCase() + qType.slice(1)}`;

        inputField.value = '';
        inputField.className = 'review-input';
        inputField.focus();
        isGrading = false;
    }

    // Convert input live to Hiragana during reading checks
    inputField.addEventListener('input', function(e) {
        const currentTask = tasks[currentTaskIndex];
        if (currentTask.questionType === 'reading') {
            let val = this.value.toLowerCase();
            for (let rom in romajiMap) {
                val = val.replace(new RegExp(rom, 'g'), romajiMap[rom]);
            }
            this.value = val;
        }
    });

    // Handle submission on Enter
    inputField.addEventListener('keydown', async function(e) {
        if (e.key !== 'Enter') return;

        if (isGrading) {
            // Move to next card
            renderCurrentTask();
            return;
        }

        const currentTask = tasks[currentTaskIndex];
        const item = currentTask.item;
        const qType = currentTask.questionType;
        const userVal = inputField.value.trim().toLowerCase();

        if (!userVal) return;

        let isCorrect = false;
        if (qType === 'meaning') {
            isCorrect = item.meanings.some(m => m.toLowerCase() === userVal);
        } else {
            isCorrect = item.readings.some(r => r === userVal);
        }

        isGrading = true;

        if (isCorrect) {
            inputField.className = 'review-input correct';

            // Play vocabulary audio if available
            if (qType === 'reading' && item.audio_urls && item.audio_urls.length > 0) {
                const audio = new Audio(item.audio_urls[0]);
                audio.play().catch(() => {});
            }

            if (qType === 'meaning') itemErrors[item.id].meaning_done = true;
            if (qType === 'reading') itemErrors[item.id].reading_done = true;

            // Remove this task from queue
            tasks.splice(currentTaskIndex, 1);
            if (currentTaskIndex >= tasks.length) currentTaskIndex = 0;

            // Check if full item is completed (both meaning & reading done)
            const reqReading = item.type !== 'radical' && item.readings && item.readings.length > 0;
            const fullyDone = itemErrors[item.id].meaning_done && (!reqReading || itemErrors[item.id].reading_done);

            if (fullyDone) {
                const stats = itemErrors[item.id];
                const formData = new FormData();
                formData.append('subject_id', item.id);
                formData.append('incorrect_meanings', stats.incorrect_meanings);
                formData.append('incorrect_readings', stats.incorrect_readings);

                fetch('api_submit_review.php', {
                    method: 'POST',
                    body: formData
                });
            }
        } else {
            inputField.className = 'review-input incorrect';
            if (qType === 'meaning') itemErrors[item.id].incorrect_meanings++;
            if (qType === 'reading') itemErrors[item.id].incorrect_readings++;

            // Shift task to later in queue
            const failedTask = tasks.splice(currentTaskIndex, 1)[0];
            tasks.push(failedTask);
        }
    });

    // Initialize
    renderCurrentTask();
</script>
</body>
</html>