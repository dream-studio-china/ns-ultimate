# Printer template designer

## Ownership and storage

`business/admin/config/components/PrinterTemplateDesigner.vue` is the custom
`PrinterTemplate.definition` control in the normal admin create/edit form. It
uses the existing CRUD save lifecycle and preview renderer; it does not change
routes, authorization, provider protocols or the persisted template language.
No core edit or database migration is required.

Editing a template must not invalidate its existing item filter. The deprecated
`all` and `kitchen` tokens remain accepted and persisted unchanged alongside new
expressions. `all` retains unfiltered behavior; `kitchen` retains strict boolean
`kitchenPrinterEnable` opt-in, including suppressing output when no items match.
Neither opening the designer nor saving unrelated content converts these tokens
to empty filters. See the [Printer module guide](../../business/backend/Printer/README.md)
for upgrade caveats when the historical clearing migration was already applied.

Visual, JSON and split views share the same definition. Valid JSON changes update
the layout and the parent form; visual changes update the JSON. Unknown node
types and properties, conditional/loop subtrees, legacy receipt commands and
`labelEnvelope`/`widthMode` survive unrelated edits. Nodes are not automatically
converted to newer equivalents. Explicitly changing a content source replaces
only its competing `value`/`path`/`template` keys and, for receipt leaf text,
the corresponding `text`/`field`/`string` type.

Raw JSON formatting is retained while typing. Visual edits and the format action
pretty-print the definition; saving persists structured JSON, not the original
whitespace. Designer selection, zoom, grid and sample data are not stored in the
definition. Opening a template does not rewrite it.

### Null-safe form transport

The upstream form-save cleaner removes null object properties recursively. To
avoid changing `equals: null` or unknown nullable metadata, the designer supplies
JSON text to the form when a definition contains null. This applies both to
loaded definitions and subsequent edits. Definitions without null continue to
use object values. The canvas and JSON control always operate on the decoded
definition, not a second independent draft.

As an additive management API input, template create/update accept `definition`
as either the original object or bounded JSON object text. The business-owned
`TemplateDefinitionInput` normalizes text before kind checks, optional rendering
and entity assignment. Invalid, oversized and non-object text fails closed.
Storage and responses remain structured JSON; existing object-based clients,
authorization and routes are unchanged. Deploy the backend change with this
frontend: an older backend will reject text input rather than preserve its nulls.

The JSON pane reuses the original admin JSON field through a business-owned raw
text adapter, retaining JSONEditor's code, tree, form, text and view modes.
Programmatic visual updates preserve tree state through `updateText`; restoring
an invalid draft from history switches tree/form back to code rather than losing
that input. The designer and JSON syntax palette follow the host's light/mist/dark
theme without recoloring other pages. Printed paper remains white with black ink.

## Drafts and history

Invalid syntax or an invalid top-level/node structure leaves the last usable
layout visible and the raw draft editable. Visual writes are disabled, automatic
preview is cleared, and an instance-bound validator blocks the parent save. A
mismatched form printer type and JSON `kind` also blocks saving; switching printer
type does not silently convert existing nodes. Unsupported nodes remain visible
in the layout tree and editable through JSON, while the renderer determines
whether it can print them.

The editor bounds input size and traversal but does not replace backend rendering
validation. For example, an invalid printer multiplier or unavailable data field
can still cause a preview/dispatch error. The backend remains authoritative;
save-time rendering semantics are unchanged.

One undo/redo history includes raw drafts (including invalid input), formatting,
properties, additions, duplication, removal and reordering. A pointer gesture
commits one step; cancelling it restores the previous layout. Clicking without
dragging does not change coordinates or automatic alignment. An external form
definition replacement resets local history instead of merging unrelated edits.
The existing save button is the only persistence action in this control.

Creating a template offers a populated receipt/label starter as well as an empty
definition. Starters use the real `order.items` sample path and supported printer
commands, and are tested through the actual form cleaner and PHP renderer.
Existing templates are never replaced by choosing a printer type; that identity
selector is hidden when editing an existing record.

## Label controls

