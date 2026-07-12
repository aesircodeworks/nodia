export const meta = {
  name: 'implement-stage',
  description: 'Implement one stage of the Nodia API plan with TDD, a codex review loop, and a committed execution journal',
  whenToUse: 'Invoke with args {stage: "1"} (or "2", "5a", "8b", ...) to implement that stage of docs/api-implementation-plan.md end to end',
  phases: [
    { title: 'Prepare', detail: 'read the stage plan, open the journal, mark the stage In progress, derive the remaining task list' },
    { title: 'Implement', detail: 'one agent per task, full TDD loop, commit per green slice', model: 'sonnet' },
    { title: 'Gate', detail: 'full quality gates locally, overlapped with review round 1; after the review loop, one push and CI verification', model: 'sonnet' },
    { title: 'Review', detail: 'codex code review of the local stage diff, up to 3 rounds' },
    { title: 'Fix', detail: 'apply blocking and important review findings locally', model: 'opus' },
    { title: 'Finalize', detail: 'journal close-out and status table update' },
  ],
}

const STAGES = {
  '1': 'stage-01-delivery-kernel.md',
  '2': 'stage-02-tenancy-rls.md',
  '3': 'stage-03-identity.md',
  '4': 'stage-04-outbox.md',
  '5a': 'stage-05a-catalog-core.md',
  '5b': 'stage-05b-seating-templates.md',
  '5c': 'stage-05c-search-media.md',
  '6': 'stage-06-inventory.md',
  '7': 'stage-07-orders.md',
  '8a': 'stage-08a-payments-webhooks.md',
  '8b': 'stage-08b-ledger-refunds.md',
  '8c': 'stage-08c-payouts.md',
  '8d': 'stage-08d-real-gateway.md',
  '9': 'stage-09-checkin.md',
  '10': 'stage-10-on-sales.md',
  '11': 'stage-11-reporting.md',
  '12': 'stage-12-hardening.md',
}

const MAX_REVIEW_ROUNDS = 3

let input = args
if (typeof input === 'string') {
  try {
    input = JSON.parse(input)
  } catch (e) {
    input = { stage: input.trim() }
  }
}
const stageKey = input && input.stage ? String(input.stage) : null
if (!stageKey || !STAGES[stageKey]) {
  throw new Error('Pass args {stage: <key>} where key is one of: ' + Object.keys(STAGES).join(', '))
}
const planFile = STAGES[stageKey]
const planPath = 'docs/plans/' + planFile
const journalPath = 'docs/plans/execution/' + planFile

const PREPARE_SCHEMA = {
  type: 'object',
  required: ['baseCommit', 'branch', 'tasks'],
  properties: {
    baseCommit: { type: 'string', description: 'git rev-parse HEAD captured before any change was made' },
    branch: { type: 'string' },
    alreadyComplete: { type: 'boolean', description: 'true if the whole stage is verifiably already implemented' },
    tasks: {
      type: 'array',
      items: {
        type: 'object',
        required: ['id', 'title', 'detail', 'commitScope'],
        properties: {
          id: { type: 'string' },
          title: { type: 'string' },
          detail: { type: 'string', description: 'plan section references, the tests to write first, acceptance evidence' },
          commitScope: { type: 'string', description: 'owning bounded-context commit scope, e.g. tenancy, support' },
        },
      },
    },
    notes: { type: 'string' },
  },
}

const TASK_RESULT_SCHEMA = {
  type: 'object',
  required: ['status', 'summary'],
  properties: {
    status: { type: 'string', enum: ['done', 'blocked'] },
    summary: { type: 'string' },
    commits: { type: 'array', items: { type: 'string' } },
    followUps: { type: 'array', items: { type: 'string' } },
    declined: { type: 'array', items: { type: 'string' }, description: 'review findings not applied, with reasons' },
  },
}

const GATE_SCHEMA = {
  type: 'object',
  required: ['passing', 'summary'],
  properties: {
    passing: { type: 'boolean' },
    summary: { type: 'string' },
  },
}

const REVIEW_SCHEMA = {
  type: 'object',
  required: ['verdict', 'findings'],
  properties: {
    verdict: { type: 'string', enum: ['approve', 'needs-fixes'] },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        required: ['severity', 'file', 'summary'],
        properties: {
          severity: { type: 'string', enum: ['blocking', 'important', 'minor'] },
          file: { type: 'string' },
          line: { type: 'integer' },
          summary: { type: 'string' },
          detail: { type: 'string' },
        },
      },
    },
  },
}

const FINAL_SCHEMA = {
  type: 'object',
  required: ['stageStatus', 'summary'],
  properties: {
    stageStatus: { type: 'string', enum: ['done', 'in-progress', 'blocked'] },
    summary: { type: 'string' },
  },
}

