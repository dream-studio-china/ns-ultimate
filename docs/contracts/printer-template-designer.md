# Printer template designer

## Ownership and storage

`business/admin/config/components/PrinterTemplateDesigner.vue` is the custom
`PrinterTemplate.definition` control in the normal admin create/edit form. It
uses the existing CRUD save lifecycle and preview renderer; it does not change
routes, authorization, provider protocols or the persisted template language.
No core edit or database migration is required.

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

## Preview and side effects

Automatic previews debounce the current usable definition and in-memory sample
through the existing non-persistent preview API, and use `PrinterPaper` to show
the server-expanded pages. Stale responses are ignored after any draft/sample
change, newer request or unmount. Invalid sample JSON clears preview and does
not replace the last usable canvas sample. The sample is never included in the
saved template or used as live order data.

The designer never creates print jobs, contacts providers directly, registers
devices or queries device status. Hardware tests remain in the existing
explicitly confirmed print action after saving. Browser preview, especially
fonts, barcode placeholders, wrapping and QR geometry, is approximate and cannot
prove physical output. See the owning [Printer module](../../business/backend/Printer/README.md#receipt-and-label-layout)
for the actual template language and renderer constraints.

The assistant host binds `PrinterTemplate.definition` to this exact component as
JSON so replacing the UI does not remove existing configured template CRUD
capabilities. It adds no print permission or side effect.
