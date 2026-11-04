## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

graphify is not on PATH here: run it as `C:/Users/front/.venvs/graphify/Scripts/graphify.exe` (version 0.9.102, its own venv).
The graph is code-only and built without any LLM backend, so student data never leaves this PC: rebuild with
`GRAPHIFY_BACKEND=none graphify extract . --code-only`; `.graphifyignore` keeps output/, storage/, databases and spreadsheets out.
Laravel calls made through `app(Service::class)` are not linked as callers, so confirm "who calls this" with a search.

Rules:
- When graphify-out/graph.json exists, run `graphify query "<question>"` before your first grep, search or source-file read for a codebase question, and put this rule in every subagent prompt that explores code. Read raw files only for lines the graph or an earlier read already located. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
- A committed graphify-out/graph.json is rewritten whole on every build, so a large diff to it in a pull request is expected. Do not flag it as churn or suggest skipping the graph rebuild or its hooks.