phase('Prepare')
log('Preparing stage ' + stageKey + ' from ' + planPath)

const prep = await agent(
  'You are preparing a stage run for the Nodia API implementation plan (repo root is the working directory).\n' +
  'Stage ' + stageKey + ', plan file ' + planPath + '.\n' +
  '1. Before changing anything, run `git rev-parse HEAD` and record it as baseCommit, and note the current branch.\n' +
  '2. Read docs/api-implementation-plan.md (the master plan and its Method section), ' + planPath + ', and the convention docs they reference.\n' +
  '3. Determine what is already landed: check the status table, `git log`, the existing code and tests. The stage may be partially done; verify against the codebase, do not trust the docs alone.\n' +
  '4. Create or update the journal at ' + journalPath + '. It is the durable record of this run. Include: a run header with stage, date (use the `date` command), branch, baseCommit; a checklist of the tasks you derive below; empty "Review rounds" and "Decisions and deviations" sections. If the journal already exists from a prior run, append a new run header, never overwrite prior entries.\n' +
  '5. In the status table of docs/api-implementation-plan.md, set this stage to "In progress" if it is not already.\n' +
  '6. Commit the journal and status change as `docs: start stage ' + stageKey + ' execution journal`.\n' +
  'Return baseCommit, branch, and the ordered list of REMAINING tasks only (skip work you verified is already done). Size each task so one focused agent can take it through the full TDD loop in one session. Each task needs: id, short title, a detail paragraph citing the plan sections it covers, the tests that must be written first, and the owning bounded-context commit scope. If the whole stage is verifiably complete, return alreadyComplete true and an empty task list.',
  { label: 'prepare:stage-' + stageKey, phase: 'Prepare', schema: PREPARE_SCHEMA }
)
if (!prep) throw new Error('Prepare agent returned no result; aborting before any implementation work.')

const completed = []
let blockedTask = null

if (prep.alreadyComplete || prep.tasks.length === 0) {
  log('Stage ' + stageKey + ' has no remaining tasks: ' + (prep.notes || 'already complete'))
} else {
  phase('Implement')
  log(prep.tasks.length + ' tasks to implement sequentially')

  for (let i = 0; i < prep.tasks.length; i++) {
    const t = prep.tasks[i]
    log('Task ' + (i + 1) + '/' + prep.tasks.length + ': ' + t.title)
    const result = await agent(
      'You are implementing one task of stage ' + stageKey + ' of the Nodia API implementation plan (repo root is the working directory).\n' +
      'Read first: docs/api-implementation-plan.md (Method section), ' + planPath + ', and the journal at ' + journalPath + ' including entries from earlier tasks in this run.\n\n' +
      'Task ' + t.id + ': ' + t.title + '\n' + t.detail + '\n\n' +
      'Method, non-negotiable:\n' +
      '- TDD double loop per the master plan: write the failing Pest feature test first, then the contract (laravel-data request and response objects plus the OpenAPI path in docs/openapi/openapi.yaml), then failing unit tests for each invariant, then implement to green.\n' +
      '- Every new tenant-scoped table starts with a failing isolation test; every invariant-guarding transition starts with a failing concurrency test; every outbox consumer starts with a failing duplicate-delivery test.\n' +
      '- Follow docs/api-conventions.md, docs/data-conventions.md, docs/event-conventions.md and the repo CLAUDE.md exactly.\n' +
      'When green: run the tests you wrote plus the suites your change touches (`php artisan test --filter=...` or `--testsuite=...` from apps/api), and run `composer -d apps/api run types:generate` if you changed any Data class (commit regenerated output). Do not run the full lint, analyse, or whole test suite per slice; the gate phase after all tasks runs them once for the stage. Commit each green slice with Conventional Commits using scope ' + t.commitScope + '. Never push.\n' +
      'Before finishing, append a journal entry for this task to ' + journalPath + ': timestamp (`date`), what landed, test evidence (which suites ran and their results), commit SHAs, and any deviation from the plan with its reason. Include the journal update in your final commit or a separate docs commit.\n' +
      'If you hit a genuine blocker (a missing design decision, a broken dependency you cannot fix within this task), stop, record it in the journal, commit the journal, and return status blocked with the reason. Do not fake green: report failures as failures.',
      { label: 'task:' + t.id, phase: 'Implement', schema: TASK_RESULT_SCHEMA, model: 'sonnet' }
    )
    if (!result) {
      blockedTask = { task: t, reason: 'agent returned no result' }
      break
    }
    if (result.status === 'blocked') {
      blockedTask = { task: t, reason: result.summary }
      log('Task ' + t.id + ' blocked: ' + result.summary)
      break
    }
    completed.push({ task: t.id, summary: result.summary, commits: result.commits || [] })
  }
}

let gate = null
let ship = null
let reviewRounds = []
let reviewClean = false

