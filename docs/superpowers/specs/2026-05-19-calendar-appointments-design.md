# Calendar View & Appointment Booking — Design

**Date:** 2026-05-19
**Status:** Approved

## Goal

Add a "Calendar View" section to `index.html` where the site owner can see all
client project appointments on a monthly calendar, and where clients can book a
new appointment by submitting four fields: Project Name, Type of Project,
Facebook Name, and Deadline.

## Requirements

A booked appointment captures:

- **Project Name** — free text.
- **Type of Project** — one of: Simple Project, Documentation Project,
  Capstone Project, Business Project.
- **Facebook Name** — free text (the client's Facebook display name).
- **Deadline** — a calendar date.

Behavioral rules:

- **One appointment per date.** A deadline date that already has an appointment
  cannot be booked again. Enforced server-side (not bypassable) and reflected in
  the UI (booked/past dates disabled in the date picker).
- Appointments persist on the server so the owner sees every client booking in
  one place.
- Each new booking emails a notification to the owner.

## Architecture

Three pieces, all following patterns already in this repo:

### 1. New section in `index.html` — `<section id="appointments">`

Placed immediately after the Payment section (`#payment`), before the Gallery
section. Reuses the existing `client-payment-card` / `client-payment-grid`
two-column layout (collapses to one column under 991px). Two columns:

- **Left — Calendar View.** A JS-generated monthly grid with `◀ ▶` month
  navigation and a month/year label. Today's cell is marked. Cells whose date
  has a booked appointment get a highlight dot/class. Clicking a booked date
  shows that appointment's details (project name, type, Facebook name,
  deadline) in a detail panel below the grid.
- **Right — Booking Form** (`<form id="appointmentForm">`). Fields: Project
  Name (text), Type of Project (`<select>` with the four options), Facebook
  Name (text), Deadline (`<input type="date">`). A submit button. The date
  input's `min` is today; already-booked dates are visually disabled and
  rejected on submit.

A status line (`#appointmentStatus`) below the card reuses the
`client-payment-status` `is-info` / `is-success` / `is-error` classes.

Styling: new CSS reuses the existing payment-card colors and adds calendar-grid
rules, including a `body.light-mode` block matching the existing light-mode
overrides.

### 2. New backend file — `appointments.php`

Mirrors `gcash_notification.php` (same `.env` loading, PHPMailer setup,
`respond()` helper, `sanitizeText()`, header set).

- **`GET`** → reads `appointments.json`, returns
  `{ ok: true, appointments: [...] }` so the calendar can render booked dates.
  Missing file → returns an empty array.
- **`POST`** → JSON body with the four fields. Steps:
  1. Sanitize/validate all four fields are present; `projectType` must be one
     of the four allowed values; `deadline` must be a valid `Y-m-d` date that
     is not in the past.
  2. Load `appointments.json`; **if any existing appointment has the same
     `deadline`, reject with HTTP 409** and message "That date is already
     booked. Please choose another date."
  3. Append the new appointment and write `appointments.json` back.
  4. Send a notification email to the owner via PHPMailer (same SMTP config and
     dark-themed HTML email style as `gcash_notification.php`). Email send
     failure is logged but does not undo the saved appointment — respond with
     `ok: true` and a note that the booking was saved.
  5. Respond `{ ok: true, message: "...", appointment: {...} }`.

Concurrency note: writes use an exclusive file lock (`LOCK_EX`) so two
simultaneous bookings for the same date cannot both succeed.

### 3. New data file — `appointments.json`

A JSON array, created automatically on first booking (like `counters.json`).
Each element:

```json
{
  "projectName": "Library System",
  "projectType": "Capstone Project",
  "facebookName": "Juan Dela Cruz",
  "deadline": "2026-06-15",
  "bookedAt": "2026-05-19 14:30:00"
}
```

### 4. Navbar

Add `<li class="nav-item"><a class="nav-link text-lg-start"
href="#appointments">Appointments</a></li>` to `#navbarNav`, between the
Payment and Gallery links.

## Data Flow

1. Page loads → JS `fetch("appointments.php")` (GET) → renders the current
   month, marking booked dates.
2. Client fills the form and submits → JS `POST` to `appointments.php`.
3. Server validates, checks the date is free, saves to `appointments.json`,
   emails the owner.
4. On `ok: true` → form resets, status shows success, calendar re-fetches and
   re-renders so the new appointment appears immediately.

## Error Handling

| Case | Behavior |
|------|----------|
| Empty / missing field | Inline HTML5 validation blocks submit; status shows which field is needed. |
| Invalid project type | Server rejects (HTTP 422). |
| Deadline in the past | Date input `min` prevents it; server also rejects (HTTP 422). |
| Date already booked | Server rejects (HTTP 409); status shows "That date is already booked. Please choose another date." |
| Network / server error | Status shows a generic retry message, matching the payment form. |
| Email send fails | Booking is still saved; status notes success with a delivery warning; error logged via `error_log`. |

## Testing

Manual verification under XAMPP:

1. `GET appointments.php` with no `appointments.json` → `{ok:true,appointments:[]}`.
2. Book an appointment → 200, `appointments.json` created with the record, owner
   receives the email, the date appears highlighted on the calendar.
3. Book a second appointment on the **same date** → 409, clear error message,
   `appointments.json` unchanged.
4. Book on a different date → succeeds, both dates show on the calendar.
5. Submit with an empty field → blocked before any request.
6. Month navigation (`◀ ▶`) → booked dates show in their correct months.
7. Toggle light/dark mode → calendar section styled correctly in both.

## Out of Scope (YAGNI)

- Editing or cancelling appointments (owner edits `appointments.json` directly
  if needed).
- Client confirmation email (owner-notification only, per decision).
- Authentication / per-client views — the calendar is a single shared view.
- Time-of-day slots — appointments are whole-day project deadlines.
