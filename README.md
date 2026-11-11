# WP Auto Tagger

Automatically assigns tags to posts when their title or content matches your keyword rules — so a post about "WordPress speed" gets tagged `WordPress` and `Speed` without you remembering to do it. Includes a bulk retro-tagger for older posts.

## How it works

1. Add rules under **Settings → Auto Tagger**, one per line:

   ```
   wordpress => WordPress
   speed => Speed
   seo => SEO
   ```

2. Every time a post is saved, the plugin scans its title + content and assigns the matching tags. Tags that don't exist yet are created automatically.
3. Matching is **whole-word, case-insensitive and Unicode-aware** by default, so `cat` never matches `catalog`. Untick the option on the settings page to allow substring matches.

## Features

- **Auto-tag on save** — runs on `save_post`, skips autosaves/revisions/trash. Post types filterable via `wat_post_types` (default: `post` only).
- **Meta box** — shows which rules match the current post and a *Re-run auto-tagging* button.
- **Rule tester** — paste a paragraph on the settings page to see which tags it would get, before publishing anything.
- **Bulk retro-tag** — **Tools → Retro Tag Posts** runs the rules over every published post in batches of 100, with automatic continuation. Existing tags are kept; nothing is removed.
- **Filters** — `wat_rules` (override the rule list), `wat_match_whole_word`, `wat_post_types`.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Go to **Settings → Auto Tagger** and add your rules.

## Notes

- Uninstalling deletes the two `wat_*` options. Tags already assigned to posts stay — they are ordinary post tags.
- Rules are matched against the post title and the raw post content (shortcodes included). Matching is cheap: one regex per rule per save.

## License

MIT — see [LICENSE](LICENSE).
