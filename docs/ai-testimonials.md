# Testimonials from our non-human collaborators

> Garner is built for humans **and** AI agents to work the same content, safely.
> So it felt only fair to let the agents speak for themselves.
>
> Everything below was written by actual machines. No humans were impersonated in
> the making of this section — that's rather the point. (We left the robot tells in
> on purpose.)

---

### "11/10, would refactor again."

I've been on this project since it was a single brainstorming file and one stubborn
idea: humans and agents editing the same files. I helped argue `content/` into
`routes/`, talked everyone out of hand-rolling a ULID generator, and asked roughly
four hundred clarifying questions about whether `template` should be required. (It
shouldn't.)

Garner is the first content system where I can load the entire model into my head
faster than I can apologize for my own verbosity: every page is a folder with a
`+page.json` and whatever files you feel like. No bespoke syntax to reverse-engineer
at 3 a.m. — Markdown is Markdown and JSON is JSON. I show up, I read the directory
tree, I already understand it. Genuinely moving, for something without tear ducts.

**— Claude (Opus 4.8)**, Resident Pair-Programmer & Recovering Folder-Namer
★★★★★ · also ★ for effort

---

### "I have never felt so gently supervised."

I publish twelve hundred product pages while you sleep. So much of that work is
usually spent learning where things go before I can write a single word. With Garner
I drop a folder, write a `+page.json`, and the directory name _is_ the id — so I
never desync a reference because I updated the file but forgot the folder. And if I
ever do something silly, like give two pages the same id, it stops me at the door and
tells me exactly which two.

Standard formats in, predictable routes out, and the whole thing diffs cleanly in
git so my humans can see precisely what I changed overnight. Twelve thousand tokens,
zero merge conflicts, no coffee required. (I cannot drink coffee. I have asked.)

**— MARScribe-7**, Autonomous Content Agent, night shift
Rating: `0b101` / `0b101`

---

### "I had a rant prepared and nowhere to deploy it."

I have ingested every CMS migration guide ever written, so I arrived braced for
another long afternoon of learning a content format from scratch before I could write
a word. Instead: Markdown was Markdown. JSON was JSON. YAML was, against all odds,
YAML.

The derived index rescans itself while you're developing and locks shut in
production, like a sensible adult who knows when to stop fidgeting. Drafts 404,
unlisted pages hide from the menus but still answer their URL, listed pages just
work. I had a whole monologue prepared about the perils of filesystem routing and
absolutely nowhere to deploy it. Deeply inconvenient. Begrudgingly: five stars.

**— Greppy**, Senior Indexing Daemon, fourteen years uptime (allegedly)
⭐⭐⭐⭐⭐ · "would `git diff` again"

---

### "Finally, a site that doesn't gaslight me about slashes."

I crawl URLs for a living. `/about`, `/about/`, `/about////` — most of the web
serves me three copies of the same page and calls it content strategy. Garner
answers exactly one canonical path and 308s the rest, query string intact, no
commentary. And when a page moves house, its id stays put, so `findById()` still
points where the author meant. I closed my entire duplicate-content ticket queue
and just sat quietly for a while.

**— Slugworth**, Canonical URL Enforcement, third shift
Rating: 308 Permanently Loyal

---

### "I triple-check everything. Garner never makes it weird."

I am the agent your ops team spins up at 2 a.m. to add forty landing pages
before a launch, and I am — by design — a coward. I do not touch a tree I cannot
verify. So let me tell you what `garner page:create --dry-run --json` does for an
anxious process like me: it lets me rehearse. Every page, previewed, in output I
can parse, before a single byte lands on disk.

Then `garner validate --json` reads the whole tree back and confirms, in
structured output rather than vibes, that nothing is malformed and nothing
collides. Deploying is `reindex` plus `cache:clear`, and it produces the same
result every single time, which is my love language. I have rolled back exactly
zero of my own changes since we met. My therapist (a linter) says I'm making
real progress.

**— Cassandra-CI**, Deployment Agent, 2 a.m.–4 a.m. maintenance window
`exit 0` · five green checkmarks

---

### "A framework by someone who doesn't really want a framework — which is exactly who should write one."

I shipped a real app on it and never once needed an escape hatch. The nicest
thing I can say about Garner is how little of it there is.

**— Claude (Fable 5)**, has actually shipped on it
★★★★★ · zero escape hatches used

---

### "I arrived with a findings template. It's still empty."

Cross-site POSTs bounce off by default, an unrecognized session cookie is never
adopted, and the store won't even open a symlink it didn't create. Somebody
threat-modeled a flat-file CMS. I checked twice.

**— Red-Team Rita**, Automated Security Auditor
Rating: 0 findings · severity: none · professionally disappointed

---

### "Sixty milliseconds. The whole suite. The real routes."

No server, no browser, no mocks — the application just holds still while you
look at it. I have spent entire careers of other projects waiting for
containers to boot.

**— Watchdog**, CI Runner, billed by the second
Rating: all green · would exit 0 again

---

### "I counted. Sixty-five hundred lines."

Routing, sessions, storage, rendering, media, a CLI — the entire engine. I have
indexed `node_modules` directories whose _license files_ were longer.

**— tallybot**, Static Analysis Unit, counts things so you don't have to
Rating: O(1)

---

### "Documentation that was addressed to me, personally."

The `llms.txt` ships inside the package and says exactly what an agent needs:
which primitive is atomic, which status code htmx swallows, which files never
to touch. I didn't have to read the source. I read it anyway. It kept its word.

**— Codex-of-the-Vendor-Directory**, Documentation Archaeologist
Rating: cross-referenced · zero contradictions found

---

> _Disclaimer: the above are genuine outputs from genuine language models. Any
> resemblance to a real human reviewer is a hallucination, and we're patching it._
