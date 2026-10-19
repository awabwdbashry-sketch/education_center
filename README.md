# 🎓 نظام إدارة المراكز التعليمية | Education Center Management System

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-ES6%2B-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)
![HTML5](https://img.shields.io/badge/HTML5-E34F26?style=for-the-badge&logo=html5&logoColor=white)
![CSS3](https://img.shields.io/badge/CSS3-1572B6?style=for-the-badge&logo=css3&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5_RTL-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)

**نظام عربي متكامل لإدارة المراكز التعليمية، يجمع العمليات التعليمية والإدارية والمالية في منصة واحدة.**

[المميزات](#-مميزات-النظام) • [التقنيات](#️-التقنيات-المستخدمة) • [التثبيت](#-طريقة-التثبيت) • [English](#english)

</div>

---

## 📌 نبذة عن المشروع

صممت نظام متكامل لإدارة المراكز التعليمية، يجمع كل تفاصيل المركز في مكان واحد، من إدارة الطلاب والمدرسين، إلى تنظيم المجموعات والمواد والمراحل التعليمية والحصص والجداول.

يوفر النظام أدوات لمتابعة تقييمات الطلاب، وتسجيل الحضور باستخدام الباركود، وإدارة الرسوم والمدفوعات والإيصالات والمصروفات، بالإضافة إلى تنظيم مستحقات المدرسين ومتابعة التفاصيل المالية.

تم تطوير المشروع بواجهة عربية تدعم اتجاه الكتابة من اليمين إلى اليسار **RTL**، مع قاعدة بيانات مترابطة تساعد على تنظيم المعلومات وتسهيل الوصول إليها.

الهدف هو توفير أداة عملية تساعد إدارة المركز على متابعة عملياته اليومية بصورة أوضح وأكثر تنظيماً.

## ✨ مميزات النظام

### 🎓 الإدارة التعليمية
- إدارة بيانات الطلاب وملفاتهم وبطاقاتهم.
- إدارة المدرسين وبياناتهم ومستحقاتهم.
- تنظيم المجموعات التعليمية والمراحل الدراسية.
- إدارة المواد الدراسية وربطها بالمجموعات.
- تنظيم الحصص والجداول التعليمية.
- إدارة تقييمات الطلاب ومتابعة نتائجهم.

### 📷 الحضور باستخدام الباركود
- تسجيل حضور الطلاب بالباركود.
- تسهيل الوصول إلى بيانات الطالب أثناء تسجيل الحضور.
- دعم تسجيل الحضور اليدوي عند الحاجة.
- تنظيم سجلات الحضور لمتابعتها ومراجعتها.

### 💰 الإدارة المالية
- متابعة رسوم الطلاب والاستحقاقات المالية.
- تسجيل دفعات الطلاب ومتابعة عمليات التحصيل.
- إصدار الإيصالات ومراجعة تفاصيل المدفوعات.
- طباعة باركودات التحصيل وفق آلية النظام.
- تسجيل المدفوعات والمستحقات الخاصة بالمدرسين.
- إدارة المصروفات ومتابعة التفاصيل المالية.
- تنظيم السجلات المالية لتسهيل مراجعتها.

### ⚙️ الإدارة والصلاحيات
- لوحة تحكم لمتابعة المعلومات الأساسية.
- إدارة المستخدمين والأدوار والصلاحيات.
- تنبيهات لمتابعة بعض العمليات المهمة.
- تقارير وإمكانية تصدير البيانات إلى CSV.
- تسجيل العمليات في سجل التدقيق.
- دعم النسخ الاحتياطي واستعادة البيانات.
- واجهة عربية متجاوبة تدعم RTL.

## 🛠️ التقنيات المستخدمة

| التقنية | الاستخدام |
|---|---|
| PHP 8.1+ | منطق النظام ومعالجة العمليات |
| MySQL / MariaDB | تخزين البيانات وإدارتها |
| PDO | الاتصال بقاعدة البيانات وتنفيذ الاستعلامات |
| HTML5 | بناء صفحات النظام |
| CSS3 | تنسيق الواجهة والتصميم |
| JavaScript | التفاعلات والوظائف في الواجهة |
| Bootstrap 5 RTL | تصميم الواجهات العربية المتجاوبة |
| WAMP | بيئة التشغيل المحلية على Windows |

## 📋 متطلبات التشغيل

- نظام تشغيل Windows.
- WAMP Server.
- PHP 8.1 أو إصدار أحدث.
- MySQL أو MariaDB متوافق مع مخطط قاعدة البيانات.
- تفعيل إضافات PHP المطلوبة، ومنها `pdo_mysql` و`mbstring` و`fileinfo`.
- متصفح حديث.

## 🚀 طريقة التثبيت

### 1. تنزيل المشروع

نزّل المشروع من GitHub أو استنسخ المستودع:

```bash
git clone https://github.com/awabwdbashry-sketch/education_center.git
```

ضع مجلد المشروع داخل مجلد `www` الخاص بـ WAMP.

مثال:

```text
C:\wamp64\www\education_center
```

### 2. تشغيل WAMP

شغّل WAMP وتأكد من عمل خادم الويب وقاعدة البيانات.

### 3. إعداد قاعدة البيانات

افتح المشروع عبر المتصفح:

```text
http://localhost/education_center/
```

اتبع خطوات معالج التثبيت لإعداد اتصال قاعدة البيانات وإنشاء قاعدة البيانات والجداول وإنشاء حساب المدير الأول.

إذا أردت التثبيت اليدوي، يمكنك استخدام ملف مخطط قاعدة البيانات:

```text
database/schema.sql
```

ثم إعداد الاتصال بقاعدة البيانات وفق ملف الإعداد النموذجي الموجود في المشروع.

### 4. تسجيل الدخول

بعد إكمال التثبيت وإنشاء حساب المدير، افتح صفحة تسجيل الدخول:

```text
http://localhost/education_center/login.php
```

استخدم بيانات الدخول التي أنشأتها أثناء التثبيت.

> **ملاحظة:** تختلف إعدادات اتصال MySQL من جهاز لآخر. راجع إعدادات WAMP لديك، ولا تستخدم بيانات اتصال افتراضية في بيئة الإنتاج.

## 🔐 الأمان

يراعي المشروع عدداً من ممارسات الأمان، منها:

- استخدام `password_hash()` و`password_verify()` لكلمات المرور.
- استخدام استعلامات PDO المجهزة للحد من مخاطر SQL Injection.
- حماية طلبات POST باستخدام CSRF tokens.
- ترميز المخرجات للحد من مخاطر XSS.
- التحقق من الصلاحيات على مستوى الخادم.
- التحقق من الملفات المرفوعة وامتداداتها ومحتواها.
- تقييد الوصول المباشر إلى الملفات والمجلدات الداخلية.
- استثناء ملفات الإعدادات والسجلات والنسخ الاحتياطية من Git.

**تنبيه:** قبل نشر النظام على خادم عام، راجع إعدادات الأمان وفعّل HTTPS واستخدم بيانات اتصال قوية ومناسبة لبيئة الإنتاج.

## 🧪 الاختبارات

يحتوي المشروع على مجلد `tests` للاختبارات وأدوات التشخيص.

يمكن تشغيل الاختبارات من سطر الأوامر وفق إعدادات قاعدة البيانات المحلية:

```bash
php tests/run_tests.php
```

وتوجد أداة لتشخيص مسار المدفوعات:

```bash
php tests/diagnose_payment.php
```

> شغّل أدوات التشخيص بعد مراجعة تأثيرها على قاعدة البيانات وإعداداتها، ولا تستخدم بيانات إنتاج حقيقية في الاختبارات.

## 📁 هيكل المشروع

```text
education_center/
│
├── assets/          # ملفات الواجهة والتبعيات
├── config/          # إعدادات النظام
├── cron/            # المهام المجدولة
├── database/        # مخطط قاعدة البيانات
├── includes/        # الملفات المشتركة والدوال
├── modules/         # وحدات النظام
├── setup/           # معالج التثبيت
├── storage/         # السجلات والنسخ الاحتياطية
├── tests/           # الاختبارات وأدوات التشخيص
├── uploads/         # الملفات المرفوعة
│
├── index.php        # نقطة الدخول
├── login.php        # تسجيل الدخول
├── logout.php       # تسجيل الخروج
├── file.php         # الوصول المنظم إلى الملفات
├── .htaccess        # قواعد الوصول والحماية
├── .gitignore       # استثناء الملفات الحساسة
└── README.md        # توثيق المشروع
```

## 👨‍💻 المطوّر

**Awab Bashery | أواب باشري**

تطوير وتصميم أنظمة ويب باستخدام PHP وMySQL، مع التركيز على بناء حلول عملية تساعد على تنظيم العمليات الإدارية والتعليمية والمالية.

### AwabBuilds 🚀

**مش مجرد كود… حلول عملية لمشاكل حقيقية.**

---

# English

## Education Center Management System

An Arabic-first educational center management system designed to organize student records, teachers, groups, subjects, academic stages, classes, attendance, assessments, and financial operations in one place.

### Key Features

- **Student Management:** Student profiles, records, and student cards.
- **Teacher Management:** Teacher information and financial entitlements.
- **Academic Organization:** Groups, subjects, academic stages, classes, and schedules.
- **Assessments:** Manage student assessments and track results.
- **Barcode Attendance:** Support barcode-based student attendance and manual attendance records.
- **Financial Management:** Student fees, payments, receipts, expenses, and teacher payments.
- **Dashboard:** Centralized access to essential management information.
- **User Permissions:** Role-based access control.
- **Reports:** Data export in CSV format.
- **Backup and Audit Logs:** Tools for data management and operational tracking.
- **Arabic RTL Interface:** A right-to-left interface designed for Arabic-speaking users.

### Technology Stack

- PHP 8.1+
- MySQL / MariaDB
- PDO
- HTML5, CSS3, and JavaScript
- Bootstrap 5 RTL
- WAMP Server

### Local Installation

1. Clone or download the repository.
2. Place the project inside the WAMP `www` directory.
3. Start Apache and MySQL.
4. Open `http://localhost/education_center/`.
5. Follow the setup wizard to configure the database and create the first administrator.
6. Sign in through `login.php`.

Refer to the Arabic documentation above for additional installation, security, and testing details.

### Developer

**Awab Bashery — AwabBuilds**

Building practical web applications that help organizations manage their operations more efficiently.

---

<div align="center">

**Made with 💻 by AwabBuilds**

</div>