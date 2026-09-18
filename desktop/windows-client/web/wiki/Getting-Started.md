# Getting Started

## Requirements

- PHP 8.1+
- Composer
- MySQL or compatible database
- Web server pointing to the repository directory

## Install

1. Clone the repository:

```bash
git clone git@github.com:LyraLink-AI/lyralink-ai.git
cd lyralink-ai
```

2. Install dependencies:

```bash
composer install
```

3. Create local environment file:

```bash
cp .env.example .env
```

4. Edit .env and set at minimum:

- DB_HOST
- DB_USER
- DB_PASS
- DB_NAME
- LLM_PROVIDER
- Provider API key for selected provider (GROQ_API_KEY or OPENROUTER_API_KEY or OPENAI_API_KEY)

5. Start your web server and open the site.

## First-run checks

- Confirm login/register works from chat account panel.
- Send a test message in chat.
- Verify the status page and support page load.
- If using billing, set PayPal values before testing plan flows.

## Recommended first provider setup

1. Set LLM_PROVIDER to groq or openrouter.
2. Set matching API key variable.
3. Set a valid model in LLM_MODEL or provider-specific model variable.
4. Send a short test prompt in chat and confirm non-empty response.

## Optional: developer diagnostics

- Login as your configured developer username.
- Use desktop chat view to inspect requested vs actual provider/model route.
- Use this to confirm fallback behavior and token accounting.

## Fork preview mode

- Set FORK_MODE=1 to force preview behavior.
- In preview mode, root route can redirect to admin preview.
