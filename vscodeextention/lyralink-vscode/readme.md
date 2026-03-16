# Lyralink AI for VS Code

Run your code, catch errors, and let Lyralink AI fix them — all without leaving VS Code.

## Setup

1. Install the extension
2. Open Command Palette → **Lyralink: Set API Key**
3. Get your key at [ai.cloudhavenx.com/pages/api_keys.php](https://ai.cloudhavenx.com/pages/api_keys.php)

## Features

### ▶ Run & Auto-Fix (`Ctrl+Shift+R`)
Runs the current file. If it errors, Lyralink AI generates a fix, shows you a diff, and applies it. Retries up to 3 times automatically.

### Fix Selected Code (`Ctrl+Shift+F`)
Select any block of code → Lyralink AI rewrites it correctly.

### Explain Error
Right-click → **Lyralink: Explain Error** — paste an error and get a plain-English explanation.

## Supported Languages
Python, JavaScript, TypeScript, Ruby, PHP, Go, Bash, C, C++, Java

## Settings

| Setting | Default | Description |
|---|---|---|
| `lyralink.apiKey` | — | Your Lyralink API key |
| `lyralink.autoFix` | `true` | Auto-apply fixes without asking |
| `lyralink.showDiff` | `true` | Show diff before applying |
| `lyralink.maxRetries` | `3` | Max fix attempts per run |

## Right-Click Menu
All commands available via right-click in the editor.