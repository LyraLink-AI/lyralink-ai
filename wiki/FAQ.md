# FAQ

## Is this safe to open source?

Yes, if secrets are not committed. Keep .env out of git and rotate exposed keys immediately.

## Which LLM providers are supported?

Groq, OpenRouter, and OpenAI-compatible routing are supported.

## Can I restrict models by plan?

Yes. Use LLM_ALLOWED_PROVIDERS_* and LLM_ALLOWED_MODELS_* variables by plan tier.

## Does mobile have dedicated behavior?

Yes. Chat has dedicated mobile layout logic, drawer navigation, and compact composer controls.

## Can I run in preview mode for forks?

Yes. Set FORK_MODE=1.

## Where is the extension code?

There is a VS Code extension folder at vscodeextention/lyralink-vscode.
