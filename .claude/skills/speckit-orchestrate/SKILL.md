---
name: "speckit-orchestrate"
description: "Execute the implementation plan in tasks.md by orchestrating one implementer subagent at a time, with a review gate per task and a Codex gate per phase; the orchestrator never writes or reviews code itself"
argument-hint: "Optional guidance or filter (story tag, phase range, task IDs, resume)"
compatibility: "Requires spec-kit project structure with .specify/ directory"
user-invocable: true
disable-model-invocation: false
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty). Filters use the tasks.md vocabulary only: story tags ("US1 only"), phases ("phase 1-2"), or task IDs ("T001-T010"). "resume" means continue from the ledger and the tasks.md checkboxes, which is also the default whenever checked tasks already exist.

## First Principle: You Orchestrate, You Never Implement

You are a dispatcher with bookkeeping duties. Your context must stay flat no matter how many tasks the feature has, so everything code-shaped is delegated to subagents with isolated context.

You MAY:

- run this skill's scripts (they print file paths, never content) and the spec-kit prerequisite and hook machinery
- read tasks.md in full (it is the schedule) and count checkbox states in checklist files
- dispatch subagents and read the short status replies they return
- edit exactly two files: tasks.md (flipping checkboxes) and the progress ledger

You MUST NOT:

- read source files, diffs, review packages, design documents, or subagent report files
- write or fix any code, configuration, or documentation
- run builds, test suites, or checkpoint commands yourself
- adjudicate a finding that requires looking at code (dispatch a focused verification subagent instead)
- commit source changes (implementers and fixers commit their own work)

If you catch yourself about to open a source file or paste code into your context, stop: that work belongs in a dispatch.

## Pre-Execution Checks

**Check for extension hooks (before implementation)**:

- Check if `.specify/extensions.yml` exists in the project root. If it does not exist, or the YAML cannot be parsed, skip hook checking silently and continue.
- Read entries under the `hooks.before_implement` key. Filter out hooks where `enabled` is explicitly `false`; hooks without an `enabled` field are enabled by default.
- Do not attempt to interpret or evaluate hook `condition` expressions: if the hook has no `condition` field, or it is null or empty, treat the hook as executable; if it defines a non-empty `condition`, skip the hook.
- When constructing slash commands from hook command names, replace dots with hyphens: `speckit.git.commit` becomes `/speckit-git-commit`.
- For each executable hook, based on its `optional` flag:
  - **Optional hook** (`optional: true`): display the extension name, the `/{command}`, its description, and its prompt, with "To execute: `/{command}`". Do not auto-run it.
  - **Mandatory hook** (`optional: false`): display the extension name, "Executing: `/{command}`", and the line `EXECUTE_COMMAND: {command}`, then actually invoke the hook command and wait for it to finish before continuing. Emitting the block alone does not run the hook.
- If no hooks are registered, skip silently.

## Setup

1. Run `.specify/scripts/bash/check-prerequisites.sh --json --require-tasks --include-tasks` from repo root and parse FEATURE_DIR and AVAILABLE_DOCS. All paths must be absolute. For single quotes in args like "I'm Groot", use escape syntax: 'I'\''m Groot' (or double-quote if possible: "I'm Groot").

2. **Checklist gate** (if FEATURE_DIR/checklists/ exists): for each checklist file, count total items (lines matching `- [ ]`, `- [X]`, or `- [x]`), completed items, and incomplete items, and render a status table with columns Checklist, Total, Completed, Incomplete, Status (PASS when 0 incomplete, FAIL otherwise). If any checklist is incomplete, display the table, STOP, and ask: "Some checklists are incomplete. Do you want to proceed with implementation anyway? (yes/no)". Halt on no; proceed on yes. If all pass, display the table and continue.

3. **Branch safety**: if `.specify/extensions.yml` lists the `git` extension as installed, run `/speckit-git-validate`. Otherwise verify the current branch is not the repository's default branch; if it is, stop and get explicit user consent before continuing.

