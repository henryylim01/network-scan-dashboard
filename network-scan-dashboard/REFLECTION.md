# Project Reflection

This document covers the real challenges I ran into while building and hardening this network scanner, how I diagnosed them, and what I'd do differently next time. Most of this project didn't go wrong at the "write the code" stage — it went wrong (and got interesting) at the "verify the code actually does what it claims" stage.

## 1. Discovering that "done" didn't always mean done

The project started from notes describing work completed in an earlier session with a senior developer friend. Early on, I assumed everything listed there was already implemented and just needed light verification. That assumption turned out to be wrong in several places:

- A `.env` file for network configuration was described as created — it didn't exist. The actual network range had been placed in `scanner.conf` instead, and the `.env` language in the notes just didn't match what was really built.
- `git` wasn't installed on the machine at all, despite `.gitignore` protections being described as already in place.
- `curl` wasn't installed either, which I only discovered when trying to verify the dashboard was reachable.
- The git repository itself had never been initialized — there was no version control protecting sensitive files, despite that being listed as complete.

**What I learned:** a description of work is not the same as verification of work. From that point on, I stopped trusting "this was already fixed" and started running a command to check, every time. This became the actual theme of the whole project — not writing new code, but confirming claimed state against real state.

## 2. The `umask 002` permission leak

While reviewing `network_scan.sh`, I found it set `umask 002` at the top of the script. This meant every file the script created — the nmap XML output, the log file, the lockfile — inherited group- and world-writable permissions by default. This directly undermined other permission work done elsewhere in the project (like locking `scanner.conf` down to `640`), because a script running as root with a loose umask was quietly creating loosely-permissioned files right next to it.

**Fix:** changed `umask 002` to `umask 027`, so new files default to owner read/write, group read-only, and no access for "other" — consistent with the rest of the project's permission scheme.

**What I learned:** a single overlooked line at the top of a script can quietly undo permission fixes made everywhere else. Permissions need to be checked at the point files are *created*, not just audited after the fact.

## 3. A genuine concurrency bug: the lockfile race condition

The original locking logic in `network_scan.sh` looked like this:
```bash
if [ -e "$LOCKFILE" ]; then
    exit 1
fi
touch "$LOCKFILE"
```
This has a classic time-of-check-to-time-of-use (TOCTOU) race: two near-simultaneous script runs could both check for the lockfile, both find it absent, and both proceed to create it — defeating the whole point of the lock. At a 5-minute cron interval this was unlikely to actually trigger in practice, but it was a real correctness bug, not a hypothetical one.

**Fix:** replaced the check-then-create pattern with an atomic `mkdir`-based lock. `mkdir` fails immediately if the directory already exists, so the check and the creation happen as a single atomic operation instead of two separate steps that can race.

**What I learned:** "it probably won't happen often" isn't the same as "it's correct." Atomic operations exist specifically to remove this class of bug, and it's worth using them even when the failure window seems small.

## 4. The risk-level bug: the most interesting one

The dashboard displayed "Unknown" for every single row's risk level, no matter what. My first instinct was to suspect the scan or parsing layer — maybe `nmap -F` wasn't gathering enough data, or maybe the XML parser was failing silently.

I worked through that theory methodically: checked the raw nmap XML output directly, checked the Python parser's risk-classification logic, and even checked the database directly with `sqlite3` — and found that the *data was completely correct* at every stage. Real ports, real risk labels, correctly stored.

The actual bug was one layer higher, and much simpler than I'd been assuming: the SQLite schema had **two nearly-identical columns**, `risk` and `risk_level`. The Python parser only ever wrote to `risk`. The PHP dashboard only ever read from `risk_level`. Since `risk_level` was never populated, it was always `NULL`, and PHP's null-coalescing operator (`?? 'Unknown'`) quietly displayed "Unknown" for every row — masking what was actually a correctly functioning data pipeline behind a display-layer bug.

**Fix:** updated all six references in `index.php` (two `SELECT` queries and four places using the value for display and CSS class assignment) to read from `risk` instead of `risk_level`.

**What I learned:** when something looks broken end-to-end, check each layer independently before assuming the failure is where you first suspect it. I nearly "fixed" this by tweaking nmap flags, which would have done nothing, because the actual bug was a naming mismatch between two files that never talked to each other explicitly — it only surfaced because I checked the raw data at each stage instead of trusting my first theory.

## 5. A CSS specificity conflict, right after fixing the bug above

Once the risk data was displaying correctly, a new, more subtle problem appeared: "Low Risk" rows weren't consistently showing their blue background — only some of them were colored correctly.

The cause was CSS specificity, not data: the dashboard used `tr:nth-child(even)` for row striping, and separately `.risk-low` / `.risk-none` etc. for risk-based coloring. `tr:nth-child(even)` has slightly higher specificity than a bare class selector, so it silently won on every even-numbered row, overriding the risk color.

