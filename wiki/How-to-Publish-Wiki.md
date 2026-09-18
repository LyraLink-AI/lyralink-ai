# How to Publish Wiki

GitHub Wikis are stored in a separate git repository ending in .wiki.git.

For this repository, wiki remote URL is:

- git@github.com:LyraLink-AI/lyralink-ai.wiki.git

## Recommended SSH setup (works reliably)

Use an account-level GitHub SSH key (not only a deploy key) for wiki pushes.

1. Generate a dedicated key on the server:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_lyralink_wiki_user -C "lyralink-wiki-user@server" -N ""
```

2. Add this to ~/.ssh/config:

```sshconfig
Host github.com-lyralink-wiki-user
	HostName github.com
	User git
	IdentityFile ~/.ssh/id_ed25519_lyralink_wiki_user
	IdentitiesOnly yes
```

3. Add the public key to GitHub user settings:

- GitHub -> Settings -> SSH and GPG keys -> New SSH key
- Paste ~/.ssh/id_ed25519_lyralink_wiki_user.pub

4. Verify auth:

```bash
ssh -T git@github.com-lyralink-wiki-user
```

Expected: authentication success message (no shell access).

## Publish steps

1. Clone the wiki repo:

```bash
git clone git@github.com-lyralink-wiki-user:LyraLink-AI/lyralink-ai.wiki.git
```

2. Copy files from this repository wiki folder into the cloned wiki repo root:

- Home.md
- Getting-Started.md
- Configuration.md
- Architecture.md
- API-Reference.md
- Troubleshooting.md
- FAQ.md
- _Sidebar.md

3. Commit and push:

```bash
git add .
git commit -m "Add initial wiki"
git push origin master
```

If your wiki default branch is main, use:

```bash
git push origin main
```

## Notes

- GitHub wiki page names map to filenames.
- Home.md is the wiki landing page.
- Internal links use wiki syntax like [[API Reference]].

## If push still fails

- Confirm the GitHub user with this key has write access to LyraLink-AI/lyralink-ai.
- Confirm repository Wiki feature is enabled.
- Re-run: ssh -T git@github.com-lyralink-wiki-user
- Test remote directly:

```bash
git ls-remote git@github.com-lyralink-wiki-user:LyraLink-AI/lyralink-ai.wiki.git HEAD
```