The layout canvas supports pointer dragging, keyboard nudging, optional 1 mm
grid snapping, zoom and native-step resize handles. Positions are shown in mm
and stored as integer printer dots (8 dots/mm). Text uses native fonts and
width/height multipliers; QR resize changes its size parameter, and barcode
resize changes its height because the existing protocol exposes no barcode
width parameter. Rotated text uses numeric size controls instead of a resize
handle. Estimated out-of-paper bounds are highlighted, not silently clamped.

Horizontal text alignment uses the existing automatic `align` behavior for
unrotated font 9; other fonts, rotated elements and codes use an estimated
one-time coordinate adjustment. Vertical centering is also a one-time estimate
using the current sample. Dragging or keyboard nudging explicitly replaces
automatic horizontal alignment with coordinates. Resizing text with automatic
alignment retains it. The canvas is schematic: codes are placeholders, text
metrics are approximate, and loops show one representative iteration, including
conditional children for editing. It is not a page-expansion simulator.

## Receipt controls

Receipts remain line-based rather than freely positioned. The nested layout tree
supports drag-before reordering and keyboard-accessible move buttons, with
descendant-cycle protection. The component library can append a root node or a
child of the selected container. Properties expose text/data/interpolation,
style alignment, emphasis and native width/height scaling, QR options, and
column content, alignment and display-cell width. Advanced conditions and
stateful legacy commands remain editable in JSON. They are not wrapped or
normalized merely by opening, moving or editing another block.

Selected nodes can move into an existing or empty container, or back to the root;
the root drop zone appends a dragged node. Drag payloads are scoped to one editor
instance and moves into descendants are rejected. The same history covers these
operations. Explicit condition controls support truthiness and strict string,
number, boolean and null comparisons without requiring hand-written JSON.

The searchable field picker derives paths from the current sample and selected
loop scope (including representative `item`/`index`/`repeatIndex` values). It
replaces a path or appends a safely escaped interpolation placeholder, optionally
formatted as money/uppercase/lowercase. Column pickers replace their content
source without changing width/alignment. Loop array choices come from the same
sample. Sample-derived paths are suggestions, not a guarantee of availability in
real order snapshots. Custom fields remain editable in JSON or path inputs.

## Preview and side effects

Automatic previews debounce the current usable definition and in-memory sample
through the existing non-persistent preview API, and use `PrinterPaper` to show
the server-expanded pages. Stale responses are ignored after any draft/sample
change, newer request or unmount. Invalid sample JSON clears preview and does
not replace the last usable canvas sample. The sample is never included in the
saved template or used as live order data.

For per-unit ticket previews, an explicit sample `item` is retained; otherwise
the first `order.items` entry supplies a representative item. The preview narrows
`order.items` to that item and supplies its unit index/total, matching the adapter's
single-ticket shape. This default is
not added to the definition or persisted. Changing ticket mode invalidates stale
previews. JSONEditor's own text/tree undo is not intercepted by the designer's
keyboard shortcuts; the toolbar still exposes the combined draft history.

The designer never creates print jobs, contacts providers directly, registers
devices or queries device status. Hardware tests remain in the existing
explicitly confirmed print action after saving. Browser preview, especially
fonts, barcode placeholders, wrapping and QR geometry, is approximate and cannot
prove physical output. See the owning [Printer module](../../business/backend/Printer/README.md#receipt-and-label-layout)
for the actual template language and renderer constraints.

The assistant host binds `PrinterTemplate.definition` to this exact component as
JSON so replacing the UI does not remove existing configured template CRUD
capabilities. It adds no print permission or side effect.

## Offline acceptance checks

Run the admin composition tests, the targeted Printer PHP tests and the explicit
cross-language check from the repository root:

```sh
node --test integration/admin/*.test.mjs
php core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml --filter 'PrintDocumentRendererTest|TemplateDefinitionInputTest'
node --test integration/backend/printer-designer-contract.test.mjs
```

The cross-language check requires Node dependencies from the core admin package
and local PHP with the renderer's multibyte extensions. It executes the actual
form-save cleaner, definition decoder and renderer without a database or printer.
Controller unit tests cover both save hooks and entity assignment. These checks
do not establish live-database persistence, deployment authorization or hardware
fidelity; those remain environment-specific acceptance checks.