**Fix:** changed the risk selectors to `tr.risk-low` (element + class combined) to reliably outweigh the striping rule. This then broke the color *legend* at the top of the page, since the legend used `<span>` elements with the same class names, and the new `tr.risk-low` selector no longer matched a `<span>`. Final fix combined both: `tr.risk-low, span.risk-low { ... }`, applying the same colors to both element types independently.

**What I learned:** CSS specificity bugs are easy to miss because they don't throw errors — they just silently produce the "wrong but plausible-looking" result. And fixes can have second-order effects: solving the row-coloring problem broke the legend, which required checking the *other* place the same class names were used before calling it done.

## 6. Bonus Task B: switching networks revealed a two-layer access control gap

For the bonus task, I switched my laptop (and, via Bridged networking, the VM) onto a phone hotspot to demonstrate scanning a different subnet and reaching the dashboard from outside the VM. This immediately surfaced something I hadn't fully appreciated: I had restricted dashboard access at **two separate layers** — a `ufw` firewall rule and an Apache `Require ip` directive — both scoped to my home subnet only.

When I switched networks, both layers blocked me, in sequence: first `ufw` (silently dropped the connection, browser timeout), then, after opening `ufw`, Apache itself (`403 Forbidden`, since `Require ip` was still scoped to the old subnet). I had to update both independently before access worked, and then remember to revert both afterward.

**What I learned:** defense-in-depth (multiple independent layers of access control) is good security practice, but it also means more places to update — and more places to *forget* to update — whenever the environment changes. It's not enough to know "I locked this down"; I need to know *how many places* I locked it down in, so I can find all of them again later. I made a point of explicitly listing both layers before reverting them, rather than relying on memory.

## 7. The final review pass caught real bugs — even after I thought the project was done

Once the core project felt finished, I went back through it specifically to gather evidence for the submission report — screenshots of crontab, firewall rules, fail2ban status, git history, and so on. I expected this to be a documentation exercise. It wasn't. Reviewing my own "finished" system with a critical eye caught two genuine, unnoticed problems:

**A leftover firewall rule that contradicted my own report.** While capturing a `ufw status verbose` screenshot as evidence for Bonus Task B, I found that `ufw` was allowing ports 80 and 443 from "Anywhere," sitting alongside the correct rule scoped to my home subnet. Both rules were active at the same time. This traced back to the very first `ufw limit 80/tcp` / `ufw limit 443/tcp` commands from early in the project — that syntax defaults to "Anywhere" when no subnet is specified with `from`. Later, for Bonus Task B, I added the correct subnet-scoped version but never went back and removed the original broad one. My report's Bonus B section explicitly claimed the dashboard was "only reachable from the local subnet" — which was not actually true on the live system until this was caught and fixed.

**A file that was documented as version-controlled but never actually was.** While double-checking that my tracked script copies matched what was actually running, I discovered `index.php` — the dashboard itself — had never been added to the git repository at all. My README described it as part of the tracked project structure, and I genuinely believed it was covered by an earlier "add scripts under version control" commit, but that commit only ever included the shell/Python scripts, not the PHP dashboard.

**What I learned:** the discipline of verifying claims against reality — the theme that ran through the entire project — applies just as much to your own finished, "done" work as it does to someone else's code or an AI's suggestion. I had genuinely convinced myself both of these were handled, because I remembered *intending* to do them and remembered similar-sounding work being done nearby (adding other scripts to git, setting up the correct ufw subnet rule). It took actually running the verification command — not just recalling the general shape of what I'd done — to find the gap. If I hadn't gone back to gather report evidence, both of these would likely have shipped unnoticed, since neither caused any visible symptom in day-to-day use of the dashboard.



- **Verify, don't trust.** The single biggest recurring theme of this project was the gap between "this should be done" and "this is actually configured on the system." Nearly every real bug I found — the missing `.env`, the missing git repo, the `risk_level` mismatch — was invisible until I ran an actual command to check.
- **Test end-to-end, not just component-by-component.** The permissions could be correct, the parser could be correct, and the dashboard could still show wrong data, because the bug was in how two correct pieces were wired together.
- **Fixes can have side effects.** The CSS fix for table rows broke the legend. Reverting network access required touching two separate config layers, not one. Nothing in this project was ever a single isolated change — everything needed a follow-up check.
- **"I finished this" needs the same skepticism as "this was already done."** The two bugs caught during the final review pass (section 7) existed in work I genuinely believed was complete. Confidence that something is done is not evidence that it's done — only re-running the actual check is.
- **Using an AI assistant productively meant not accepting its first answer as final** — the most valuable moments were re-testing claims (mine and the assistant's) against the actual running system, rather than assuming a plausible-sounding explanation was the correct one.
