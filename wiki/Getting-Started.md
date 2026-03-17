# Getting Started

## Requirements

- PHP 8.1+
- Composer
- MySQL or compatible database
- Web server pointing to the repository directory

## Setup

1. Install dependencies:

```bash
composer install
```

2. Create local environment file:

```bash
cp .env.example .env
```

3. Edit .env and set at minimum:

- DB_HOST
- DB_USER
- DB_PASS
- DB_NAME
- LLM_PROVIDER
- Provider API key for selected provider (GROQ_API_KEY or OPENROUTER_API_KEY or OPENAI_API_KEY)

4. Start your web server and open the site.

## First-run checks

- Confirm login/register works from chat account panel.
- Send a test message in chat.
- Verify the status page and support page load.
- If using billing, set PayPal values before testing plan flows.

## Fork preview mode

- Set FORK_MODE=1 to force preview behavior.
- In preview mode, root route can redirect to admin preview.
