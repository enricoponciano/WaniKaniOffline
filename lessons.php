<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WaniKani Lessons</title>
    <script src="https://unpkg.com/wanakana@5.3.1/wanakana.min.js"></script>
    <style>
        :root {
            --radical: #00aaff;
            --kanji: #f100a1;
            --vocab: #aa00ff;
            --bg-dark: #1f232b;
            --text-main: #333333;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #eaedf0; color: var(--text-main); height: 100vh; display: flex; flex-direction: column; overflow: hidden; }

        /* Top Bar */
        .top-nav {
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 48px;
            padding: 0 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 10;
            color: #fff;
            font-size: 0.95rem;
            font-weight: 700;
        }
        .nav-left a { color: #fff; text-decoration: none; font-size: 1.3rem; opacity: 0.85; }
        .nav-left a:hover { opacity: 1; }
        .nav-right { display: flex; gap: 16px; font-family: monospace; font-size: 0.95rem; }

        /* Main Lesson Hero Banner */
        .hero-banner, .quiz-hero {
            height: 40vh;
            min-height: 250px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #ffffff !important;
            position: relative;
            transition: background 0.2s;
        }
        .radical-bg { background: var(--radical) !important; }
        .kanji-bg { background: var(--kanji) !important; }
        .vocab-bg { background: var(--vocab) !important; }

        .character-text {
            font-size: 6.5rem;
            font-weight: 500;
            font-family: "Hiragino Kaku Gothic Pro", "Yu Gothic", "Meiryo", sans-serif;
            line-height: 1.1;
            color: #ffffff !important;
            text-shadow: 0 2px 10px rgba(0,0,0,0.25);
        }
        .character-svg { width: 90px; height: 90px; filter: brightness(0) invert(1); }
        .meaning-subtext { font-size: 1.7rem; font-weight: 600; margin-top: 0.4rem; opacity: 0.95; color: #fff; }

        /* Tabs Navigation Strip */
        .tab-strip {
            background: #2b303c;
            display: flex;
            justify-content: center;
            gap: 2rem;
            border-bottom: 1px solid #1f232b;
        }
        .tab-btn {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 1rem;
            font-weight: 700;
            padding: 12px 16px;
            cursor: pointer;
            position: relative;
            transition: color 0.15s;
        }
        .tab-btn:hover { color: #fff; }
        .tab-btn.active { color: #fff; }
        .tab-btn.active::after {
            content: "";
            position: absolute;
            bottom: 0; left: 50%;
            transform: translateX(-50%);
            border-left: 7px solid transparent;
            border-right: 7px solid transparent;
            border-bottom: 7px solid #fff;
        }

        /* Slide Content Area */
        .slide-container {
            flex-grow: 1;
            background: #fff;
            max-width: 960px;
            width: 100%;
            margin: 0 auto;
            position: relative;
            padding: 2rem 3.5rem;
            overflow-y: auto;
            border-left: 1px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
        }
        .arrow-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.8rem;
            color: #94a3b8;
            cursor: pointer;
            user-select: none;
            padding: 10px;
        }
        .arrow-nav:hover { color: #333; }
        .arrow-left { left: 10px; }
        .arrow-right { right: 10px; }

        .content-title { font-size: 1.5rem; font-weight: 700; color: #1e293b; margin-bottom: 0.75rem; }
        .content-body { font-size: 1.05rem; line-height: 1.65; color: #334155; }
        .component-row { display: flex; gap: 12px; align-items: center; margin: 1rem 0; }
        .comp-chip {
            padding: 6px 12px;
            border-radius: 6px;
            color: #fff;
            font-size: 1.15rem;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .comp-chip.radical { background: var(--radical); }
        .comp-chip.kanji { background: var(--kanji); }

        /* Bottom Batch Control Footer */
        .bottom-footer {
            background: #fff;
            border-top: 1px solid #e2e8f0;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .batch-chip {
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 1.1rem;
            font-weight: bold;
            font-family: "Hiragino Kaku Gothic Pro", "Yu Gothic", sans-serif;
            cursor: pointer;
            border: 2px solid transparent;
            background: #f1f5f9;
            color: #475569;
            transition: all 0.15s;
        }
        .batch-chip.active {
            border-color: #3b82f6;
            transform: scale(1.06);
            color: #fff;
        }
        .batch-chip.radical.active { background: var(--radical); border-color: #0077b6; }
        .batch-chip.kanji.active { background: var(--kanji); border-color: #b80082; }
        .batch-chip.vocabulary.active, .batch-chip.kana_vocabulary.active { background: var(--vocab); border-color: #6b00b3; }

        .btn-quiz {
            background: #00cc66;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 8px 18px;
            font-size: 1.05rem;
            font-weight: 800;
            cursor: pointer;
            margin-left: 8px;
            transition: background 0.15s;
        }
        .btn-quiz:hover { background: #00b359; }

        /* Quiz Screen */
        #quiz-screen {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #f4f7f6;
            flex-direction: column;
            z-index: 100;
        }
        .quiz-prompt-bar {
            background: #2b303c;
            color: #fff;
            padding: 14px;
            text-align: center;
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .quiz-input-wrapper {
            max-width: 600px;
            margin: 2.5rem auto;
            width: 100%;
            padding: 0 1rem;
        }
        .quiz-input {
            width: 100%;
            padding: 14px 20px;
            font-size: 1.7rem;
            border: 2px solid #cbd5e1;
            border-radius: 8px;
            text-align: center;
            outline: none;
            font-family: "Hiragino Kaku Gothic Pro", "Yu Gothic", sans-serif;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
        }
        .quiz-input.correct { background: #dcfce7 !important; border-color: #22c55e !important; color: #15803d !important; }
        .quiz-input.incorrect { background: #fee2e2 !important; border-color: #ef4444 !important; color: #b91c1c !important; }
    </style>
</head>
<body>

<div class="top-nav">
    <div class="nav-left">
        <a href="index.php">&#8962;</a>
    </div>
    <div class="nav-right">
        <span>R <span id="queue-rad-count">0</span></span>
        <span>K <span id="queue-kan-count">0</span></span>
        <span>V <span id="queue-voc-count">0</span></span>
    </div>
</div>

<div class="hero-banner" id="hero-banner">
    <div id="hero-char-wrap" class="character-text">...</div>
    <div id="hero-meaning" class="meaning-subtext">...</div>
</div>

<div class="tab-strip" id="tab-strip"></div>

<div class="slide-container">
    <div class="arrow-nav arrow-left" onclick="prevTab()">&lt;</div>
    <div class="arrow-nav arrow-right" onclick="nextTab()">&gt;</div>
    <div class="content-title" id="content-title">...</div>
    <div class="content-body" id="content-body">...</div>
</div>

<div class="bottom-footer">
    <div id="batch-chips-container" style="display: flex; gap: 8px;"></div>
    <button class="btn-quiz" onclick="startQuiz()">Quiz &rarr;</button>
</div>

<!-- Quiz Screen -->
<div id="quiz-screen">
    <div class="quiz-hero" id="quiz-hero">
        <div id="quiz-char-wrap" class="character-text">...</div>
    </div>
    <div class="quiz-prompt-bar" id="quiz-prompt-type">VOCABULARY MEANING</div>
    <div class="quiz-input-wrapper">
        <input type="text" id="quiz-input" class="quiz-input" placeholder="Your Answer" autofocus autocomplete="off">
    </div>
</div>

<script>
    let lessonBatch = [];
    let currentIndex = 0;
    let currentTab = 0;
    let availableTabs = [];

    function getBgClass(type) {
        if (type === 'radical') return 'radical-bg';
        if (type === 'kanji') return 'kanji-bg';
        return 'vocab-bg';
    }

    async function loadLessonBatch() {
        try {
            const res = await fetch('api_lesson_queue.php');
            const data = await res.json();

            document.getElementById('queue-rad-count').textContent = data.counts.radical;
            document.getElementById('queue-kan-count').textContent = data.counts.kanji;
            document.getElementById('queue-voc-count').textContent = data.counts.vocabulary;

            if (!data.batch || data.batch.length === 0) {
                alert("No lessons available right now!");
                window.location.href = "index.php";
                return;
            }

            lessonBatch = data.batch;
            renderBatchFooter();
            loadItem(0);
        } catch (e) {
            console.error("Failed to load lessons", e);
        }
    }

    function renderBatchFooter() {
        const container = document.getElementById('batch-chips-container');
        container.innerHTML = '';

        lessonBatch.forEach((item, idx) => {
            const chip = document.createElement('div');
            chip.className = `batch-chip ${item.object_type} ${idx === currentIndex ? 'active' : ''}`;
            chip.textContent = item.characters || '—';
            chip.onclick = () => loadItem(idx);
            container.appendChild(chip);
        });
    }

    function loadItem(index) {
        currentIndex = index;
        const item = lessonBatch[index];

        const hero = document.getElementById('hero-banner');
        hero.className = `hero-banner ${getBgClass(item.object_type)}`;

        const charWrap = document.getElementById('hero-char-wrap');
        if (item.characters) {
            charWrap.innerHTML = item.characters;
        } else if (item.character_image_url) {
            charWrap.innerHTML = `<img src="${item.character_image_url}" class="character-svg" alt="radical">`;
        }

        document.getElementById('hero-meaning').textContent = item.primary_meaning;

        if (item.object_type === 'radical') {
            availableTabs = ['Meaning Mnemonic'];
        } else if (item.object_type === 'kanji') {
            availableTabs = ['Radical Composition', 'Meaning', 'Reading'];
        } else {
            availableTabs = ['Kanji Composition', 'Meaning', 'Reading', 'Context'];
        }

        renderTabs();
        loadTab(0);
        renderBatchFooter();
    }

    function renderTabs() {
        const strip = document.getElementById('tab-strip');
        strip.innerHTML = '';

        availableTabs.forEach((tabName, idx) => {
            const btn = document.createElement('button');
            btn.className = `tab-btn ${idx === currentTab ? 'active' : ''}`;
            btn.textContent = tabName;
            btn.onclick = () => loadTab(idx);
            strip.appendChild(btn);
        });
    }

    function loadTab(tabIdx) {
        currentTab = tabIdx;
        renderTabs();

        const item = lessonBatch[currentIndex];
        const tabName = availableTabs[tabIdx];
        const title = document.getElementById('content-title');
        const body = document.getElementById('content-body');

        title.textContent = tabName;

        if (tabName.includes('Composition')) {
            let compHtml = `<p>This ${item.object_type} is composed of:</p><div class="component-row">`;
            item.components.forEach(c => {
                compHtml += `<div class="comp-chip ${c.object_type}">${c.characters || ''} <span style="font-size:0.85rem; font-weight:normal; opacity:0.9;">(${c.meaning})</span></div>`;
            });
            compHtml += `</div><p style="margin-top:1rem;">Can you see how these pieces combine to form the meaning?</p>`;
            body.innerHTML = compHtml;
        } else if (tabName === 'Meaning' || tabName === 'Meaning Mnemonic') {
            body.innerHTML = `<p style="font-size:1.2rem; font-weight:bold; margin-bottom:0.5rem;">Primary: ${item.all_meanings.join(', ')}</p><p>${item.meaning_mnemonic || 'No mnemonic provided.'}</p>`;
        } else if (tabName === 'Reading') {
            body.innerHTML = `<p style="font-size:1.2rem; font-weight:bold; margin-bottom:0.5rem;">Reading: ${item.all_readings.join(', ')}</p><p>${item.reading_mnemonic || 'No reading mnemonic provided.'}</p>`;
        } else {
            body.innerHTML = `<p>Context sentences and usage patterns will appear here in future updates.</p>`;
        }
    }

    function prevTab() {
        if (currentTab > 0) loadTab(currentTab - 1);
        else if (currentIndex > 0) loadItem(currentIndex - 1);
    }

    function nextTab() {
        if (currentTab < availableTabs.length - 1) loadTab(currentTab + 1);
        else if (currentIndex < lessonBatch.length - 1) loadItem(currentIndex + 1);
    }

    // --- FULL BATCH QUIZ CONTROLLER ---
    let quizQueue = [];
    let activeQuiz = null;
    let batchMistakes = {}; // subject_id -> { meaning: count, reading: count }

    function startQuiz() {
        quizQueue = [];
        batchMistakes = {};

        lessonBatch.forEach(item => {
            batchMistakes[item.id] = { meaning: 0, reading: 0 };
            quizQueue.push({ item: item, type: 'meaning', answered: false });
            if (item.object_type !== 'radical') {
                quizQueue.push({ item: item, type: 'reading', answered: false });
            }
        });

        // Shuffle the quiz cards for the entire 5-item batch
        quizQueue.sort(() => Math.random() - 0.5);
        document.getElementById('quiz-screen').style.display = 'flex';
        nextQuizQuestion();
    }

    function nextQuizQuestion() {
        const remaining = quizQueue.filter(q => !q.answered);
        if (remaining.length === 0) {
            finishLessonBatch();
            return;
        }

        activeQuiz = remaining[0];
        const hero = document.getElementById('quiz-hero');
        hero.className = `quiz-hero ${getBgClass(activeQuiz.item.object_type)}`;

        const charWrap = document.getElementById('quiz-char-wrap');
        if (activeQuiz.item.characters) {
            charWrap.innerHTML = activeQuiz.item.characters;
        } else if (activeQuiz.item.character_image_url) {
            charWrap.innerHTML = `<img src="${activeQuiz.item.character_image_url}" class="character-svg" alt="radical">`;
        }

        const promptBar = document.getElementById('quiz-prompt-type');
        const input = document.getElementById('quiz-input');

        promptBar.textContent = `${activeQuiz.item.object_type.toUpperCase()} ${activeQuiz.type.toUpperCase()}`;

        input.value = '';
        input.className = 'quiz-input';
        input.placeholder = (activeQuiz.type === 'reading') ? '答え (Hiragana)' : 'Your Answer (English)';

        // Attach WanaKana conversion for readings
        if (typeof wanakana !== 'undefined') {
            wanakana.unbind(input);
            if (activeQuiz.type === 'reading') {
                wanakana.bind(input, { IMEMode: true });
            }
        }

        input.focus();
    }

    // Fallback direct romaji-to-hiragana converter on keyup
    document.getElementById('quiz-input').addEventListener('input', function() {
        if (activeQuiz && activeQuiz.type === 'reading') {
            if (typeof wanakana !== 'undefined') {
                this.value = wanakana.toHiragana(this.value, { IMEMode: true });
            }
        }
    });

    document.getElementById('quiz-input').addEventListener('keydown', async function(e) {
        if (e.key === 'Enter') {
            let val = this.value.trim().toLowerCase();
            if (!val) return;

            let isCorrect = false;
            if (activeQuiz.type === 'meaning') {
                isCorrect = activeQuiz.item.all_meanings.some(m => m.toLowerCase() === val);
            } else {
                // Compare Hiragana values
                let hiraganaVal = typeof wanakana !== 'undefined' ? wanakana.toHiragana(val) : val;
                isCorrect = activeQuiz.item.all_readings.some(r => r === hiraganaVal || r === val);
            }

            if (isCorrect) {
                this.classList.add('correct');
                activeQuiz.answered = true;
                setTimeout(nextQuizQuestion, 300);
            } else {
                this.classList.add('incorrect');
                // Track mistake on item
                if (activeQuiz.type === 'meaning') {
                    batchMistakes[activeQuiz.item.id].meaning++;
                } else {
                    batchMistakes[activeQuiz.item.id].reading++;
                }

                // Move the missed question to the back of the queue
                setTimeout(() => {
                    this.classList.remove('incorrect');
                    this.value = '';
                    const failed = quizQueue.shift();
                    quizQueue.push(failed);
                    nextQuizQuestion();
                }, 700);
            }
        }
    });

    // Submit the entire batch of 5 to MySQL ONLY after all items are completed
    async function finishLessonBatch() {
        for (const it of lessonBatch) {
            const mistakes = batchMistakes[it.id] || { meaning: 0, reading: 0 };
            const fd = new FormData();
            fd.append('subject_id', it.id);
            fd.append('incorrect_meanings', mistakes.meaning);
            fd.append('incorrect_readings', mistakes.reading);
            await fetch('api_submit_review.php', { method: 'POST', body: fd });
        }

        alert("🎉 Batch Complete! All 5 items have officially been promoted to Apprentice I.");
        window.location.href = "index.php";
    }

    loadLessonBatch();
</script>

</body>
</html>