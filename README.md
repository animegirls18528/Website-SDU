# ระบบจัดเก็บเอกสารอิเล็กทรอนิกส์ (งานระบบกองคลัง - EDMS / Website-SDU)

ระบบเว็บแอปพลิเคชันสำหรับบริหารจัดการ จัดเก็บ ค้นหา ตรวจสอบความถูกต้อง และสแกนเอกสารอิเล็กทรอนิกส์ รองรับการทำงานร่วมกับเครื่องสแกนเอกสาร Canon imageFORMULA DR-G2110 ผ่าน NAPS2 Console CLI พร้อมระบบความปลอดภัยและการประทับ QR Code ตรวจสอบเอกสาร

---

## 📁 โครงสร้างโปรเจกต์ (Project Structure)

```text
Website-SDU/
├── api/                       # API Endpoints (REST-like JSON APIs)
│   ├── auth.php               # จัดการเข้าสู่ระบบ/ออกจากระบบ, ตรวจสอบ session และ brute-force lockout
│   ├── dashboard_stats.php    # รวบรวมสถิติรายงานสำหรับ Dashboard และ Audit Logs
│   ├── document.php           # CRUD เอกสาร, ประทับ QR Code ลง PDF
│   ├── export.php             # ส่งออกข้อมูลเอกสารเป็นไฟล์ CSV (UTF-8 BOM สำหรับ Excel)
│   ├── file.php               # บริการดาวน์โหลด/เปิดดูไฟล์แนบที่ปลอดภัย (Stream file พร้อมเช็คสิทธิ์)
│   ├── init.php               # โหลดข้อมูลเริ่มต้นสำหรับ Single Page Application (แฟ้ม, ฟิลด์, ข้อมูลเอกสาร)
│   ├── scan.php               # เชื่อมต่อควบคุมการสแกนเอกสาร Canon DR-G2110 ผ่าน NAPS2 CLI
│   ├── settings.php           # จัดการหมวดหมู่/แฟ้มจัดเก็บ, คำนำหน้ารหัสเอกสาร, ฟิลด์แบบกำหนดเอง, ค่าระบบ, พื้นที่และสำรองข้อมูล
│   ├── upload.php             # รับอัปโหลดไฟล์ (PDF/รูปภาพ), ตรวจสอบ MIME type และลบหน้าที่ไม่ต้องการ
│   └── users.php              # จัดการบัญชีผู้ใช้งาน (เพิ่ม/แก้ไขสิทธิ์/รีเซ็ตรหัสผ่าน/ปลดล็อก)
│
├── config/                    # ไฟล์การตั้งค่าและส่วนเสริมความปลอดภัยส่วนกลาง
│   ├── .htaccess              # ป้องกันการเข้าถึงไฟล์ใน config โดยตรงผ่านเว็บเบราว์เซอร์
│   ├── auth.php               # ระบบ Session ความปลอดภัย, ป้องกัน CSRF, ตรวจสอบสิทธิ์ Role (Admin/Staff)
│   ├── database.php           # การเชื่อมต่อฐานข้อมูล MySQL ด้วย PDO (UTF-8mb4)
│   ├── helpers.php            # ฟังก์ชันตัวช่วยส่วนกลาง เช่น addAuditLog() บันทึกประวัติการทำงาน
│   └── scanner.php            # ค่าคอนฟิกเครื่องสแกนเอกสาร Canon DR-G2110 และ Path NAPS2 CLI
│
├── uploads/                   # โฟลเดอร์เก็บไฟล์เอกสาร (PDF, รูปภาพ)
│   └── .htaccess              # ป้องกันการเรียกดูไฟล์ตรงๆ (ต้องเข้าถึงผ่าน api/file.php เท่านั้น)
│
├── composer.json              # กำหนด PHP Dependencies (FPDF, FPDI, chillerlan/php-qrcode)
├── composer.lock              # Lock เวอร์ชันของ PHP Packages
├── database.sql               # โครงสร้างฐานข้อมูลเริ่มต้น (Tables, Default Data)
├── index.php                  # หน้าหลักของระบบ (Single Page Application Dashboard & Management)
├── login.php                  # หน้าจอเข้าสู่ระบบ (Sign in)
├── logout.php                 # สคริปต์ออกจากระบบ ล้าง Session
├── setup.php                  # ตัวช่วยสร้างบัญชี Admin คนแรกเมื่อติดตั้งระบบครั้งแรก
└── verify.php                 # หน้าเว็บสำหรับสแกน QR Code เพื่อตรวจสอบความถูกต้องของเอกสาร
```

