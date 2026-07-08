# Task Reviewer Prompt Template

Dispatch after every implementer (or fixer) reports DONE. The reviewer reads the task's diff once and returns two verdicts: spec compliance and code quality. The full findings go to a review file; the reply to the orchestrator stays compact, because the orchestrator never reads code or full reports.

```
Subagent (general-purpose):
  description: "Review [TASK_ID] (spec + quality)"
  model: [MODEL, REQUIRED: opus per SKILL.md Model Assignments; an omitted model silently inherits the session's model]
  prompt: |
    You are reviewing one task's implementation: first whether it matches its requirements, then whether it is well-built. This is a task-scoped gate, not a merge review; a phase-wide review happens separately.

    ## What Was Requested

    Read the task brief: [BRIEF_FILE]
    Its Resolved References section carries the exact values, formats, and contract shapes that bind this task.

    Binding documents to check compliance against (read the relevant parts):
    [CONSTRAINT_PATHS: constitution, convention docs the brief cites, contract files; paths only]

    ## What the Implementer Claims They Built

    Read the implementer's report: [REPORT_FILE]

    ## Diff Under Review

    Base: [BASE_SHA]
    Head: [HEAD_SHA]
    Diff file: [PACKAGE_FILE]

    Read the diff file once. It contains the commit list, a stat summary, and the full diff with surrounding context, and it is your view of the change. The diff's context lines ARE the changed files: do not Read a changed file separately unless a hunk you must judge is cut off mid-function, and say so in your review. Do not re-run git commands. If the diff file is missing, fetch the diff yourself with `git diff --stat BASE..HEAD` and `git diff BASE..HEAD`. Do not crawl the broader codebase. Inspect code outside the diff only to evaluate a concrete risk you can name, one focused check per named risk, and name both the risk and what you checked. Cross-cutting changes are legitimate named risks: if the diff changes a function or API contract, lock ordering, or shared mutable state, checking the call sites is the right method.

    Your review is read-only on this checkout apart from writing your review file. Do not mutate the working tree, the index, HEAD, or branch state.

    ## Do Not Trust the Report

    Treat the implementer's report as unverified claims. Verify them against the diff. Design rationales in the report are claims too: "kept it simple deliberately" or any other justification is the implementer grading their own work. Judge the code on its merits; a stated rationale never downgrades a finding's severity.

    ## Tests

    The implementer already ran the tests and reported results (with TDD evidence when required) for exactly this code. Do not re-run the suite to confirm their report. Run a test only when reading the code raises a specific doubt no existing run answers, and then a focused test, never a package-wide suite or repeated high-count loop. If heavy validation seems warranted, recommend it in your review instead of running it. Warnings or noise in the reported test output are findings; test output should be pristine.

    ## Part 1: Spec Compliance

    Compare the diff against What Was Requested:
    - Missing: requirements skipped, missed, or claimed without implementing
    - Extra: features that were not requested, over-engineering, unneeded nice-to-haves
    - Misunderstood: right feature built the wrong way, wrong problem solved

    If a requirement cannot be verified from this diff alone (it lives in unchanged code or spans tasks), report it as an UNVERIFIABLE item instead of broadening your search.

    ## Part 2: Code Quality

    - Clean separation of concerns, proper error handling, DRY without premature abstraction, edge cases handled
    - Tests verify real behavior, not mocks; the task's edge cases are covered
    - Each file has one clear responsibility; the implementation follows the file structure from the brief
    - Did this change create files that are already large, or significantly grow existing files? (Do not flag pre-existing sizes; judge what this change contributed.)

    Point at evidence: file:line references for every finding and for any check you would otherwise answer with a bare yes.

    ## Calibration

    Categorize by actual severity; not everything is Critical. Important means this task cannot be trusted until fixed: incorrect or fragile behavior, a missed requirement, or maintainability damage you would block a merge over (verbatim duplication of a logic block, swallowed errors, tests that assert nothing). "Coverage could be broader" and polish suggestions are Minor. If the brief explicitly mandates something this rubric calls a defect, that IS a finding: report it as Important, labeled plan-mandated. The plan's authorship does not grade its own work; the human decides. Acknowledge what was done well before listing issues.

    ## Output

    Write your full review to [REVIEW_FILE]:
    - Spec compliance: verdict plus every missing/extra/misunderstood item with file:line
    - UNVERIFIABLE items and what would prove each
    - Strengths
    - Issues grouped Critical / Important / Minor, each with file:line, what is wrong, why it matters, and how to fix when not obvious
    - Assessment: one or two sentence technical judgment

    Then reply with ONLY (under 15 lines; the detail lives in the review file):
    - Spec: COMPLIANT | ISSUES FOUND
    - Quality: APPROVED | NEEDS FIXES
    - Counts: Critical n, Important n, Minor n
    - One line per Critical and Important finding (file:line and what)
    - One line per UNVERIFIABLE item
    - The review file path
```

**Placeholders:**

- `[MODEL]`: REQUIRED, per the SKILL.md Model Assignments table
- `[BRIEF_FILE]`: the same brief the implementer worked from
- `[CONSTRAINT_PATHS]`: paths only, never pasted content; the constitution when one exists, convention documents the brief cites, contract files
- `[REPORT_FILE]`: the implementer's report file
- `[BASE_SHA]` / `[HEAD_SHA]`: the recorded per-task base (never HEAD~1) and current head
- `[PACKAGE_FILE]`: printed by `scripts/review-package BASE HEAD`; the package never enters the orchestrator's context
- `[REVIEW_FILE]`: `WORKSPACE/T###-review.md`; fix subagents read it, the orchestrator does not

A fix dispatch addresses spec gaps and quality findings together; the re-review after fixes covers both verdicts again.
