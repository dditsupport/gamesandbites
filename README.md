# Games N Bites — Slot Booking System

Lightweight PHP 8.4 + MySQL booking system. No frameworks, no Composer. Drop on MilesWeb shared hosting and go.

## What it does

- Customers pick a date + slot, enter name + mobile, pay ₹500 advance
- Advance: Cash (pay at venue) or UPI (deep link + QR + screenshot/UTR upload)
- **Discount coupons:** admin creates codes (e.g. GNB100, GNB200, GNB300) with flat ₹ discount, optional usage limit and expiry. Customers enter code on the booking page to reduce slot total.
- Slot is held in "pending" state and blocks others from booking it
- Admin gets a push notification (via ntfy.sh) on every new booking
- Admin verifies UPI screenshot/UTR → confirms or cancels
- Slot rules:
  - Each slot duration is configurable (1 hour, 2 hours for night, anything)
  - Admin sets rates per slot
  - Advance is fixed at ₹500 regardless of slot price (configurable)
  - Overnight slots supported (e.g. 11 PM – 1 AM)

## File structure

```
booking/
├── index.php            ← customer slot grid
├── book.php             ← customer booking form
├── confirm.php          ← order placed + UPI proof upload
├── install.sql          ← run this once in phpMyAdmin
├── admin/               ← admin panel (login, dashboard, bookings, slots, settings)
├── assets/
│   ├── css/style.css
│   └── uploads/         ← UPI screenshots + QR image stored here
├── config/config.php    ← DB credentials — EDIT THIS
└── includes/            ← bootstrap, helpers
```

## Deploy to MilesWeb

### 1. Create database
- Log in to MilesWeb cPanel
- Open **MySQL Databases**
- Create a new database (e.g. `yourname_booking`)
- Create a new user with a strong password
- Add the user to the database with **ALL PRIVILEGES**
- Note down: database name, username, password, host (usually `localhost`)

### 2. Upload files
- Zip the entire `booking/` folder
- In cPanel → **File Manager**, navigate to `public_html/` (or a subfolder like `public_html/booking/` if you want it at `yoursite.com/booking/`)
- Upload the zip, extract it, delete the zip
- Make sure `assets/uploads/` is writable (chmod 755 or 775 — usually default works on MilesWeb)

### 3. Configure
- Edit `config/config.php` and fill in your DB credentials
- Save

### 4. Run schema
- Open cPanel → **phpMyAdmin**
- Select your database
- Click **Import** tab
- Choose `install.sql` and run it
- (After import, you can delete `install.sql` from the server for safety)

**If you're upgrading an older install** (already had bookings before coupon support was added), instead import `migrations/001_add_coupons.sql` to add only the new coupon tables/columns.

### 5. First login
- Visit `https://yoursite.com/booking/admin/` (or `admin/index.php`)
- Default credentials: `admin` / `admin123`
- **Immediately go to Settings → Change admin password**

### 6. Configure your venue
- Settings → set venue name, address, contact phone, advance amount (₹500)
- Settings → UPI: enter your UPI ID, payee name, upload your QR image
- Settings → Notifications: enter a random ntfy topic (see below)
- Slots & Rates → add your slots (e.g. 21:00–23:00 ₹1000, 23:00–01:00 ₹1000)

## ntfy push notifications setup

1. On your phone, install the **ntfy** app (Play Store / App Store) — free
2. Pick a long, random, secret topic name (e.g. `gnb-bookings-x7k9q-9f2`). Anyone who knows the topic can read your alerts, so keep it private.
3. In the ntfy app, tap "+" → subscribe to that exact topic
4. In your admin Settings → Notifications, paste the same topic, save
5. Click **Send test notification** — you should see a push within seconds
6. From now on, every booking pings your phone with customer name, slot, date, amount

## Security notes

- `.htaccess` files block PHP execution in `assets/uploads/` and direct access to `config/` and `includes/`
- HTTPS is recommended (MilesWeb provides free SSL via cPanel → Let's Encrypt)
- Change the default admin password before going live
- Delete `install.sql` from the server after import
- Set `'debug' => false` in `config/config.php` for production (default)

## Things you may want to tweak

- **Auto-confirm cash bookings on creation?** Currently all bookings start as pending. If you'd rather confirm cash automatically, set status to `confirmed` in `book.php` when `payment_method === 'cash'`.
- **Time zone**: Set in your PHP config or add `date_default_timezone_set('Asia/Kolkata');` at the top of `includes/bootstrap.php` if your server is on UTC.
- **Auto-cancel old pending bookings**: Add a cron job that runs daily and cancels pending bookings older than X hours.

## Default test credentials

- Admin: `admin` / `admin123` (change immediately)
