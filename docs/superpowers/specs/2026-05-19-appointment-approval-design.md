# Tap-to-Schedule & Appointment Approval — Design

**Date:** 2026-05-19
**Status:** Approved
**Revises:** `2026-05-19-calendar-appointments-design.md`

## Goal

Revise the Calendar View feature so that:

1. Clients select an appointment date by **tapping a day tile** on the calendar
   (the date `<input>` becomes a read-only display).
2. Every booking starts as **pending** and requires the owner's
   **approve / disapprove** decision, made from links in a notification email.
3. The calendar reflects appointment **status** with three visual states.

## Background

The existing feature (already implemented) has: a `#appointments` section with
a monthly calendar and a booking form (Project Name, Type of Project, Facebook
Name, Deadline via `<input type="date">`); `appointments.php` with `GET`/`POST`;
and `appointments.json` storage. This revision changes the date-selection
mechanism, adds an approval workflow, and adds a `status` field.

## Requirements

### Tap-to-schedule

- Clicking a day tile that is **in the future and not booked** selects it as the
  appointment date. The selected tile shows a distinct "selected" ring.
- Clicking a **past** date or an **already-booked** date does not select it.
- Clicking a **booked** date still opens that appointment's detail panel
  (unchanged behavior).
- A tile can be both selectable and, once selected, show the selected ring.

### Form date field — view only

- The `<input type="date" id="appointmentDeadline">` is replaced by a read-only
  display element (`#appointmentDateDisplay`). It shows
  "Tap a date on the calendar" when nothing is selected, and the chosen date
  (e.g. `2026-06-15`) once a tile is tapped.
- A hidden input (`#appointmentDeadline`, `type="hidden"`) carries the selected
  date value for submission.
- Submit is blocked with a clear message if no date is selected.

### Appointment status

Each appointment has a `status`: one of `pending`, `approved`, `disapproved`,
and a unique `id` (used in approval links).

- **Booking** → saved as `pending`. The date is immediately blocked against
  further bookings.
- **Owner approves** → `status` becomes `approved`.
- **Owner disapproves** → `status` becomes `disapproved`. That date is no longer
  considered booked — it becomes selectable/bookable again.

### Date-blocking rule

A date counts as **booked** (unselectable, rejected by the server) when an
appointment exists for it with status `pending` OR `approved`. A `disapproved`
appointment does **not** block its date.

### Calendar visual states

- **Pending** tile — orange background/border.
- **Approved** tile — cyan/green background/border (the current "booked" look).
- **Disapproved** — not rendered as special; the date appears free.
- **Today** marker and **selected** ring are independent of status.

### Approval emails

- On booking, the owner receives one email containing two buttons:
  **Approve** and **Disapprove**, each a link to `appointment_approval.php`
  with the appointment `id`, an `action`, and a signed token.
- No emails are sent to clients (the form collects no client email).

## Architecture

### `appointments.php` (modify)

- `POST` now:
  - Generates a unique `id`: `APT-<time>-<8 hex>`.
  - Saves the appointment with `status: "pending"` and the `id`.
  - The "already booked" check ignores `disapproved` appointments — a date is
    taken only if a `pending`/`approved` appointment exists for it.
  - Sends the owner an **approval email** with Approve/Disapprove links built
    from `getBaseUrl()`, the `id`, the action, and an HMAC token.
- `GET` returns all appointments including `id` and `status` (so the calendar
  can color them). `disapproved` appointments are still returned; the client JS
  decides not to highlight them.
- New helpers mirror `gcash_approval.php`: `generateAppointmentToken(id)` using
  `APPROVAL_SECRET`, and `getBaseUrl()`.

### `appointment_approval.php` (new)

Modeled on `gcash_approval.php`. Serves an HTML page.

- Reads `?action=` (`approve` or `disapprove`), `?id=`, `?token=`.
- Validates the action is one of the two allowed values and the token matches
  `generateAppointmentToken(id)` via `hash_equals`.
- Opens `appointments.json` under `LOCK_EX`, finds the appointment by `id`:
  - Not found → error page.
  - Found and already decided (`approved`/`disapproved`) → info page stating the
    current status (idempotent; a re-clicked link does not flip it).
  - Found and `pending` → set `status` to `approved` or `disapproved`, record
    `decidedAt`, write the file back.
