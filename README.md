# The Bookkeeper

A Banks Solution WordPress plugin: a paid, members-only book reader with chapters split into short sections, highlights with sticky notes, reflections, action points, a journal and progress tracking.

- Reader: `/read/{book-slug}/`, a standalone page (`inc/reader.php`, `assets/reader.js`, `assets/reader.css`).
- REST API: `bookkeeper/v1` (`inc/rest.php`). Data lives in the `bk_marks`, `bk_entries` and `bk_progress` tables (`inc/install.php`).
- Content: `bk_book` and `bk_chapter` post types. Each H2 starts a section. Give a paragraph or group the class `bk-reflect`, `bk-action` or `bk-quote` to make it interactive.
- Access: `inc/access.php`. Admins own every book; grant others under Bookkeeper > Readers until payments ship.
- Mail: Bookkeeper > Settings routes all site mail over SMTP (`inc/admin.php`).
- Demo content: `bk_seed_demo()` in `inc/seed.php` creates a stand-in book.

Roadmap: Paystack and Stripe checkout (NGN and USD), reminders every 22 hours, email summaries, Google Docs sync.
