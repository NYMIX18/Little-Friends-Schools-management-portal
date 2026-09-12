# Little Friends Schools — School Management Portal

A PHP + MySQL school management system built for **Little Friends Schools**
(Bomet, Sotik-Chebole, Kenya). It combines a public marketing site with a
role-based portal for admins, teachers, students, and parents — including
mark entry, auto-generated report cards, fee tracking, and inventory
management.

> Built as a single-repo PHP app (no framework) — designed to run on a
> standard LAMP/WAMP/XAMPP stack.

---

## ✨ Features

**Public site**
- Marketing landing page with hero video, programs, testimonials, and contact info
- PWA-ready (installable, offline shell via service worker, custom manifest)
- WhatsApp-first admissions contact flow

**Authentication**
- Single login/registration entry point with three roles: **Student**,
  **Teacher**, **Admin**
- First-time account activation flow (students/teachers verify identity
  via admission number or registered phone, then set a password)
- Auto-generated usernames for teachers (initials + phone digits)

**Admin dashboard**
- Manage students, teachers, classes, fees, and inventory
- View academic reports across the school

**Teacher dashboard**
- Select class → term → exam type, then enter marks per student per subject
- Live grade calculation (CBE grading: A/B/C/D/E) with visual score bars
- Auto-generated printable report cards with per-student averages and remarks
- Notifications panel and school newsletter display

**Student / Parent dashboards**
- View results, fees, and class information

---

## 🛠️ Tech Stack

- **Backend:** PHP 8+ (mysqli, prepared statements)
- **Database:** MySQL / MariaDB
- **Frontend:** Vanilla HTML/CSS/JS, Bootstrap 5 (admin CRUD screens),
  Bootstrap Icons, Google Fonts
- **PWA:** Custom manifest + service worker served dynamically from PHP

---

## 📂 Project Structure

```
little_friends_schools/
├── index.php                  # Public site + login/registration portal
├── auth/
│   ├── process_login.php      # Legacy/alternate login handler
│   └── logout.php
├── config/
│   ├── db.example.php         # Template — copy to db.php and fill in credentials
│   └── db.php                 # Your local DB credentials (gitignored)
├── dashboards/
│   ├── admin.php
│   ├── teacher.php
│   ├── teacher_dashboard.php  # Main teacher mark-entry + report card UI
│   ├── student.php
│   ├── parent.php
│   ├── add_student.php
│   ├── add_teacher.php
│   ├── classes.php
│   ├── fees.php
│   ├── inventory.php
│   ├── reports.php
│   └── view_students.php
└── assests/                   # Static images
```

---

## 🚀 Getting Started

### Requirements
- PHP 8.0+
- MySQL/MariaDB
- Apache/Nginx (or PHP's built-in server) — e.g. via XAMPP/WAMP/MAMP

### 1. Clone the repo
```bash
git clone https://github.com/<your-username>/little_friends_schools.git
cd little_friends_schools
```

### 2. Configure the database
```bash
cp config/db.example.php config/db.php
```
Edit `config/db.php` with your local MySQL credentials.

### 3. Create the database
Create a MySQL database named `little_friends_schools` and set up the
following core tables (adjust to match your actual schema):

- `users` (id, name, username, phone, phone_number, email, password, role, profile_pic)
- `students` (id, user_id, admission_number, class_id)
- `teachers` (id, user_id, phone, is_class_teacher)
- `classes` (id, class_name, termly_fees)
- `subjects` (id, subject_name)
- `terms` (id, term_name)
- `marks` (id, student_id, subject_id, teacher_id, term_id, score, grade, exam_type, level, created_at)
- `notifications`, `newsletters` (optional)

> ⚠️ A full SQL dump/migration file isn't included yet for security reasons since its an actively working website. Permission acquired first before commits

### 4. Serve the app
Using PHP's built-in server (from the project root):
```bash
php -S localhost:8000
```
Or place the folder in your Apache/XAMPP `htdocs` directory and visit
`http://localhost/little_friends_schools/`.

---

## 🔐 Security Notes

- All auth queries use **prepared statements** (mysqli, parameter binding).
- Passwords are hashed with `password_hash()` / verified with `password_verify()`.
- `config/db.php` is gitignored — never commit real database credentials.
- The admin login includes a one-time legacy plaintext-password migration
  path (upgrades old plaintext passwords to hashed ones on first successful
  login) — safe to remove once all admin accounts have logged in at least once.

---

## 🗺️ Roadmap / Known Gaps

- [ ] Add a `schema.sql` migration file for one-command setup
- [ ] Add a proper "forgot password" flow (currently: contact school office)
- [ ] Add automated tests
- [ ] Move inline `mysqli_query` string concatenation in a few older
      dashboard files (e.g. `classes.php`) to prepared statements

---

## 📄 License

Proprietary — © Little Friends Schools. Not licensed for reuse without
permission from the school administration.
