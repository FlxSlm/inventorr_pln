# Testing Mode - Login Security

## 📌 Setting Konfigurasi

File: `app/auth/login.php` (baris 14-26)

### 1. Testing Mode

```php
define('TESTING_MODE', false); // <-- Ubah ke true saat testing
```

**Production (false)**:
- Lockout menggunakan waktu normal (menit)
- 1 menit = 60 detik

**Testing (true)**:
- Lockout dipercepat 60x
- 1 "menit" = 1 detik
- 5 "menit" = 5 detik
- 30 "menit" = 30 detik

### 2. Login Security

```php
define('ENABLE_LOGIN_SECURITY', true); // <-- Ubah ke false untuk matikan
```

**Enabled (true)**: Rate limiting aktif
**Disabled (false)**: Tidak ada lockout sama sekali

### 3. Admin Default Password

```php
define('ADMIN_DEFAULT_PASSWORD', 'plnadmin2026');
```

Password ini **HANYA berlaku untuk akun admin** (role='admin').
Karyawan (role='user') **TIDAK BISA** menggunakan password default.

---

## 🧪 Cara Testing

### Step 1: Aktifkan Testing Mode

Edit `app/auth/login.php` baris 22:

```php
define('TESTING_MODE', true); // <-- Ubah jadi true
```

### Step 2: Testing Login Admin

1. Buka halaman login
2. Masukkan email admin (contoh: `admin@pln.com`)
3. Salah password 5 kali → Muncul form password default
4. Masukkan password default: `plnadmin2026`

### Step 3: Testing Lockout (jika salah password default)

Dengan `TESTING_MODE = true`:
- Percobaan 1 gagal → **1 detik** lockout (bukan 1 menit)
- Percobaan 2 gagal → **2 detik** lockout (bukan 2 menit)
- Percobaan 3 gagal → **5 detik** lockout (bukan 5 menit)

### Step 4: Reset Attempts (jika perlu)

Jalankan query SQL:

```sql
DELETE FROM admin_default_password_attempts;
DELETE FROM login_attempts;
```

---

## 📊 Lockout Duration

### Normal Login (5+ failed attempts)

| Percobaan | Production | Testing Mode |
|-----------|------------|--------------|
| 5 failed  | 1 menit    | 1 detik      |
| 6 failed  | 2 menit    | 2 detik      |
| 7 failed  | 5 menit    | 5 detik      |
| 8 failed  | 10 menit   | 10 detik     |
| 9 failed  | 15 menit   | 15 detik     |
| 10+ failed| 30 menit   | 30 detik     |

### Default Password (admin only)

| Percobaan | Production | Testing Mode |
|-----------|------------|--------------|
| 1 failed  | 1 menit    | 1 detik      |
| 2 failed  | 2 menit    | 2 detik      |
| 3 failed  | 5 menit    | 5 detik      |
| 4 failed  | 10 menit   | 10 detik     |
| 5+ failed | 30 menit   | 30 detik     |

---

## ⚠️ PENTING: Sebelum Production

Jangan lupa **KEMBALIKAN** setting ke production:

```php
// app/auth/login.php
define('TESTING_MODE', false);  // ← Set ke false
define('ENABLE_LOGIN_SECURITY', true);  // ← Set ke true
```

---

## 🔒 Keamanan Password Default

### ✅ Yang Bisa (Admin Only):
- Email dengan `role='admin'` di database
- Setelah 5x gagal login normal
- Password default: `plnadmin2026`

### ❌ Yang Tidak Bisa (Karyawan):
- Email dengan `role='user'` (karyawan)
- Tidak akan muncul form password default
- Harus pakai fitur reset password biasa

---

## 🛠 Debug Commands

### Cek attempts admin tertentu:
```sql
SELECT * FROM admin_default_password_attempts 
WHERE email = 'admin@pln.com' 
ORDER BY attempted_at DESC;
```

### Cek attempts dari IP tertentu:
```sql
SELECT * FROM login_attempts 
WHERE ip_address = '127.0.0.1' 
ORDER BY attempted_at DESC;
```

### Hapus semua lockout:
```sql
DELETE FROM admin_default_password_attempts;
DELETE FROM login_attempts;
```

---

## 📝 Catatan

1. **Testing Mode** hanya untuk development/testing
2. **WAJIB disabled** di production server
3. Password default **hanya admin**, bukan karyawan
4. Lockout tracking per IP + email (tidak global)
5. Auto-cleanup attempts > 24 jam
