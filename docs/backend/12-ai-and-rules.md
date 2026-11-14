# AI and the rules that replace it

Most checks run on plain rules now. Claude is only called where Settings > AI usage (`admin/ai-usage.php`, owner only) turns it on, and never past the daily budget.

## The rules (`inc/rules.php`)

- `rs_rules_kind`: recomp, emulator, tool, fan game or unsure, from the repo's name, description, README and topics (word lists at the top of the file).
- `rs_rules_origin`: the console from names in the text, mapped to `RS_PC_ORIGINS`.
- `rs_rules_fill`: what Auto-add's page step needs (name, game, origin, blurb, description, tags, verdict, notes), shaped like Claude's answer. It takes the submission's own answers first, then the README's title and first paragraphs.
- `rs_rules_check`: for **Check it** on Requests. Returns `go`, `soon` (no Windows download yet), `skip` (an emulator, tool or fan game), `listed` or `look` (unsure, or the repo can't be read), with the reasons.

Easy Setup and launcher settings (Auto-add steps 7 and 8) need Claude; without it they're skipped with a note, and the boot profile can be filled in by hand or by the developer's recomplauncher.json.

## Which features may use Claude (`inc/aiuse.php`)

| Feature | Key | Default |
|---|---|---|
| Auto-add page text | `autoadd` | off (rules) |
| Daily scout sorting | `scout` | off (rules) |
| Translating players' notes | `translate` | Haiku |
| PR drafter | `prq` | Sonnet |
| **Write the page with AI** on a draft | `ondemand` | Haiku |

`rs_ai_model_for($feature)` gives the model id or `''` for off. Settings are in the `ai_cfg` setting (modes, daily budget, prices per million tokens).

## Counting and the budget

Every call goes through `rs_ai_json` (or the PR drafter's own client, which sends its totals back through `api/prq.php`). Before a call, `rs_ai_budget_ok()` throws once today's spend (UTC) reaches the budget (default $3, 0 = no limit). After a call, `rs_ai_record` adds its tokens to `ai_usage` (day, feature, model, calls, tokens in, out, cache read, cache write). Costs are worked out from those tokens and the prices on the page, so they're an estimate; the Anthropic console has the bill. Counting started on Nov 14, 2026.

The page shows today, 7 and 30 days, the budget left, the last 14 days and each feature's spend, and holds the switch for starting Auto-add as soon as a PC submission arrives (`sub_autostart`).
