# The Bookkeeper

A Banks Solution WordPress plugin: a paid, members-only book reader with chapters split into short sections, highlights with sticky notes, reflections, action points, a journal and progress tracking.

- Reader: `/read/{book-slug}/`, a standalone page (`inc/reader.php`, `assets/reader.js`, `assets/reader.css`).
- REST API: `bookkeeper/v1` (`inc/rest.php`). Data lives in the `bk_marks`, `bk_entries` and `bk_progress` tables (`inc/install.php`).
- Content: `bk_book` and `bk_chapter` post types. Each H2 starts a section. Give a paragraph or group the class `bk-reflect`, `bk-action` or `bk-quote` to make it interactive.
- Access: `inc/access.php`. Admins own every book; readers unlock a book by paying, or you grant it under Bookkeeper > Readers.
- Payments: `inc/pay.php`. Paystack checkout (redirect flow), verified on return and by the signed webhook `POST /wp-json/bookkeeper/v1/paystack`. Keys and the dollar switch live under Bookkeeper > Settings, prices in the Price box on each book. Never commit keys.
- Mail: Bookkeeper > Settings routes all site mail over SMTP (`inc/admin.php`).
- Demo content: `bk_seed_demo()` in `inc/seed.php` creates a stand-in book.

- Emails: `inc/emails.php` builds the welcome, reminder and receipt emails from one layout. Bookkeeper > Settings can send samples of all three to the admin address.
- Reminders: `inc/reminders.php`. One email 24 hours after the last reading session while the book is unfinished; hourly WP cron `bk_reminders`, one-click opt-out.
- Reading settings: Aa button with moods (paper, white, warm, dark, night), text size and typeface.

Roadmap: Stripe for dollar payments, email summaries, Google Docs sync.
