# WaniKaniOffline

A lightweight, PHP-based offline companion and local synchronization client for [WaniKani](https://www.wanikani.com). This project allows users to cache reviews, inspect upcoming SRS schedules, and practice lessons locally with bidirectional synchronization capabilities.

---

## Features

- **Offline SRS Reviews & Lessons:** Run localized review and lesson queues (`review.php`, `lessons.php`) without constant round-trip network delays.
- **REST Endpoints for Local Client:** Dedicated endpoints to fetch queues, update progress, submit reviews, and query subjects:
  - `api_lesson_queue.php`
  - `api_level_progress.php`
  - `api_level_subjects.php`
  - `api_submit_review.php`
- **Upcoming Review Analysis:** Visual tools to forecast upcoming reviews (`upcoming_reviews.php`) and cross-check scheduled queues (`compare_upcoming.php`, `live_compare.php`).
- **Sync Engine:** On-demand sync script (`sync_now.php`) to sync local study progress back to the official WaniKani v2 API.
- **Diagnostics:** Built-in connection probe (`api_probe.php`) and state dumper (`debug_dump.php`) for troubleshooting.

---

## Tech Stack

- **Backend:** PHP 8.x
- **API Integration:** WaniKani REST API v2
- **Data Persistence:** Local JSON / SQLite cache
- **Tooling:** Git, PhpStorm / JetBrains IDE support

---

## Project Structure

```text
├── api_lesson_queue.php     # Endpoint serving cached lesson batches
├── api_level_progress.php   # Endpoint for level completion metrics
├── api_level_subjects.php   # Radicals, Kanji, and Vocabulary lookup
├── api_probe.php            # Connection health check and credentials validator
├── api_submit_review.php    # Handler for completed SRS review submissions
├── compare_upcoming.php     # Comparison tool for review timing logic
├── debug_dump.php           # Raw cache and session diagnostic viewer
├── index.php                # Main dashboard entry point
├── lessons.php              # Offline lesson presentation interface
├── live_compare.php         # Real-time state versus remote state inspector
├── live_upcoming.php        # Live incoming SRS item monitor
├── review.php               # Spaced-repetition review session interface
├── sync_now.php             # Bidirectional synchronization runner
└── upcoming_reviews.php     # Timeline view of incoming review queues
