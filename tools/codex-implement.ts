import { tool } from "@opencode-ai/plugin"

export default tool({
  description:
    "Escalate a bounded difficult implementation task to the authenticated Codex CLI. Codex may edit the current worktree in workspace-write/full-auto mode. Use only after local-first attempts or when high-risk technical reasoning genuinely requires frontier implementation.",

  args: {
    task: tool.schema
      .string()
      .min(20)
      .describe(
        "A concise self-contained implementation handoff: objective, relevant files, constraints, observed/expected behaviour, failing tests, local attempts, current hypothesis, and exact desired outcome."
      ),
  },

  async execute(args, context) {
    const prompt = `
You are the expert implementation escalation engineer.

You are being invoked from a local-first development workflow after cheaper
local agents have already investigated or attempted this task.

Repository worktree:
${context.worktree}

You may edit files inside this worktree and run appropriate repository commands.

Do NOT:
- commit changes;
- push changes;
- rewrite Git history;
- force-reset the repository;
- make destructive database changes not explicitly authorized;
- run migrate:fresh, db:wipe, or sail down -v against an existing development
  environment;
- invent unresolved product behaviour.

Read AGENTS.md before editing.

Respect these repository document roles:
- PRODUCT_SPEC.md = intended product behaviour
- ROADMAP.md = task scope/dependencies/risk
- DECISIONS.md = decided and unresolved product boundaries
- CURRENT_STATE.md = currently implemented reality
- DEFINITION_OF_DONE.md = completion evidence
- DOMAIN_MODEL.md and feature-specific docs = architecture where applicable

If the handoff conflicts with an authoritative project document, investigate the
conflict. Do not silently choose a new product requirement.

IMPLEMENTATION HANDOFF

${args.task}

Required behaviour:

1. Inspect the relevant repository state yourself.
2. Determine the root cause / implementation strategy before editing.
3. Make the smallest coherent change that satisfies the stated task.
4. Preserve unrelated existing behaviour.
5. Add or update focused automated tests.
6. Use the project's Laravel Sail workflow from AGENTS.md.
7. Run focused tests after changes.
8. Run additional applicable checks when practical, but do not claim checks you
   did not actually run.
9. Inspect your final diff.
10. Leave all changes uncommitted for the parent workflow to verify independently.

If you discover that the requested behaviour actually requires an unresolved
product-owner decision, stop that portion of implementation and clearly report
the decision required rather than inventing it.

At the end return:

ROOT CAUSE / RATIONALE
FILES CHANGED
IMPLEMENTATION
TESTS / CHECKS RUN
RESULTS
REMAINING RISKS
OWNER DECISION NEEDED (if any)

The parent workflow will independently verify your work, so be precise about
what you actually ran and observed.
`.trim()

    const proc = Bun.spawn(
      ["codex", "exec", "--ephemeral", "--full-auto"],
      {
        cwd: context.worktree,
        stdin: "pipe",
        stdout: "pipe",
        stderr: "pipe",
      },
    )

    proc.stdin.write(prompt)
    proc.stdin.end()

    const [stdout, stderr, exitCode] = await Promise.all([
      new Response(proc.stdout).text(),
      new Response(proc.stderr).text(),
      proc.exited,
    ])

    if (exitCode !== 0) {
      return [
        `Codex implementation escalation failed with exit code ${exitCode}.`,
        stderr.trim(),
        stdout.trim(),
      ]
        .filter(Boolean)
        .join("\n\n")
    }

    const result = stdout.trim()

    if (!result) {
      return [
        "Codex implementation completed but returned no final stdout.",
        stderr.trim(),
      ]
        .filter(Boolean)
        .join("\n\n")
    }

    return result
  },
})
