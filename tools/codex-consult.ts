import { tool } from "@opencode-ai/plugin"

export default tool({
  description:
    "Ask Codex for a read-only expert engineering consultation using the existing authenticated Codex CLI. Use only after serious local investigation when stronger technical reasoning is justified. Codex cannot edit the worktree through this tool.",

  args: {
    task: tool.schema
      .string()
      .min(20)
      .describe(
        "A concise self-contained engineering handoff: objective, relevant files, constraints, observed/expected behaviour, failing tests, local attempts, current hypothesis, and the specific question for Codex."
      ),
  },

  async execute(args, context) {
    const prompt = `
You are an expert engineering consultant.

You are being invoked from a local-first development workflow because local
agents have already investigated the problem and stronger technical reasoning
is justified.

Repository worktree:
${context.worktree}

You are in READ-ONLY consultation mode.

Do not modify files.
Do not create files.
Do not commit.
Do not push.
Do not make product decisions that are unresolved in project documentation.

Read AGENTS.md and any directly relevant repository documentation before drawing
conclusions. Treat CURRENT_STATE.md as implemented reality and PRODUCT_SPEC.md
as intended product behaviour. Respect DECISIONS.md boundaries.

ENGINEERING HANDOFF

${args.task}

Your job:

1. Inspect the relevant repository files yourself where useful.
2. Validate or reject the local agents' current hypothesis.
3. Identify the root cause or best technical approach.
4. Explain important correctness, data-safety, concurrency, security or
   architectural concerns.
5. Recommend the smallest robust implementation approach.
6. Identify specific files/symbols likely to require changes.
7. Identify tests that should prove the solution.
8. Explicitly distinguish technical conclusions from any unresolved product
   decision.

Do not implement the change.

Return a concise engineering consultation structured as:

ROOT CAUSE / CONCLUSION
RECOMMENDED APPROACH
FILES / SYMBOLS
TESTS
RISKS / CAVEATS
OWNER DECISION NEEDED (if any)
`.trim()

    const proc = Bun.spawn(
      ["codex", "exec", "--ephemeral", "--sandbox", "read-only"],
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
        `Codex consultation failed with exit code ${exitCode}.`,
        stderr.trim(),
        stdout.trim(),
      ]
        .filter(Boolean)
        .join("\n\n")
    }

    const result = stdout.trim()

    if (!result) {
      return [
        "Codex consultation completed but returned no final stdout.",
        stderr.trim(),
      ]
        .filter(Boolean)
        .join("\n\n")
    }

    return result
  },
})
