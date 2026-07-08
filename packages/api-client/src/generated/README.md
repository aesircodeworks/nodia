# Generated

Everything in this directory is generated output, never hand-written. TypeScript
types are emitted here from the API's laravel-data objects via
spatie/typescript-transformer, run through `composer types:generate` in
`apps/api`. Do not edit files in this directory directly; regenerate them
instead. CI fails if regeneration produces a diff against what is committed
here (see docs/decisions/013-laravel-data-for-dtos.md and
specs/001-project-foundation/research.md R8).