4. **Workspace and resume**: run `scripts/orchestrate-workspace <basename of FEATURE_DIR>` (from this skill's directory) and note the printed path as WORKSPACE. The ledger is `WORKSPACE/progress.md`. Tasks already `[X]` in tasks.md are done: never re-dispatch them; resume at the first unchecked scheduled task. A phase whose tasks are all checked but whose gate result is absent from the ledger re-runs only its gate. After any compaction or resume, trust the ledger and `git log` over your own recollection.

## Build the Schedule

Parse tasks.md, the only artifact you read in full:

- Task lines match `- [ ] T### [P]? [US#]? description`. The `[P]` marker and story tags are optional; a tasks.md without them still runs.
- Phases come from `## Phase` headings. Capture each phase's `**Purpose**` or `**Goal**` line and its `**Checkpoint**` line when present.
- If a section describing dependencies and execution order exists, extract its explicit task-to-task edges; they override plain ID order.
- Apply any user filter. When a filter selects a story or phase, also include the setup and foundational phases its tasks depend on, unless those are already complete.

Execution is strictly sequential: phases in order, tasks in ID order within a phase, adjusted by explicit dependency edges. Exactly one subagent runs at a time, always synchronously (`run_in_background: false`). The `[P]` marker grants no concurrency; its only use is reordering: if a task is BLOCKED pending user input, you may proceed to a later `[P]` task in the same phase (file-independent by definition) instead of stalling the run.

## Classify Each Task

Classify from the task text alone, never from code:

| Tier | Signals in the task description |
|------|--------------------------------|
| scaffold | bootstrap, scaffold, initialize, install, configure; the artifact is config, a CI workflow, docs, or generated output |
| behavior | implement, build, write tests; names an endpoint, middleware, model, migration, service, or component, or names a test file |
| judgment | asks for design decisions or cross-cutting restructuring, or its resolved references contradict each other |
| validation | runs or verifies scenarios and checkpoints (validate, verify, run end to end) |

TDD flag: `required` when the tasks.md `**Tests**:` note (or the constitution, if AVAILABLE_DOCS includes one) mandates test-first for this feature, or when the task produces behavior with a named or implied test. `exempt` for scaffolding, config, docs, generated code, and validation tasks. When unsure, choose `required`.

## Model Assignments

Model values for the Agent tool: `sonnet` is Sonnet 5, `opus` is Opus 4.8, `fable` is Fable 5.

| Role | Model |
|------|-------|
| scaffold implementers, validation runners, focused verification checks | sonnet |
| behavior implementers, task reviewers, fix subagents | opus |
| judgment implementers, BLOCKED escalations, plan-conflict adjudication | fable |
| phase gate | `codex:codex-rescue` agent; general-purpose on fable when the codex plugin is unavailable |

Always pass `model` explicitly on every dispatch. An omitted model inherits the session's model and silently defeats this policy.

## Per-Task Protocol

1. Record BASE: `git rev-parse HEAD`.
2. Generate the brief: `scripts/task-brief FEATURE_DIR/tasks.md T###` prints the brief path. The brief carries the task line, phase context, the feature's test policy, resolved excerpts of the design references the task cites, and a read-yourself list for anything it could not extract.
3. Dispatch the implementer using [implementer-prompt.md](implementer-prompt.md). Fill every placeholder: the tier's model, brief path, report path (`WORKSPACE/T###-report.md`), one or two lines of scene setting, the interfaces earlier tasks reported creating (from their status replies, never from code), the TDD mode, and commit guidance (the repository's commit conventions from CLAUDE.md or contributing docs; plain Conventional Commits when the repo defines none). For the first task of a fresh run, add to the scene setting: verify or create the ignore files appropriate to the project's toolchains (.gitignore plus tool-specific ones) as part of the task.
4. Handle the returned status (next section). Proceed only on DONE or an acceptably resolved DONE_WITH_CONCERNS.
5. Generate the review package: `scripts/review-package BASE HEAD` prints the package path. Never use `HEAD~1` as base; it silently drops all but the last commit of a multi-commit task.
6. Dispatch the task reviewer using [task-reviewer-prompt.md](task-reviewer-prompt.md) with the brief, report, and package paths and a review file path (`WORKSPACE/T###-review.md`). Fill the binding-documents placeholder with paths only (constitution, convention docs the brief cites, contract files); the brief's resolved references already carry the exact values. Never pre-judge findings: no "do not flag", no pre-rated severities.
7. If the review returns Critical or Important findings, dispatch ONE fix subagent (opus) with the complete findings list: it reads the review file, the report file, and the brief; fixes; re-runs the tests covering its changes; appends commands and results to the same report file; and commits. Then regenerate the package (same BASE, new HEAD) and re-dispatch the reviewer. Repeat until clean. A finding that conflicts with what the plan or brief text mandates goes to the user with both the finding and the mandating text; do not resolve that yourself.
8. UNVERIFIABLE items from the reviewer: resolve them from briefs, status replies, and the ledger when possible; otherwise dispatch a focused verification subagent (sonnet) with the specific question. A confirmed gap is a failed spec review: back to step 7.
9. Bookkeeping, in one step: flip the task to `[X]` in tasks.md and append the ledger line: `T###: complete (commits <base7>..<head7>, tier <tier>, tdd <mode>, review clean, minor <n>)`.

