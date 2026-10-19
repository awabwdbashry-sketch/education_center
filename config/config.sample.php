<?php
// نموذج ملف الإعدادات — يُنشئ setup/index.php ملف config.php تلقائياً.
// لا ترفع config.php إلى أي مستودع (مستثنى في .gitignore).
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'education_center_management',
        'user'    => 'root',
        'pass'    => '',          // WAMP الافتراضي: بدون كلمة مرور لـ root. غيّرها في بيئة الإنتاج.
        'charset' => 'utf8mb4',
    ],
    'timezone' => 'Africa/Khartoum',
    'debug'    => false,          // لا تفعّله في الإنتاج
];