const reviewAgent = (round, resolvedSoFar) => agent(
  'Have Codex code-review the Nodia API stage ' + stageKey + ' implementation (repo root is the working directory).\n' +
  'The changes under review: `git log --oneline ' + prep.baseCommit + '..HEAD` and `git diff ' + prep.baseCommit + '..HEAD`.\n' +
  'The stage plan defining what this work must satisfy is ' + planPath + '; the binding conventions are docs/api-conventions.md, docs/data-conventions.md, docs/event-conventions.md and the repo CLAUDE.md.\n' +
  'Review focus: correctness bugs; convention violations; missing tests the plan mandates (isolation coverage for new tables, concurrency coverage for guarded transitions, duplicate-delivery coverage for consumers); RLS gaps; money handling (integer minor units only); state transitions written as read-then-write instead of conditional UPDATEs checked by affected-row count; contract drift between Data classes and the OpenAPI document.\n' +
  'Report only real findings, each with file and line. Severity: blocking (must fix), important (fix before the stage is called done), minor (journal note only). No style nits.\n' +
  'This is review round ' + round + ' of at most ' + MAX_REVIEW_ROUNDS + '.' +
  (resolvedSoFar.length ? ' Findings already addressed in earlier rounds, do not re-report them: ' + JSON.stringify(resolvedSoFar) : ''),
  { label: 'codex-review:round-' + round, phase: 'Review', agentType: 'codex:codex-rescue', schema: REVIEW_SCHEMA }
)

if (completed.length > 0) {
  phase('Gate')
  let firstReview = null
  ;[gate, firstReview] = await parallel([
    () => agent(
      'Run the full quality gates for the Nodia API after stage ' + stageKey + ' implementation work (repo root is the working directory):\n' +
      '`composer -d apps/api run lint`, `composer -d apps/api run analyse`, `php artisan test --parallel` from apps/api (if failures look parallelism-induced, e.g. tests clashing over shared database state, fall back to `composer -d apps/api run test` and note that in the journal), then `composer -d apps/api run types:generate` followed by `git status --short packages/api-client/src/generated` to confirm no contract drift. Run `pnpm typecheck` if any TypeScript changed.\n' +
      'If anything fails, fix it properly (respect the TDD method and repo conventions, never suppress or skip tests), commit fixes with the correct Conventional Commit scope, and re-run until green.\n' +
      'Do NOT push and do not touch CI; the push and CI verification happen once after the review loop.\n' +
      'A code review of the same diff is running concurrently, so keep fixes minimal and scoped to gate failures.\n' +
      'Append a "Gate" entry to ' + journalPath + ' with a timestamp (`date`) and the local results, and commit it as docs.\n' +
      'Return passing false only if you could not reach green locally, with what still fails.',
      { label: 'gate:stage-' + stageKey, phase: 'Gate', schema: GATE_SCHEMA, model: 'sonnet' }
    ),
    () => reviewAgent(1, []),
  ])

  const resolvedSoFar = []
  for (let round = 1; round <= MAX_REVIEW_ROUNDS; round++) {
    const review = round === 1 ? firstReview : await reviewAgent(round, resolvedSoFar)
    if (!review) {
      log('Codex review round ' + round + ' returned no result; stopping the review loop.')
      break
    }
    const actionable = review.findings.filter(f => f.severity === 'blocking' || f.severity === 'important')
    const minors = review.findings.filter(f => f.severity === 'minor')
    reviewRounds.push({ round: round, verdict: review.verdict, actionable: actionable.length, minor: minors.length, findings: review.findings })
    log('Review round ' + round + ': ' + actionable.length + ' actionable, ' + minors.length + ' minor')

    if (actionable.length === 0) {
      reviewClean = true
      if (minors.length > 0) {
        await agent(
          'Append a "Review round ' + round + '" entry to ' + journalPath + ' (timestamp via `date`): Codex approved the stage ' + stageKey + ' diff with only minor notes, listed here verbatim for the record: ' + JSON.stringify(minors) + '. Do not change any code. Commit the journal update as `docs: record stage ' + stageKey + ' review round ' + round + '`.',
          { label: 'journal:round-' + round, phase: 'Fix', schema: TASK_RESULT_SCHEMA, model: 'sonnet', effort: 'low' }
        )
      }
      break
    }

    const fix = await agent(
      'Apply the Codex code review findings for stage ' + stageKey + ', round ' + round + ' (repo root is the working directory). Findings:\n' + JSON.stringify(review.findings, null, 2) + '\n\n' +
      'For each blocking or important finding: fix it properly, writing or adjusting tests first when the fix is behavioral. Never suppress, silence, or work around a finding. If you conclude a finding is factually wrong, leave the code alone and record your reasoning instead.\n' +
      'Minor findings need no code change unless trivial; record them in the journal.\n' +
      'When done, run the tests covering what you changed (`php artisan test --filter=...` or the touched suites from apps/api) and `composer -d apps/api run lint`; commit fixes with the correct Conventional Commit scope. Do NOT push and do not touch CI; a full gate plus one push and CI verification happen once after the review loop.\n' +
      'Append a "Review round ' + round + '" entry to ' + journalPath + ' (timestamp via `date`): every finding, what was done or why it was declined. Commit the journal.\n' +
      'Return status done with commits, and list any declined findings with reasons.',
      { label: 'fix:round-' + round, phase: 'Fix', schema: TASK_RESULT_SCHEMA, model: 'opus' }
    )
    if (!fix) {
      log('Fix agent for round ' + round + ' returned no result; stopping the review loop.')
      break
    }
    for (const f of actionable) {
      resolvedSoFar.push(f.file + ': ' + f.summary)
    }
  }

  if (gate && gate.passing) {
    ship = await agent(
      'Ship the Nodia API stage ' + stageKey + ' branch (repo root is the working directory). Local gates already passed once; review fixes may have landed since, verified only with scoped test runs.\n' +
      'Do NOT re-run the full local test suite. If any commits landed after the gate entry in ' + journalPath + ', run only `composer -d apps/api run lint`, the scoped tests covering those commits (`php artisan test --filter=...` or the touched suites from apps/api), and, if any Data class changed, `composer -d apps/api run types:generate` followed by `git status --short packages/api-client/src/generated` to confirm no contract drift. CI is the full verification. Fix any failure properly (never suppress or skip tests) and commit with the correct Conventional Commit scope.\n' +
      'Then push the branch to origin (`git push origin HEAD`) and verify CI: list the triggered runs with `gh run list --branch <branch>` and watch them with `gh run watch <id> --exit-status`. If any workflow fails, read the failed job logs (`gh run view <id> --log-failed`), fix the root cause properly (never weaken a gate to pass it), commit, push again, and re-watch until every workflow is green. CI can fail for reasons local runs cannot catch (missing service containers, lint rules on regenerated output), so treat a red run as a real stage defect, not noise.\n' +
      'Append a "CI" entry to ' + journalPath + ' with a timestamp (`date`), the run IDs and conclusions, and commit it as docs (that commit can ride the final push).\n' +
      'Return passing false only if you could not reach green locally and on CI, with what still fails.',
      { label: 'ship:stage-' + stageKey, phase: 'Gate', schema: GATE_SCHEMA, model: 'opus' }
    )
  }
}

