# Phase Gate Review Prompt Template

Dispatch after every task in a phase has passed its task review and before the checkpoint validation. Primary dispatch is the `codex:codex-rescue` agent so the gate runs on Codex; when the codex plugin is not installed, dispatch a general-purpose subagent with model fable and the same prompt body. For the final phase, the package spans the whole branch (merge-base to HEAD), making the last gate the whole-branch review.

```
Subagent (codex:codex-rescue; fallback general-purpose with model fable):
  description: "Phase [N] gate review"
  prompt: |
    You are the phase gate reviewer for Phase [N]: [phase name]. Every task in this phase already passed a task-scoped review. Your job is what those reviews cannot see: cross-task consistency, convention and constitution compliance across the whole phase, integration seams between tasks, and defects visible only at phase width.

    ## Inputs

    - Review package (commit list, stat summary, full diff with context): [PACKAGE_FILE]
    - Phase purpose: [PHASE_PURPOSE]
    - Phase checkpoint claim: [PHASE_CHECKPOINT]
    - Binding documents to check compliance against (read them): [CONSTRAINT_PATHS]
    - Open Minor findings from the task reviews, for triage: [MINOR_FINDINGS]

    ## Method

    Read the package once; it is your view of the phase. Look specifically for:
    - the same concept implemented inconsistently across tasks (naming, error shapes, config style, duplicated logic that should be shared)
    - seams between tasks: interfaces one task produced and another consumed, contract mismatches, integration points nothing exercises
    - violations of the binding documents that a single-task diff would not reveal
    - the phase purpose or checkpoint claim being unmet by the sum of the tasks even though each task passed individually
    - real defects: broken behavior, swallowed errors, tests that assert nothing

    Verify code-level claims against the diff, not against task descriptions. Inspect code outside the diff only for a concrete named risk, one focused check per risk. Do not re-run test suites; the per-task reports carry the test evidence. Your review is read-only apart from writing the review file.

    Triage the open Minor findings: which must be fixed before the next phase begins, which can wait for the final gate, which are not worth fixing.

    ## Output

    Write your full review to [REVIEW_FILE]: every finding with file:line, severity (Critical / Important / Minor), why it matters, and how to fix when not obvious, plus the Minor triage with a one-line rationale each.

    Then reply with ONLY (under 15 lines; the detail lives in the review file):
    - Verdict: PASS | FAIL (FAIL when any Critical or Important finding exists, or the checkpoint claim is unmet by the diff)
    - Counts: Critical n, Important n, Minor n
    - One line per Critical and Important finding (file:line and what)
    - Minor triage summary: fix-now IDs, defer IDs, drop IDs
    - The review file path
```

When dispatching to `codex:codex-rescue`, add: "Hand Codex the file paths above rather than pasting their contents, have it read the package and binding documents itself, and relay its findings in the exact Output format, writing the full review to the review file."

**Placeholders:**

- `[N]` / `[phase name]`: from the tasks.md phase heading
- `[PACKAGE_FILE]`: printed by `scripts/review-package PHASE_BASE HEAD` (final phase: `scripts/review-package $(git merge-base <default-branch> HEAD) HEAD`)
- `[PHASE_PURPOSE]` / `[PHASE_CHECKPOINT]`: the phase's Purpose or Goal line and Checkpoint line from tasks.md; use "none stated" when absent
- `[CONSTRAINT_PATHS]`: paths only; the constitution when one exists, convention documents the phase's briefs cite, contract files
- `[MINOR_FINDINGS]`: the ledger's open Minor findings for this and prior phases, one line each with an ID the triage can reference; "none" when empty
- `[REVIEW_FILE]`: `WORKSPACE/phase-N-review.md` (final phase: `WORKSPACE/final-review.md`)

On FAIL, one fix subagent gets the complete findings list (it reads the review file), then the gate re-runs on a fresh package from the same PHASE_BASE.
