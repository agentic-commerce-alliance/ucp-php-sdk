# Complexity QA

This check finds the code that is hardest to read and change, and says what to do about it.
It measures four things:

1. **Cognitive complexity** per function, as SonarSource defines it. Nesting costs more than
   sequence, so a flat list of guard clauses scores lower than the same logic nested.
2. **Parameter count** per function. Data constructors in `Model`, `Event` and `Exception`
   namespaces are left out: a constructor whose promoted properties are the wire shape is
   not a smell.
3. **Body length** in lines.
4. **Duplicated code** across the analysed tree, ignoring whitespace and comments.

It analyses `packages/core/src`, `packages/symfony-bundle/src` and both example apps.

## Commands

```bash
docker compose run --rm php composer complexity            # report only
docker compose run --rm php composer complexity:gate       # fail on regressions, part of `qa`
docker compose run --rm php composer complexity:baseline   # accept the current state
```

The report goes to standard output and to `var/reports/complexity/report.md` and `report.json`.
CI appends the Markdown to the job summary of the PHP 8.2 lane and uploads both files as the
`complexity-report` artifact.

The limits default to 15 for cognitive complexity, 5 parameters and 80 body lines. Override them
per run with `--max-cognitive`, `--max-parameters` and `--max-lines`, and the table size with `--top`.

## The Gate Is A Ratchet

`tools/complexity-baseline.json` records every function that is over a limit today, with its
value, plus the total of duplicated lines. The gate fails when:

- a function crosses a limit and is not in the baseline,
- a function in the baseline gets worse,
- the duplicated line total rises.

It does not fail on the hotspots that already exist. After a refactoring lowers a value, run
`composer complexity:baseline` in the same pull request so the improvement cannot quietly be
undone. If an increase is deliberate, regenerate the baseline and say why in the pull request.

## Which Fixes Are Free

Every row carries a surface, and the surface decides whether a fix can break consumers:

| Surface | Meaning | Changing the signature |
|---|---|---|
| `private` | private, or protected in a final class | free |
| `internal` | `Ucp\Sdk\Internal`, bundle bridge, event listener, internal or `@internal` classes, example apps | free, outside the stable surface in [AGENTS.md](../AGENTS.md) |
| `public-api` | everything else | a BC break, caught by `composer bc-check` |

Simplifying a function body never breaks backward compatibility, whatever its surface. That is
why only the parameter table shows the surface. For a `public-api` signature, add the simpler
form next to the old one and deprecate the old one instead of changing it.

## How It Is Built

- Cognitive complexity comes from `tomasvotruba/cognitive-complexity`, used as a library rather
  than as a PHPStan rule, so it can report and rank instead of only fail.
- Clone detection is in `tools/ComplexityReport.php`. It hashes windows of 70 PHP tokens and
  extends a match while consecutive windows keep matching, the method phpcpd used. Matches
  shorter than 5 lines and overlaps within one file are ignored. The maintained phpcpd fork
  needs PHP 8.4, and this repository's toolchain resolves against 8.2.
- `ComplexityReportToolTest` checks the metric against the worked examples in SonarSource's
  white paper, not against values read back from the implementation.
