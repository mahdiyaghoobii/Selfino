
---

# Selfino Bot - سلفینو 🍽️
**A smart bot for buying and selling meal plans among Semnan University students**  
**ربات هوشمند خرید و فروش وعده‌های غذایی بین دانشجویان دانشگاه سمنان**

---

## 📜 Introduction | معرفی
This bot allows students of Semnan University to:  
این ربات به دانشجویان دانشگاه سمنان اجازه می‌دهد تا:
- 🛒 **Sell extra meal plans** | **وعده‌های غذایی اضافه خود را به فروش برسانند**
- 💰 **Buy needed meal plans** | **وعده‌های غذایی مورد نیاز خود را خریداری کنند**
- 📅 Filter requests by **day, meal, and dining location** | **درخواست‌ها را بر اساس روز، وعده و محل سلف فیلتر کنند**
- 🔄 Automatically post requests in a **dedicated channel/group** | **به صورت خودکار در کانال/گروه اختصاصی منتشر شوند**

---

## ✨ Features | ویژگی‌ها
- ▫️ **3 daily requests per user** | **محدودیت ۳ درخواست روزانه برای هر کاربر**
- 🔒 **Mandatory group/channel membership check** | **بررسی عضویت در کانال/گروه اجباری**
- 📊 **Advanced logging system** | **سیستم لاگ‌گیری پیشرفته**
- ⏳ **Automatic user state saving** | **ذخیره خودکار وضعیت کاربران**
- 🗑️ **Request deletion by the sender** | **امکان حذف درخواست توسط ارسال‌کننده**
- 👮‍♂️ **Special admin privileges** | **دسترسی ویژه برای ادمین‌ها**

---

## ⚙️ Prerequisites | پیش‌نیازها
- PHP 7.4 or higher | **PHP نسخه 7.4 یا بالاتر**
- Server access with PHP execution capability | **دسترسی به سرور با قابلیت اجرای PHP**
- A Telegram bot from [@BotFather](https://t.me/BotFather) | **یک ربات تلگرام از [@BotFather](https://t.me/BotFather)**
- Membership in:
    - Group: [@semnanm](https://t.me/semnanm) | **گروه: [@semnanm](https://t.me/semnanm)**
    - Channel: [@semnanam](https://t.me/semnanam) | **کانال: [@semnanam](https://t.me/semnanam)**

---

## 🚀 Installation & Setup | نصب و راه‌اندازی
1. Clone the repository:  
   **کلون کردن ریپوزیتوری:**
   ```bash
   git clone https://github.com/mahdiyaghoobii/Selfino.git
   cd safino-bot
   ```

2. Create required directories:  
   **ایجاد دایرکتوری‌های لازم:**
   ```bash
   mkdir logs states
   chmod 777 logs states
   ```

3. Install dependencies:  
   **نصب وابستگی‌ها:**
   ```bash
   composer require guzzlehttp/guzzle
   ```

4. Enable write permissions:  
   **فعال کردن دسترسی نوشتن:**
   ```bash
   chmod 755 index.php
   ```

---

## ⚙️ Configuration | پیکربندی
Edit the main file (`index.php`) with the following values:  
**فایل اصلی (`index.php`) را با مقادیر زیر ویرایش کنید:**
```php
// Main parameters | پارامترهای اصلی
$botToken = 'YOUR_BOT_TOKEN'; // Bot token from @BotFather | توکن ربات از @BotFather
$groupId = -1001234567890; // Group ID (with negative sign) | آیدی عددی گروه (با منفی)
$topicId = 12345; // Topic ID | آیدی تاپیک گروه
$requiredGroup = '@semnanm'; // Required group | گروه الزامی
$requiredChannel = '@semnanam'; // Required channel | کانال الزامی
```

---

## 📖 User Guide | راهنمای استفاده

### Starting the Bot | شروع کار با ربات
1. Send the `/start` command.  
   **ارسال دستور `/start`.**
2. Choose **Buy** or **Sell**.  
   **انتخاب گزینه خرید یا فروش.**
3. Select a dining hall from the list.  
   **انتخاب سلف از لیست پیشنهادی.**
4. Choose a meal type.  
   **انتخاب نوع وعده غذایی.**
5. Select the desired day.  
   **انتخاب روز مورد نظر.**

---

### Bot Menus | منوهای ربات
```
📋 Main Menu | منوی اصلی:
├─ Buy 🛒 | خرید 🛒
└─ Sell 💰 | فروش 💰

🏫 Dining Halls | لیست سلف‌ها:
├─ Engineering | مهندسی
├─ Campus | پردیس
├─ Art | هنر
└─ Dormitories... | خوابگاه‌ها...

🍽️ Meal Types | وعده‌های غذایی:
├─ Breakfast ☀️ | صبحانه ☀️
├─ Lunch 🌞 | ناهار 🌞
└─ Dinner 🌙 | شام 🌙
```

---

### Limitations | محدودیت‌ها
- **3 daily requests per user** | **هر کاربر حداکثر ۳ درخواست در روز**
- **Telegram username required** | **نیاز به یوزرنیم تلگرام برای ثبت درخواست**
- **Mandatory group/channel membership** | **الزام عضویت در کانال/گروه مشخص شده**

---

## 📝 Logging & Debugging | لاگ‌گیری و خطایابی
Logs are stored in the `logs` directory. Format:  
**لاگ‌ها در دایرکتوری `logs` ذخیره می‌شوند. فرمت:**
```log
[2024-03-01 12:30:45] UserID: 12345 - Action: START_FLOW - Details: ...
```

---

## 🔒 Security | امنیت
- **Sensitive data is not logged** | **از نمایش اطلاعات حساس در لاگ‌ها خودداری شده**
- **Permissions are checked before sensitive actions** | **بررسی مجوزها قبل از هر عمل حساس**
- **HTTPS is used for Telegram API communication** | **استفاده از HTTPS برای ارتباط با API تلگرام**

---

## 🆘 Support | پشتیبانی
For reporting issues or suggestions:  
**برای گزارش مشکل یا پیشنهاد:**
- Admin: [@amposhtiban](https://t.me/amposhtiban) | **ادمین: [@amposhtiban](https://t.me/amposhtiban)**
- Support Group: [@semnanm](https://t.me/semnanm) | **گروه پشتیبانی: [@semnanm](https://t.me/semnanm)**
- News Channel: [@semnanam](https://t.me/semnanam) | **کانال اطلاع‌رسانی: [@semnanam](https://t.me/semnanam)**

---

**Semnanam Team | تیم سمنانم**  
**Developed with ♥ for Semnan university students | توسعه داده شده با ♥ برای دانشجویان دانشگاه سمنان**

---

