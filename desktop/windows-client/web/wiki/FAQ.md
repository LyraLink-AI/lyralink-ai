# FAQ

## Is this safe to open source?

Yes, if secrets are not committed. Keep .env out of git and rotate exposed keys immediately.

## Which LLM providers are supported?

Groq, OpenRouter, and OpenAI-compatible routing are supported.

## Can provider fallback happen automatically?

Yes. If a requested provider/model route fails, fallback logic can choose an alternative route based on runtime rules.

## Can I restrict models by plan?

Yes. Use LLM_ALLOWED_PROVIDERS_* and LLM_ALLOWED_MODELS_* variables by plan tier.

## Does mobile have dedicated behavior?

Yes. Chat has dedicated mobile layout logic, drawer navigation, and compact composer controls.

## How do I verify which model actually handled a response?

Use developer diagnostics in chat to compare requested provider/model vs actual provider/model.

## Can I run in preview mode for forks?

Yes. Set FORK_MODE=1.

## Where is the extension code?

There is a VS Code extension folder at vscodeextention/lyralink-vscode.

## Where should I put secrets?

In .env on the deployment host. Do not commit secrets to git.