---

## 🛠️ รายละเอียดสถาปัตยกรรมและไฟล์สำคัญ

### 1. หน้าเว็บหลัก (User Interface & Frontend)
- **[index.php](file:///c:/xampp/htdocs/Website-SDU/index.php)**: 
  - ส่วนติดต่อผู้ใช้งานหลักแบบ SPA (Single Page Application)
  - มีระบบ Dashboard แสดงสถิติและกราฟ (Chart.js)
  - จัดการเอกสาร (เพิ่ม, แก้ไข, ลบ, ค้นหา, ดูตัวอย่าง PDF ด้วย PDF.js)
  - รองรับการสแกนเอกสารโดยตรงจากเครื่องสแกนเนอร์ผ่านเบราว์เซอร์
  - เมนูตั้งค่าระบบและจัดการผู้ใช้งาน (เฉพาะสิทธิ์ Admin)
- **[login.php](file:///c:/xampp/htdocs/Website-SDU/login.php)**: หน้าฟอร์มเข้าสู่ระบบ ป้องกัน Brute-force Login และ Session Fixation
- **[setup.php](file:///c:/xampp/htdocs/Website-SDU/setup.php)**: ใช้งานได้เฉพาะเมื่อยังไม่มีผู้ใช้ในระบบ เพื่อกำหนดรหัสผ่านบัญชี Admin คนแรก
- **[verify.php](file:///c:/xampp/htdocs/Website-SDU/verify.php)**: หน้าสาธารณะสำหรับตรวจสอบข้อมูลเอกสาร เมื่อผู้ใช้งานใช้มือถือสแกน QR Code ที่ถูกประทับไว้บนไฟล์ PDF

### 2. โมดูล API (`/api`)
- **[api/scan.php](file:///c:/xampp/htdocs/Website-SDU/api/scan.php)**: คอยรับคำสั่งสแกนเอกสาร ส่งคำสั่งไปยัง Command Prompt เรียก `NAPS2.Console.exe` เพื่อดึงกระดาษจากถาด ADF ของ Canon DR-G2110 และรวมเป็นไฟล์ PDF อัตโนมัติ
- **[api/document.php](file:///c:/xampp/htdocs/Website-SDU/api/document.php)**: ควบคุมข้อมูลเอกสาร และใช้ FPDI + php-qrcode ในการประทับ QR Code (ลิงก์ไปยัง `verify.php?id=...`) ลงที่มุมขวาล่างของทุกหน้าในไฟล์ PDF
- **[api/file.php](file:///c:/xampp/htdocs/Website-SDU/api/file.php)**: File streaming gatekeeper เช็ค Session ผู้ใช้ก่อนส่งไฟล์ ป้องกัน Directory Traversal
- **[api/upload.php](file:///c:/xampp/htdocs/Website-SDU/api/upload.php)**: จัดการอัปโหลดไฟล์ ตรวจสอบประเภทไฟล์ และฟังก์ชันลบหน้า PDF ที่ไม่ต้องการออกก่อนบันทึก
- **[api/dashboard_stats.php](file:///c:/xampp/htdocs/Website-SDU/api/dashboard_stats.php)**: คำนวณยอดเอกสารรายสัปดาห์, อัตราส่วนแฟ้ม, Active Users และดึง Audit Logs
- **[api/users.php](file:///c:/xampp/htdocs/Website-SDU/api/users.php)**: ควบคุมผู้ใช้ เพิ่ม/แก้ไขสิทธิ์ ปลดล็อกบัญชี และเปลี่ยนรหัสผ่าน

### 3. โครงสร้างฐานข้อมูล (`database.sql`)
ฐานข้อมูลชื่อ `dms_system` ประกอบด้วยตารางสำคัญ:
- `documents`: ข้อมูลเอกสารหลัก (รหัสเอกสาร, ชื่อเอกสาร, แฟ้ม, วันที่, พาธไฟล์, CustomData รูปแบบ JSON)
- `folders`: โครงสร้างแฟ้มจัดเก็บเอกสาร
- `prefixes`: คำนำหน้าเลขเอกสารสำหรับสร้างรหัสอัตโนมัติ (เช่น DOC, INV)
- `custom_fields`: ฟิลด์ข้อมูลเพิ่มเติมที่กำหนดเองแบบ Dynamic
- `users`: ข้อมูลผู้ใช้งาน, แฮชรหัสผ่าน (bcrypt), สิทธิ์ (`admin`, `staff`), ประวัติล็อกอินล้มเหลว
- `audit_logs`: บันทึกกิจกรรมของระบบ (การอัปโหลด, ลบ, แก้ไข, การตั้งค่า, พร้อม IP Address)
- `system_settings`: การตั้งค่าระบบ เช่น URL สาธารณะ อายุการเก็บเริ่มต้น

---

## 🚀 ความต้องการของระบบ (Prerequisites)

> ⚠️ **หมายเหตุสำหรับการนำไป Hosting:** ระบบนี้ออกแบบมาสำหรับ Traditional Web Server (เช่น Shared Hosting, VPS หรือ XAMPP) **ไม่รองรับ Serverless Platform อย่าง Vercel** เนื่องจากมีการใช้งาน Local File System (`uploads/`) และใช้งานระบบ PHP Session พื้นฐาน

1. **Web Server**: Apache บน XAMPP หรือ IIS
2. **PHP**: เวอร์ชัน 7.4 ขึ้นไป (แนะนำ PHP 8.x) พร้อม Extensions:
   - `pdo_mysql`
   - `gd`
   - `fileinfo`
   - `mbstring`
3. **Database**: MySQL หรือ MariaDB
4. **Composer**: สำหรับติดตั้ง PHP dependencies
5. **(ตัวเลือก)** อุปกรณ์สแกนเอกสาร:
   - เครื่องสแกนเอกสาร **Canon imageFORMULA DR-G2110** (ติดตั้ง Driver TWAIN เรียบร้อย)
   - โปรแกรม **NAPS2 (Desktop)** ติดตั้งที่เครื่อง Server (จำเป็นสำหรับการใช้งานเมนูสแกนผ่านระบบ)

---

## 📦 การติดตั้งและเริ่มต้นใช้งาน (Installation)

### 1. ติดตั้ง Dependencies
เปิด Terminal หรือ Command Prompt ในโฟลเดอร์โปรเจกต์:
```bash
composer install
```

### 2. นำเข้าฐานข้อมูล
1. เปิด **phpMyAdmin** หรือ MySQL CLI
2. นำเข้าไฟล์ [database.sql](file:///c:/xampp/htdocs/Website-SDU/database.sql):
```bash
mysql -u root -p < database.sql
```

### 3. ตั้งค่าการเชื่อมต่อฐานข้อมูล
ตรวจสอบค่าการเชื่อมต่อใน [config/database.php](file:///c:/xampp/htdocs/Website-SDU/config/database.php):
```php
$host = 'localhost';
$dbname = 'dms_system';
$username = 'root';
$password = '';
```

### 4. การตั้งค่าระบบสแกนเอกสาร (Scanner Setup)
ตรวจสอบการตั้งค่าใน [config/scanner.php](file:///c:/xampp/htdocs/Website-SDU/config/scanner.php):
- ตรวจสอบว่า `NAPS2_CONSOLE_PATH` ชี้ไปยังตำแหน่งของ `NAPS2.Console.exe` ที่ติดตั้งจริง (เช่น `C:\Program Files\NAPS2\NAPS2.Console.exe`)
- ชื่อเครื่องสแกนใน `SCANNER_DEVICE_NAME` ต้องตรงกับ TWAIN Driver ของ Canon

### 5. ตั้งค่าบัญชีผู้ใช้เริ่มต้น
1. เข้าเบราว์เซอร์ไปที่: `http://localhost/Website-SDU/setup.php`
2. กำหนด Username และ Password สำหรับผู้ดูแลระบบคนแรก (Admin)
3. เมื่อตั้งค่าสำเร็จ ระบบจะพาไปยังหน้า Dashboard [index.php](file:///c:/xampp/htdocs/Website-SDU/index.php) โดยอัตโนมัติ

---

## 🔒 ฟีเจอร์ความปลอดภัย (Security Features)

- **CSRF Protection**: มีการตรวจสอบ CSRF Token ทุกคำขอประเภท POST / State-changing
- **Brute-Force Protection**: ล็อกบัญชีชั่วคราวเมื่อล็อกอินผิดติดต่อกันเกินจำนวนครั้งที่กำหนด
- **Secured File Access**: โฟลเดอร์ `uploads/` ถูกปิดกั้นการเข้าถึงจาก URL ตรง การเข้าถึงไฟล์ทุกไฟล์ต้องผ่าน `api/file.php` ซึ่งตรวจสอบสิทธิ์ Session ก่อนเสมอ
- **Audit Logs**: มีระบบบันทึกประวัติการกระทำสำคัญทุกขั้นตอนพร้อม IP Address ของผู้ใช้งาน
- **QR Code Verification**: ประทับ QR Code อัตโนมัติลงในหน้าเอกสาร PDF ทุกหน้า เพื่อให้บุคคลภายนอกสแกนตรวจสอบความถูกต้องผ่าน `verify.php` ได้ทันที

---

## ✨ ฟีเจอร์ที่เพิ่มในเวอร์ชันปรับปรุง (ต.ค. 2569)

### การค้นหาและคลังเอกสาร
- ค้นหาฝั่งเซิร์ฟเวอร์ แบ่งหน้า ค้นได้ทุกเอกสาร (เดิมค้นได้เฉพาะ 1,000 ฉบับล่าสุด) จากรหัส ชื่อเรื่อง แท็ก ดัชนีเพิ่มเติม และข้อความในไฟล์ PDF
- ตาราง `document_search` เก็บข้อความค้นหาที่ปรับรูปแบบแล้ว (ตัวพิมพ์เล็ก, รวม "ํา" เป็น "ำ") จึงค้นครั้งเดียวต่อคำค้น
- ตัวกรอง: แฟ้ม ช่วงวันที่ ใกล้ครบอายุ ครบอายุแล้ว รอตรวจสอบ ฉบับร่าง ขอทำลาย ยังไม่มีไฟล์ ถังขยะ · ส่งออก CSV ตามตัวกรองปัจจุบัน
- หน้ารายละเอียดเอกสาร: ข้อมูลดัชนี ไฟล์ปัจจุบัน เวอร์ชันเก่า และประวัติการใช้งานรายเอกสาร

### การจัดเก็บไฟล์
- ไฟล์อยู่ใน `uploads/ปี/เดือน/` · ตาราง `document_files` เก็บ SHA-256 จำนวนหน้า ผู้อัปโหลด และเวอร์ชัน
- เก็บไฟล์ต้นฉบับ (`*_orig.pdf`) แยกจากฉบับที่ประทับ QR · แทนที่ไฟล์แล้วฉบับเดิมยังอยู่ในประวัติเวอร์ชัน
- ลบเอกสาร = ย้ายไปถังขยะ (กู้คืนได้) ลบถาวรอัตโนมัติเมื่อครบจำนวนวันที่ตั้งไว้ (ค่าเริ่มต้น 30 วัน)
- เลขเอกสารจองแบบ atomic (`doc_sequences`) ถ้ามีผู้อื่นใช้เลขไปก่อน ระบบออกเลขถัดไปให้
- หน้าจัดหน้าเอกสาร: หมุน ลบ เรียงหน้า และรวมหลายไฟล์ (รวมรูปภาพ) เป็น PDF เดียว ทำในเบราว์เซอร์ด้วย pdf-lib

### QR Code และการตรวจสอบ
- QR ใช้ token สุ่ม (`verify.php?t=...`) เดาไม่ได้ · QR รุ่นเก่า (`?id=`) ยังใช้ได้แต่แสดงข้อมูลน้อยลง
- ผู้ถือไฟล์อัปโหลดไฟล์ในหน้าตรวจสอบเพื่อเทียบ SHA-256 กับต้นฉบับได้ (ตรวจจับไฟล์ที่ถูกแก้ไข)
- แก้บั๊กเดิม: การประทับ QR ทำให้มีหน้าว่างแทรกหลังทุกหน้า
- PDF จากโปรแกรมสมัยใหม่ (compressed xref) ถูกบันทึกใหม่ในเบราว์เซอร์ก่อนอัปโหลด เพื่อให้ประทับ QR ได้

### การสแกน
- คิวสแกนทำงานเบื้องหลัง (`tools/scan_worker.php`) หน้าเว็บไม่ค้าง สแกนได้ทีละงาน งานอื่นรอคิว ยกเลิกงานที่รอคิวได้
- ตัวเลือกใหม่: ปรับภาพเอียง, ข้ามหน้าว่าง, OCR ภาษาไทย/อังกฤษ, แยกเอกสารด้วยแผ่นคั่น Patch-T, บันทึกเป็น PDF/A-2b, เลือกเครื่องสแกน (TWAIN / WIA / eSCL เครือข่าย)
- แก้บั๊กเดิม: ไฟล์ที่สแกนเปิดดูไม่ได้ (`file.php` รับเฉพาะชื่อ `doc_`) และหน้าเว็บแสดงคำสั่งระบบเมื่อสแกนผิดพลาด

### สิทธิ์ ขั้นตอนงาน และวงจรชีวิตเอกสาร
- บทบาทใหม่ "ผู้ดูเอกสาร" (viewer) ดูและค้นหาได้อย่างเดียว · จำกัดแฟ้มที่ผู้ใช้แต่ละคนเข้าถึงได้
- บันทึก audit log ตอนเปิดดูและดาวน์โหลดไฟล์
- ขั้นตอน: ฉบับร่าง → รอตรวจสอบ → อนุมัติ (ผู้ดูแลระบบอนุมัติหรือตีกลับ)
- อายุการเก็บกำหนดตามประเภทเอกสาร (prefix) ได้ · ขออนุมัติทำลายเอกสารที่ครบอายุ → ผู้ดูแลอนุมัติ → ถังขยะ

### ดูแลระบบ
- หน้า "พื้นที่และสำรองข้อมูล": ขนาดไฟล์ พื้นที่ดิสก์ ไฟล์ขยะที่ไม่ได้ผูกกับเอกสาร สั่งอ่านข้อความในไฟล์ทั้งหมด
- สำรองข้อมูล (`tools/backup.php`): ฐานข้อมูล (gzip) และไฟล์ทั้งหมด ไปที่ `BACKUP_DIR` นอก htdocs เก็บ 14 ชุดล่าสุด

## ⚙️ การตั้งค่าเพิ่มเติม

- **`config/app.php`**: path ของ PHP CLI, mysqldump, โฟลเดอร์สำรองข้อมูล, Tesseract (ไม่บังคับ)
- **ฐานข้อมูลอัปเกรดอัตโนมัติ**: `config/migrate.php` เพิ่มตารางหรือคอลัมน์ใหม่ในการเรียกใช้งานครั้งแรก (เพิ่มอย่างเดียว ไม่ลบหรือเปลี่ยนชื่อของเดิม)
- **OCR ตอนสแกน**: ติดตั้ง NAPS2 แล้วดาวน์โหลดชุดภาษา OCR ภาษาไทยในโปรแกรม NAPS2 หนึ่งครั้ง
- **สำรองข้อมูลอัตโนมัติทุกวัน** (Command Prompt แบบ Administrator):
  ```
  schtasks /Create /SC DAILY /ST 02:00 /TN "EDMS Backup" /TR "C:\xampp\php\php.exe C:\xampp\htdocs\Website-SDU-main\Website-SDU-main\tools\backup.php"
  ```
- **ความเร็วค้นหา**: XAMPP ตั้ง `innodb_buffer_pool_size=16M` ซึ่งเล็กมาก แนะนำปรับใน `C:\xampp\mysql\bin\my.ini` เป็น `512M` แล้วรีสตาร์ต MySQL
- **อ่านข้อความไฟล์ย้อนหลัง**: `php tools/extract_text.php --all`
