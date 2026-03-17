# How to Publish Wiki

GitHub Wikis are stored in a separate git repository ending in .wiki.git.

For this repository, wiki remote URL is:

- git@github.com:LyraLink-AI/lyralink-ai.wiki.git

## Publish steps

1. Clone the wiki repo:

```bash
git clone git@github.com:LyraLink-AI/lyralink-ai.wiki.git
```

2. Copy files from this repository wiki folder into the cloned wiki repo root:

- Home.md
- Getting-Started.md
- Configuration.md
- API-Reference.md
- Troubleshooting.md
- FAQ.md
- _Sidebar.md

3. Commit and push:

```bash
git add .
git commit -m "Add initial wiki"
git push origin main
```

## Notes

- GitHub wiki page names map to filenames.
- Home.md is the wiki landing page.
- Internal links use wiki syntax like [[API Reference]].
