# رابط HTTP برای Bale

این پروژه یک API مبتنی بر Laravel برای استفاده از قابلیت‌های کتابخانهٔ PHP بله است. حساب‌های بله، نشست‌ها، فایل‌ها و webhookها در دیتابیس نگهداری می‌شوند. API از توکن Bearer استفاده می‌کند و مستندات OpenAPI و پنل مدیریت Filament دارد.

> این نسخه برای اجرای محلی طراحی شده است. پیش از انتشار عمومی، تنظیمات محیط، دامنه‌ها، محدودیت‌های شبکه، پشتیبان‌گیری و سیاست نگهداری داده را متناسب با زیرساخت خود بازبینی کنید.

## قابلیت‌ها

- ثبت‌نام و ورود کاربر و ساخت توکن API
- اتصال حساب بله با شماره تلفن، کد ورود و در صورت نیاز رمز دومرحله‌ای
- نگهداری رمزگذاری‌شدهٔ نشست بله و جداسازی داده‌های کاربران
- فهرست و اجرای عملیات موجود در `aiobalephp` با اعتبارسنجی schema
- آپلود خصوصی و دانلود کنترل‌شدهٔ رسانه‌ها
- webhook امضاشده، صف ارسال، retry و ثبت نتیجه
- پنل مدیریت Filament و مستندات OpenAPI

## نیازمندی‌ها

- PHP 8.3 یا بالاتر به‌همراه افزونه‌های `curl`, `fileinfo`, `mbstring`, `openssl`, `pdo_mysql` و `sodium`
- Composer
- MySQL 8 یا نسخهٔ سازگار
- پوشهٔ کتابخانهٔ `aiobalephp` در کنار این پروژه؛ مقدار پیش‌فرض Composer به `../aiobalephp` اشاره می‌کند

## نصب و راه‌اندازی

از پوشهٔ پروژه اجرا کنید:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

در MySQL یک دیتابیس بسازید:

```bash
mysql -u root -p -e 'CREATE DATABASE aiobalephpapi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
```

مقادیر اتصال دیتابیس را در `.env` تنظیم کنید:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=aiobalephpapi
DB_USERNAME=root
DB_PASSWORD=your-database-password
```

سپس جداول را بسازید:

```bash
php artisan migrate
```

`APP_KEY` برای رمزگذاری نشست‌های بله، رمز webhook و challenge ورود استفاده می‌شود. آن را ثابت و محرمانه نگه دارید؛ تغییر آن اطلاعات رمزگذاری‌شدهٔ قبلی را غیرقابل‌خواندن می‌کند. فایل `.env` را در مخزن کد قرار ندهید.

## اجرای محلی

سرور HTTP:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

در ترمینالی دیگر، worker صف را اجرا کنید تا webhookها تحویل داده شوند:

```bash
php artisan queue:work --tries=5 --timeout=30
```

برای دریافت رخدادهای زندهٔ یک حساب بله و قراردادن آن‌ها در صف webhook:

```bash
php artisan bale:watch-events 1
```

عدد `1` شناسهٔ حساب بله در دیتابیس است. این فرایند باید فعال بماند؛ برای هر حسابی که باید رخدادهای زنده‌اش دریافت شود، یک watcher اجرا کنید. برای توقف watcher از `Ctrl+C` استفاده کنید.

## مستندات API و پنل مدیریت

- مستندات تعاملی: `http://127.0.0.1:8000/docs/api`
- سند OpenAPI: `http://127.0.0.1:8000/docs/api.json`
- پنل مدیریت: `http://127.0.0.1:8000/admin`

مستندات و پنل را در محیط محلی باز کنید. دسترسی ادمین به‌صورت پیش‌فرض برای همه غیرفعال است و هیچ حساب یا رمز پیش‌فرضی ساخته نمی‌شود.

## احراز هویت API

ثبت‌نام، توکن Bearer برمی‌گرداند:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/register \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "name": "API User",
    "email": "user@example.com",
    "password": "replace-this-with-a-long-password",
    "password_confirmation": "replace-this-with-a-long-password"
  }'
```

برای ورود از `POST /api/v1/auth/login` با فیلدهای `email` و `password` استفاده کنید. در درخواست‌های محافظت‌شده توکن را بفرستید:

```bash
TOKEN='paste-the-returned-token-here'
curl http://127.0.0.1:8000/api/v1/auth/me \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json'
```

خروج از API با `POST /api/v1/auth/logout` توکن جاری را باطل می‌کند. برای درخواست‌های محافظت‌شده، هدر `Accept: application/json` را بفرستید تا خطاها در قالب JSON برگردند.

## اتصال حساب بله

۱. درخواست ارسال کد ورود:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/bale/auth/start \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"phone":"+989123456789","device_title":"Local API"}'
```

شناسهٔ `challenge_id` را از پاسخ بردارید. سپس کد دریافت‌شده را تأیید کنید:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/bale/auth/challenges/CHALLENGE_ID/verify-code \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"code":"12345","label":"حساب شخصی"}'
```

اگر حساب بله رمز دومرحله‌ای داشته باشد، این درخواست با وضعیت `409` و کد `two_step_password_required` پاسخ می‌دهد. در آن صورت:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/bale/auth/challenges/CHALLENGE_ID/verify-password \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"password":"your-two-step-password","label":"حساب شخصی"}'
```

challengeها پس از مدت محدود منقضی می‌شوند و تعداد تلاش‌های ورود نیز محدود است. نشست‌های ذخیره‌شده را از `GET /api/v1/bale/accounts` ببینید. عملیات خروج از حساب بله:

```text
POST /api/v1/bale/accounts/{account_id}/sign-out
```

## فهرست و اجرای عملیات بله

فهرست عملیات و schema ورودی آن‌ها:

```bash
curl http://127.0.0.1:8000/api/v1/bale/methods \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json'
```

هر عملیات ثبت‌شده در `MethodRegistry` از مسیر زیر قابل اجراست؛ عملیات چرخهٔ ورود از این مسیر عمومی مستثنا هستند:

```text
POST /api/v1/bale/accounts/{account_id}/methods/{method_name}
```

بدنه باید ورودی را در `payload` قرار دهد. نمونهٔ ساختاری برای `SendMessage`:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/bale/accounts/1/methods/SendMessage \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "payload": {
      "peer": {"type": 1, "id": 123456},
      "message_id": 0,
      "content": {"text": {"value": "سلام"}},
      "chat": {"type": 1, "id": 123456}
    }
  }'
```

schema دقیق هر عملیات و فیلدهای لازم را از endpoint فهرست عملیات دریافت کنید. پاسخ‌های کتابخانه به JSON تبدیل می‌شوند؛ مقدارهای باینری به‌صورت Base64 همراه با مشخص‌کردن encoding برگردانده می‌شوند.

## رسانه‌ها

فایل‌ها روی دیسک خصوصی Laravel ذخیره می‌شوند و برای دانلود باید از همان کاربر API احراز هویت شود. نمونهٔ آپلود:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/media \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json' \
  -F 'file=@./photo.jpg'
```

مسیرهای رسانه:

- `GET /api/v1/media` — فهرست فایل‌های کاربر جاری
- `POST /api/v1/media` — آپلود فایل
- `GET /api/v1/media/{media_id}/download` — دانلود فایل متعلق به کاربر جاری
- `DELETE /api/v1/media/{media_id}` — حذف فایل

حد پیش‌فرض آپلود محلی ۵۰ مگابایت است و با `BALE_MAX_UPLOAD_KB` تغییر می‌کند.

برای آپلود مستقیم به فضای Bale، حساب بله را در مسیر انتخاب و فایل را multipart بفرستید:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/bale/accounts/1/files \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json' \
  -F 'file=@./document.pdf'
```

`POST /api/v1/bale/accounts/{account}/files` فقط فایل را در Bale ذخیره می‌کند و آن را
به چت نمی‌فرستد یا در دیسک API نگه نمی‌دارد. پاسخ شامل `file_id`, `access_hash`,
`size`, `name` و `mime_type` است؛ این مقادیر را می‌توان در payload هر عملیات Bale
مطابق schema همان عملیات استفاده کرد. این مسیر هم از حد `BALE_MAX_UPLOAD_KB` استفاده می‌کند.

## Webhookها

برای ساخت webhook، حساب بله باید فعال باشد. فقط رخدادهای تعریف‌شده در `config/bale.php` پذیرفته می‌شوند. نمونه:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/webhooks \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{
    "bale_account_id": 1,
    "url": "https://hooks.example.com/bale",
    "events": ["message", "message_sent"]
  }'
```

راز webhook در پاسخ ساخت یا چرخاندن راز نمایش داده می‌شود و بعداً قابل بازیابی نیست؛ آن را امن نگه دارید. رخدادها در صف ذخیره و با worker تحویل داده می‌شوند. درخواست شامل هدرهای `X-Bale-Event`, `X-Bale-Delivery`, `X-Bale-Timestamp` و `X-Bale-Signature` است. امضا برابر HMAC-SHA256 روی متن `timestamp.body` با راز webhook است و به شکل `sha256=<hex>` ارسال می‌شود.

URL webhook باید HTTPS، بدون اطلاعات کاربری و روی پورت پیش‌فرض باشد. میزبان‌های محلی، IPهای خصوصی/رزروشده و مقصدهایی که به آن‌ها resolve می‌شوند رد می‌شوند. هنگام ارسال، DNS دوباره اعتبارسنجی و به IP عمومی بررسی‌شده pin می‌شود؛ redirect نیز دنبال نمی‌شود. نتیجه و وضعیت retry را از مسیرهای زیر ببینید:

- `GET /api/v1/webhooks`
- `PATCH /api/v1/webhooks/{webhook_id}`
- `DELETE /api/v1/webhooks/{webhook_id}`
- `GET /api/v1/webhooks/{webhook_id}/deliveries`

## مدیریت کاربران

کاربر موردنظر را ابتدا با endpoint ثبت‌نام API ایجاد کنید. سپس در ترمینال پروژه دسترسی پنل را به او بدهید:

```bash
php artisan admin:promote user@example.com
```

بعد از آن همان کاربر می‌تواند وارد `/admin` شود. برای لغو دسترسی ادمین، مقدار `is_admin` کاربر را در دیتابیس `false` کنید.

## پیکربندی‌های مهم

| متغیر | کاربرد | مقدار پیش‌فرض |
|---|---|---:|
| `BALE_AUTH_CHALLENGE_TTL_MINUTES` | عمر challenge ورود بله | `10` |
| `BALE_MAX_UPLOAD_KB` | حداکثر اندازهٔ فایل آپلودی | `51200` |
| `BALE_WEBHOOK_MAX_ATTEMPTS` | تعداد تلاش برای تحویل webhook | `5` |
| `BALE_WEBHOOK_TIMEOUT_SECONDS` | مهلت پاسخ مقصد webhook | `8` |
| `QUEUE_CONNECTION` | درایور صف | `database` |

برای اجرای production، `APP_DEBUG=false` بگذارید، از HTTPS استفاده کنید، صف را با process manager پایدار اجرا کنید و دسترسی دیتابیس و فایل `.env` را محدود نگه دارید.

## آزمون‌ها

اجرای تست‌های API:

```bash
composer test
```

اجرای تست‌های کتابخانهٔ PHP در پوشهٔ همسایه:

```bash
cd ../aiobalephp
composer test
```
