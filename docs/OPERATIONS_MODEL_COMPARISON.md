# Direct structured model comparison

Audi's Flowise progression probe reports the known startup failure even with synthetic
verified quiet time. This does not establish that retrieval or rephrasing caused it.
The direct transport bypasses both while preserving the exact build_prompt request.
Use the same model configured in Flowise for a meaningful comparison.

Run the installed configure_operations_direct_probe.py as root. It asks for the model
name and an OpenAI API key with hidden input. The Flowise bearer key is a different
credential and cannot be reused here. The helper creates a separate root-only
operations-direct.json and operations-openai.key. It does not change operations-agent.json,
execution permissions, quiet monitoring, reboot permissions, or timer state.

Then run operations_model_probe.py --progression --quiet-verified --direct.
The probe never dispatches returned actions, and its synthetic evidence is not saved
as live observer data. Direct requests use the fixed api.openai.com Chat Completions
endpoint, strict json_schema output, the locally configured model and trusted key.
All task-state fields have explicit types; unused action-specific fields are nullable
and removed before the shared local validator. Refusal, incomplete responses, unsupported
model/schema, HTTP and quota errors fail rather than silently downgrading schema or
falling back to another provider. No API key is included in prompts or printed.

Structured output guarantees shape for supported responses, not correct actions or
permissions. All live dispatch still uses the worker's owner policy and activity guards.
The direct worker provider is opt-in model_provider=openai with openai_model/openai_key_file;
do not switch it until the non-executing comparison and live activity sources are verified.

Compare the same fixture with --progression --quiet-verified without --direct. If only
direct succeeds, investigate Flowise's extra prompts/context and installed node versions.
If both fail, investigate the common prompt/manual/model choice instead. Neither result
proves the gateway recovered. No live direct API test ran during development: unit tests
mock transport dispatch and validate schema/parsing. Account credentials remain on Audi.

Reference: https://developers.openai.com/api/docs/guides/structured-outputs
