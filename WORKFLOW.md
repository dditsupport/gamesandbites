# Games N Bites — Booking Workflow

End-to-end flow of the booking system, from the visitor and the admin perspective. Use this as the quick reference for how a booking moves through the app.

---

## 🧑 Visitor flow

```
Home page  (index.php)
   │
   │  • See venue map, rules image, slot grid for the chosen date
   │  • Pick a date  (today  →  today + booking-window days)
   │  • Slot grid shows one of:
   │        Available · In process · Payment done · Booked · Past
   ▼
Pick an available slot
   │  • Blocked dates  →  "Bookings are closed" notice; no booking allowed
   │  • Past / Booked / In process / Payment-done  →  not clickable
   │  • Tap to book  →  book.php
   ▼
Booking form  (book.php)
   │  • Enter Full name        (letters / spaces only · mandatory)
   │  • Enter Mobile number    (exactly 10 digits · mandatory)
   │  • Choose payment method  (UPI / Cash — whichever admin enabled)
   │  • Optional: apply coupon (only if allowed for this slot+weekday)
   │  • Place booking
   │        ↓ creates row in `bookings` with  status = 'pending'
   │        ↓ slot is now held (locked from others)
   │        ↓ admin gets ntfy push:  "New booking …"
   ▼
Confirmation page  (confirm.php)
   │
   ├── UPI path
   │     • Shown: "⏳ Pay & submit proof within 5 min, or slot is released"
   │     • Open UPI app via deep-link OR scan QR · pay the advance
   │     • Submit UTR  and / or  upload payment screenshot
   │     • Screenshot is auto-compressed in the browser before upload
   │     • Admin gets a 2nd ntfy push: "Payment proof: GNB-XXXX"
   │     • If NO proof in 5 min  →  slot auto-releases (see lifecycle)
   │
   └── Cash path
         • Shown booking ID to present at the venue
         • Slot stays held until admin handles it
           (cash bookings are NOT on the 5-min timer)
```

---

## 🛡️ Admin flow

```
admin/index.php  (login)
   ▼
Dashboard
   │  • Today's bookings, pending count, this-month revenue, recent 10
   │  • Upload / replace the Rules image (auto-compressed in browser)
   ▼
Bookings list  (bookings.php)
   │  • Newest orders on top (sorted by created_at)
   │  • Filter by status, search by name/mobile/code, date range
   │  • Each row: code, customer, slot date · slot time · placed time,
   │              payment, proof, status
   │  • "Open"  →
   ▼
Booking detail  (booking.php)
   │  • Review customer info, slot, payment proof (UTR + screenshot)
   │  • Optional: adjust total, record pending payment, write internal note
   │  • Action:
   │       ├── ✅ Confirm         →  status = 'confirmed'
   │       ├── ❌ Cancel          →  status = 'cancelled' (slot releases)
   │       └── ↩  Move to pending
   ▼
Notify customer  (WhatsApp)
       • Button pre-fills the right template based on status:
            confirmed  →  full BOOKING CONFIRMED template
                          (rules · venue · map link · contact)
            cancelled  →  BOOKING CANCELLED template (5 reasons)
            pending    →  short acknowledgement
       • Opens WhatsApp on the customer's number; admin taps Send
       • "Preview / copy message" textarea for SMS / email use
```

### Other admin pages

- **Slots & Rates** — add/edit slots, per-weekday rates, per-weekday *"coupons allowed"* flags, CSV **Export / Update existing / Append / Replace**, and **Block dates** (close bookings on holidays).
- **Coupons** — create flat-discount codes; set usage limit, expiry, active. *(Per-slot/weekday coupon control lives on Slots & Rates.)*
- **Settings** — venue info, payment methods on/off, UPI ID + QR (auto-compressed), ntfy push topic, change admin password.

---

## 🔄 Slot lifecycle (what shows in the customer grid)

```
Visitor places booking  (status = pending, no payment proof)
        │
        ├── UPI, within 5-min grace
        │       grid shows:  "In process · try again in 5 min"   (amber)
        │
        ├── UPI with UTR/screenshot submitted   OR   Cash booking
        │       grid shows:  "Payment done · awaiting confirmation"  (blue)
        │       held — NOT auto-released; waits for admin
        │
        └── UPI, 5 min passed, still no proof
                slot auto-releases (lazy: query-level, no cron required)
                grid shows:  "Tap to book"   again
                the original pending row stays in DB but stops locking
                the slot — admin can cancel it later for tidiness

Admin confirms   (status = 'confirmed')
        grid shows:  "Booked"  +  🏏 customer name   (red)

Admin cancels    (status = 'cancelled')
        slot is free, grid shows:  "Tap to book"

Time passes (today's slots that have already started)
        grid shows:  "Past"   (greyed out, not clickable)
```

---

## ⏱️ Key rules at a glance

| What | Where it's set | Default |
|------|----------------|---------|
| How many days ahead bookings are open | Settings → Booking window | 7 days |
| Advance the customer pays online | Settings → Advance amount | ₹500 |
| Grace window for unpaid UPI before slot releases | `SLOT_HOLD_MINUTES` in `includes/helpers.php` | 5 min |
| Cash slot release | n/a — held until admin acts | — |
| Blocked dates | Slots & Rates → Block dates | none |
| Coupon allowed per slot+weekday | Slots & Rates → Edit slot → Coupons | all weekdays on |
| Image compression (browser-side) | All file inputs with `class="js-compress"` | max 1600px JPEG q0.82 |

---

## 📦 Data model — the parts that matter

- **`slots`** — slot templates: start/end time, `*_enabled` per weekday, `*_rate` per weekday, `*_coupon` per weekday (1 = coupons allowed).
- **`bookings`** — `status` ∈ `pending` / `confirmed` / `cancelled`; `payment_method` ∈ `cash` / `upi`; `upi_utr`, `upi_screenshot` (proof); `pending_amount/method/remarks` (admin records balance at venue); `extra_discount` (admin adjustment).
- **`coupons`** — flat ₹ off, usage limit, expiry, active flag.
- **`blocked_dates`** — calendar dates with all bookings disabled (holidays / private events / maintenance).
- **`settings`** — venue name/address, contact phone, UPI ID + QR, ntfy topic, rules image path, advance amount, booking window days.

---

## 🛠️ Maintenance notes

- **Sandbox-style auto-release of unpaid UPI bookings** runs lazily inside `slot_hold_sql()`; there is no cron. Stale pending rows simply stop locking the slot after 5 minutes.
- **The rules image** is whatever `settings.rules_image` points to, with a fallback to `assets/rules.jpg`. Replace from Dashboard → Rules image.
- **The map embed** on the home page uses `maps.google.com/maps?q=…&output=embed` (no API key). The "Get directions" button uses the exact venue share link.
- **CSV imports** detect comma / semicolon / tab automatically and strip UTF-8 BOM, so files edited and re-saved from Excel import cleanly.
- **Migration `migrations/004_blocked_dates_and_coupon_days.sql`** is required on production for the block-dates and per-weekday coupon features. Run it once in phpMyAdmin.