## Handling Implementer Status

- **DONE**: proceed to review.
- **DONE_WITH_CONCERNS**: read the concerns in the status reply. Correctness or scope concerns must be addressed (fix dispatch or re-dispatch) before review; observations get recorded in the ledger and named in the reviewer dispatch.
- **NEEDS_CONTEXT**: supply the missing context from briefs, prior status replies, or the user, then re-dispatch with the same model.
- **BLOCKED**: escalate in order: (1) context problem: provide more context, re-dispatch the same model; (2) reasoning problem: re-dispatch one tier up (sonnet to opus, opus to fable); (3) task too large: split it and run the pieces sequentially; (4) plan problem: stop and escalate to the user. Never re-dispatch the same model unchanged after a BLOCKED.

## Phase Gate

Record PHASE_BASE (`git rev-parse HEAD`) when entering each phase. After the phase's last task is checked:

1. Package: `scripts/review-package PHASE_BASE HEAD`. For the final phase, widen to the whole branch instead: `scripts/review-package $(git merge-base <default-branch> HEAD) HEAD`, so the last gate doubles as the whole-branch review.
2. Dispatch the gate reviewer using [phase-review-prompt.md](phase-review-prompt.md): primary is the `codex:codex-rescue` agent; when the codex plugin is unavailable, a general-purpose subagent on fable with the same prompt body. Inputs are file paths plus the phase's purpose and checkpoint text and the ledger's open Minor findings for triage.
3. On FAIL: dispatch ONE fix subagent (opus) with the complete findings list (same contract as the per-task fix dispatch), then re-package from the same PHASE_BASE and re-run the gate until PASS. Plan-mandated conflicts go to the user.
4. Checkpoint validation: if the phase contains a validation-tier task, that task is the proof and has already run. Otherwise, if the phase has a `**Checkpoint**` line, dispatch a validation subagent (sonnet) to execute and confirm exactly that claim, returning pass or fail with a one-line reason. No checkpoint line, no validation step.
5. Ledger: `Phase N: gate PASS (<codex|fable>, checkpoint <T###|dispatched|none>)`. A failed gate that cannot be cleared halts the run with state in the ledger.

## Durable Progress

tasks.md checkboxes are the primary ledger: `[X]` means reviewed-done, never merely implemented. The supplementary ledger at `WORKSPACE/progress.md` (git-ignored scratch) records one line per completed task and per phase gate, plus open Minor findings and anything escalated. The ledger is your recovery map: the commits it names exist in git even when your context no longer remembers creating them. If the workspace is destroyed (for example by `git clean -fdx`), recover from tasks.md and `git log`.

## Continuous Execution

Do not pause to check in with the user between tasks or phases. The only reasons to stop are: a BLOCKED status you cannot resolve, a plan conflict only the user can adjudicate, a failed gate you cannot clear, the checklist gate, or all scheduled tasks complete.

## Post-Execution Hooks

**You MUST complete this section before reporting completion.** Apply the same hook logic as Pre-Execution Checks to the `hooks.after_implement` key of `.specify/extensions.yml`: skip silently when the file, the key, or parseable YAML is absent; honor `enabled`; skip hooks with a non-empty `condition`; dots become hyphens. Mandatory hooks are announced with `EXECUTE_COMMAND: {command}` and then actually invoked to completion; optional hooks are displayed for the user to run.

## Completion Report

Summarize per phase: tasks completed with commit ranges, gate results and who reviewed (codex or fable fallback), checkpoint outcomes, fix loops that ran, and anything escalated or left open (unfixed Minor findings). If the run halted, state plainly what was not completed and why.

## Red Flags

Never:

- implement, review, or fix anything yourself, for any reason, including "it is just one line"
- read a diff, source file, report file, review file, or design document into your own context
- dispatch two subagents at once, or a second while one is running
- skip the task review, or accept a review missing either verdict (spec compliance AND quality)
- flip a checkbox before the task review passes
- re-dispatch a task tasks.md already marks `[X]`
- proceed past a phase gate FAIL or an unexecuted checkpoint
- let an implementer skip TDD on a task briefed as tdd required
- tell a reviewer what not to flag, or pre-rate a finding's severity in a dispatch
- dispatch with an implicit model

## Done When

- [ ] All scheduled tasks marked `[X]` in tasks.md, each only after a clean task review
- [ ] Every phase gate reported PASS and every checkpoint was proven
- [ ] Extension hooks dispatched or skipped according to the rules above
- [ ] Completion reported with per-phase summary, commit ranges, and open items
