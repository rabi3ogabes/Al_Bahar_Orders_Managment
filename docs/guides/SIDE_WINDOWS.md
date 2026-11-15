# Side windows — how secondary flows open (dashboard, store, apps)

**Rule:** quick views, details, carts, filters and edit forms open in a window beside the page, so the person keeps
their place and one Back closes the window. A separate page is only for big or shareable content (checkout, a full
product editor, a printable document).

## Which overlay (lightest one that does the job)

| Need | Overlay | Dashboard | Store (React) | Apps (Flutter) |
|---|---|---|---|---|
| Hint on an icon | Tooltip | `title` / `aria-label` | same | `Tooltip` + visible text |
| 2–7 actions for one row | Popover (desktop) / bottom sheet (phone) | row buttons / menu | action menu | `showModalBottomSheet` |
| Quick view, details, edit form, cart, filters | **Side window** (end side: right in English, **left in Arabic**) | `<x-admin.dialog wire:model="…">` | `SideDrawer` | `showSidePanel()` |
| A decision ("Delete this?") | Centred modal, never closed by the scrim | `<x-admin.dialog variant="modal" …>` | `ConfirmDialog` | `confirmAction()` |
| Checkout, onboarding | Full page | page | page | route |

Phones (< 768 px): a side window becomes a tall bottom sheet (max 92 % of the screen) with a drag handle.

## Behaviour every window must have

1. Opens on tap; the page behind stays where it was (scroll position, filters, search).
2. Closes by ✕, Esc, scrim click and the system/browser **Back** button — Back closes only the top window.
3. Focus moves in, is trapped, and returns to the button that opened it. Body scroll is locked behind it.
4. The primary action is pinned at the bottom of the window and says what it does ("Save changes", "Place order").
5. Record views that deserve a link (an order, a customer) are reachable by URL (`?view=…` or `?order=…`), so reload,
   share and notification deep links still work; the full page route keeps working too, and the window offers
   "Open full page".
6. Right-to-left: use logical properties only (`start`/`end`, `ms-`/`me-`, `rtl:`), the window slides from the left in Arabic.
7. 44 px touch targets (48 dp in the apps), visible focus, dark mode, reduced motion.
8. Toggle = instant save + "Saved" toast, never next to a Save button. Checkbox group = waits for Save, disabled until dirty.
9. The same permissions and branch scoping as the page the window replaces — a window must never show more than the page would.
10. Dirty form + close → ask "Discard changes?" (modal), do not just close.

## Dashboard (Blade + Livewire + Alpine)

`resources/views/components/admin/dialog.blade.php` is the one component. Default variant is the end-side drawer; width follows
`max-width="max-w-md|lg|xl|2xl|3xl|4xl|5xl"`. Forms inside close it with `@click="open = false"`. Their last row of buttons
(a direct child `div` of the `form` holding `.btn-primary`) is pinned by `.drawer-body` in `resources/css/app.css`.
An optional `<x-slot:footer>` is pinned too. Nested decisions use `variant="modal"`; Esc and Back close only the top one.

## Edit in a side window, or open the full page (Employees, Roles, Branches)

The three Organization lists whose records have a big editor (Employees, Roles, Branches) offer both ways to edit, from the same row:

- **A click on the row (or its arrow) opens the editor in the side window** beside the list: the search, filters and scroll position behind it stay.
  The window has the record's name and status on top, an **Open full page** link, and the editor itself. On phones it is a bottom sheet.
- **Ctrl/⌘-click, "open in new tab" and a visit without JavaScript** go to the full page (`/admin/employees/{id}`, `/admin/roles/{id}`, `/admin/branches/{id}`), which keeps working for links, e-mails and notifications.
- **The "New …" button** opens an empty editor in the window, with the same "Open full page" link to the page that creates it.
- `?edit=<id>` and `?new=1` keep an open window in the URL: reload, share and Back work (Back closes only the window).
- Closing a window with unsaved input asks "Discard your changes?"; saving shows the usual toast and the row behind refreshes at once.
- After a new employee/role/branch is created the window switches to that record (an employee lands on *Branches & roles*, their first job).

How it is built (so the next list can do the same):

| Piece | What it does |
|---|---|
| `App\Livewire\Admin\Concerns\WithRecordEditor` | on the list: `?edit=` / `?new=1`, `openEditor()`, `openCreate()`, closes, the `record-created` / `record-changed` / `record-deleted` events. The list supplies `editable($id)` and `creatable()`: the **same access rule as the full page** (a branch manager still only opens their own branch; a bad, foreign or tampered id never opens). |
| `<x-admin.record-editor>` | the two windows (edit, new) with the top bar and "Open full page" link; renders the page's own Livewire editor with `embedded = true`. |
| `<x-admin.open-editor>` | the row link: a real `href` to the page, a plain click opens the window. |
| The editors (`EmployeeEditor`, `RoleEditor`, `BranchEditor`) | one component for page and window. `embedded` (locked, the browser cannot change it) only switches the layout to one column, drops the page header and keeps every action inside the window (no redirects): a created record, a change and a delete are announced to the list with events. |

Rules stay in the editor, so the window can never allow more than the page: tests in `tests/Feature/Admin/RecordEditorWindowsTest.php`.
Zones already edit in a window (they are a small form with no page of their own); Settings, App updates and Push notifications are single pages.

## Apps (Flutter)

`lib/core/widgets/side_panel.dart` — `showSidePanel<T>(context:, title:, builder:, footer:, …)` is a bottom sheet on phones and an
end-side panel from 720 px wide (tablets, web, landscape), RTL-aware. Both are routes, so Back works. Do not build new ad-hoc sheets.

Extras in `showSidePanel`: `isForm: true` switches off swipe/scrim dismissal and asks "Discard changes?" before closing (use it for any window
with input); `SidePanelLayout` and `PanelStep` give a window several steps (a password change, a cancel/return) with Back stepping inside it first;
the keyboard lifts the pinned footer; `showInfoSnack` / `showErrorSnack` called inside a window show a toast inside it (a snackbar would hide
behind it); a page change underneath (a footer tab) closes open windows.

**Add to cart** (customer app: `addToCartAndShow` in `lib/features/cart/presentation/cart_panel.dart`; store: the same behaviour in the cart drawer):
tapping "Add to cart" never navigates. It opens a cart window that stays open until the person closes it (no timer) with "Added to your cart",
the item's photo, name, price and a −/+ quantity, the subtotal (the server's figure), and exactly two buttons: **Checkout** and **Keep shopping**
(closes, same screen, same scroll position). Scrim tap, swipe down, Esc and Back close it. The cart badge changes before the server answers
and rolls back on failure. Adding the same variant again raises the existing line (the server adds to the row's quantity), never a second line.

