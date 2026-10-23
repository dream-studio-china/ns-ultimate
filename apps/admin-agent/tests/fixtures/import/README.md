# Import parser compatibility fixtures

These are small, synthetic, non-confidential samples used only to prove that the
local import parser accepts ordinary files produced by established Office tools.
They contain no macros, embedded objects, external links, personal data or
organization-specific values. The parser never executes Office documents, and
these fixtures were not opened in Microsoft Office during generation.

## Source data

The fixtures share the same names, categories, codes, quantities and active
flags. Their note cells intentionally differ so each sample also exercises
ordinary punctuation and spacing from that producer.

| Name | Category | Code | Quantity | Active | XLSX note | DOCX note |
| --- | --- | --- | --- | --- | --- | --- |
| North | Tea | TEA-001 | 2 | TRUE | Line one / Line two (newline in the cell) | O'Brien, Ltd |
| South | Coffee | COF-002 | 3 | FALSE | O'Brien, Ltd | He said "hello" |
| East | Green tea | GRN-003 | 4 | TRUE | He said "hello" | A-B |
| West | Herbal | HRB-005 | 5 | FALSE | (empty) | Plain note |

## `sheetjs-products.xlsx`

- Generated with SheetJS `xlsx` 0.16.9, installed by the repository's admin
  frontend dependency tree, from an array-of-arrays workbook with fixed
  `CreatedDate`/`ModifiedDate` values of `2026-01-01T00:00:00Z`.
- The workbook metadata is fixed to `ns-ultimate local test` and the title
  "Synthetic import interoperability sample".
- ZIP entry timestamps were normalized to `2026-01-01T00:00:00` with Python's
  standard `zipfile` module; entry contents and order are unchanged.
- SHA-256: `40ca96e55d2e643712c612dbc90f6f52a042bb8e90c8a8336ac410ec59938bba`

Generation shape (run against the installed SheetJS package):

```js
const workbook = XLSX.utils.book_new()
workbook.Props = {
  Title: 'Synthetic import interoperability sample',
  Subject: 'Not confidential; generated for parser acceptance only',
  Author: 'ns-ultimate local test',
  CreatedDate: new Date('2026-01-01T00:00:00Z'),
  ModifiedDate: new Date('2026-01-01T00:00:00Z')
}
XLSX.utils.book_append_sheet(workbook, XLSX.utils.aoa_to_sheet(data), 'Products')
XLSX.writeFile(workbook, outputPath, { bookType: 'xlsx', compression: true })
```

## `pandoc-products.docx`

- Generated with Pandoc 2.12 from the Markdown table above using
  `-f markdown-smart -t docx`.
- `SOURCE_DATE_EPOCH=1767225600` (`2026-01-01T00:00:00Z`) makes the output
  deterministic, including document properties and ZIP timestamps.
- The source had no author, title or comments; Pandoc emits its normal empty
  footnotes/endnotes/comments parts, which the parser reports as an ignored
  ancillary-text warning.
- SHA-256: `2abbb65b3f5787c3139e3429e1b1d978151040012696cbdab086a143b4e15164`

Command shape:

```sh
SOURCE_DATE_EPOCH=1767225600 pandoc -f markdown-smart -t docx products.md -o pandoc-products.docx
```

The parser test in `tests/import-files.test.mjs` reads both fixtures and
asserts their parsed columns, rows and warnings.