- Shows a success page ("Appointment Approved" / "Appointment Disapproved")
  with the appointment details and a "Back to Portfolio" link, and an error
  page on failure — same visual style as `gcash_approval.php`'s pages.

### `appointments.json` (data shape change)

Each record gains `id` and `status` (and `decidedAt` once decided):

```json
{
  "id": "APT-1747000000-A1B2C3D4",
  "projectName": "Library System",
  "projectType": "Capstone Project",
  "facebookName": "Juan Dela Cruz",
  "deadline": "2026-06-15",
  "status": "pending",
  "bookedAt": "2026-05-19 14:30:00",
  "decidedAt": null
}
```

### `index.html` (modify)

- **Form:** replace the date `<input>` with a read-only `#appointmentDateDisplay`
  element plus a hidden `#appointmentDeadline` input.
- **CSS:** add `.calendar-day.selectable` (hover affordance),
  `.calendar-day.selected` (selection ring), `.calendar-day.pending` (orange),
  `.calendar-day.approved` (cyan/green). Keep light-mode variants.
- **JS:**
  - Build a `status`-aware map: a date is "blocked" if a `pending`/`approved`
    appointment exists for it.
  - Render tiles: `pending` → orange, `approved` → cyan/green, future-unblocked
    → `selectable`.
  - Clicking a `selectable` tile sets the selected date, updates
    `#appointmentDateDisplay` and the hidden input, and moves the `selected`
    ring. Clicking a blocked tile shows its appointment detail.
  - On submit, block if no date selected. After a successful `POST`, reload and
    re-render (the new pending appointment shows orange).

## Data Flow

1. Page load → `GET appointments.php` → render tiles by status.
2. Client taps a free future tile → date stored, shown in the form.
3. Client fills the three text fields → submit → `POST` → saved `pending`,
   date blocks → owner gets the approval email → calendar re-renders, tile
   orange.
4. Owner clicks **Approve** in the email → `appointment_approval.php` sets
   `approved` → next calendar load shows the tile cyan/green.
5. Owner clicks **Disapprove** → status `disapproved` → next calendar load shows
   the date free again, bookable.

## Error Handling

| Case | Behavior |
|------|----------|
| Submit with no date tapped | Status: "Please tap a date on the calendar first." No request. |
| Tap a past or booked tile | No selection; booked tile opens its detail panel. |
| Date already booked at `POST` (race) | Server rejects 409, message to pick another date. |
| Approval link bad/missing params | `appointment_approval.php` shows an error page. |
| Approval token invalid | Error page: invalid/expired link. |
| Appointment id not found | Error page: appointment not found. |
| Link clicked twice / already decided | Info page stating the current status; no change. |
| Owner email fails on booking | Booking still saved as pending; response carries `emailWarning`; UI shows info-style status. |

## Testing

Manual verification under XAMPP:

1. `php -l` on `appointments.php` and `appointment_approval.php`.
2. `GET appointments.php` → returns array (records include `id`, `status`).
3. `POST` a booking → 200, record saved with `status:"pending"` and an `id`;
   owner receives the Approve/Disapprove email.
4. `POST` same date again → 409 (pending blocks).
5. Open the **Approve** link → success page; `appointments.json` shows
   `status:"approved"`.
6. Re-open the same Approve link → info page, status stays `approved`.
7. `POST` a second booking on a new date → pending; open its **Disapprove**
   link → success page; `status:"disapproved"`; `POST` that same date again →
   now succeeds (disapproved frees the date).
8. Approval link with a wrong token → error page.
9. Browser: tapping a free future tile fills the read-only date display and
   rings the tile; tapping a past/booked tile does not select; submit with no
   date selected is blocked.
10. Browser: pending tiles render orange, approved tiles cyan/green, disapproved
    dates render free; verified in light and dark mode.

## Out of Scope (YAGNI)

- Client-facing emails of any kind.
- Editing appointment fields after booking.
- An owner dashboard — approval happens entirely via email links.
- Re-notifying when a disapproved date is later re-booked (a new booking simply
  starts a fresh pending cycle).
- Authentication beyond the HMAC token on approval links.