phase('Finalize')
const outcome = {
  stage: stageKey,
  tasksPlanned: prep.tasks.length,
  tasksCompleted: completed.length,
  blocked: blockedTask,
  gate: gate,
  ship: ship,
  reviewRounds: reviewRounds.map(r => ({ round: r.round, verdict: r.verdict, actionable: r.actionable, minor: r.minor })),
  reviewClean: reviewClean,
  alreadyComplete: prep.alreadyComplete || false,
}

const final = await agent(
  'Close out the stage ' + stageKey + ' run of the Nodia API implementation plan (repo root is the working directory). Journal: ' + journalPath + '. Machine summary of the run: ' + JSON.stringify(outcome) + '\n' +
  '1. Append a final summary to the journal (timestamp via `date`): tasks completed, the local gate result, the ship result including CI run conclusions, review rounds and their outcome, unresolved or declined findings, blockers if any. Then walk the exit criteria in ' + planPath + ' one by one and record for each whether it is met, with concrete evidence (test names, endpoints, commits, CI run links).\n' +
  '2. Update the status table in docs/api-implementation-plan.md: mark the stage "Done" only if every exit criterion is verifiably met, the gates are green locally and on CI for the current HEAD, and the review ended with no unaddressed blocking or important findings. Otherwise set an honest partial status (for example "In progress (blocked on X)") and say why in the journal.\n' +
  '3. Commit as `docs: close stage ' + stageKey + ' execution journal` and push (`git push origin HEAD`). If any code commits landed after the last green CI run, confirm the newly triggered runs are green before returning; a docs-only push needs no wait.\n' +
  'Return the final stage status and a one-paragraph summary. Be strictly truthful: report the run as it actually went.',
  { label: 'finalize:stage-' + stageKey, phase: 'Finalize', schema: FINAL_SCHEMA, model: 'sonnet' }
)

return {
  stage: stageKey,
  status: final ? final.stageStatus : 'unknown',
  summary: final ? final.summary : 'finalize agent returned no result',
  tasksCompleted: completed,
  blocked: blockedTask,
  gatePassing: gate ? gate.passing : null,
  ciPassing: ship ? ship.passing : null,
  reviewRounds: outcome.reviewRounds,
  reviewClean: reviewClean,
  journal: journalPath,
}
