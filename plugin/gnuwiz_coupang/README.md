# gnuwiz Coupang Plugin

영카트와 쿠팡 판매자 API 연동을 위한 플러그인입니다.

## 설치
1. `/plugin/gnuwiz_coupang/setup.php` 실행하여 필요한 테이블과 디렉터리를 생성합니다.
2. `lib/coupang_config.php`에 API 키와 Vendor ID를 설정합니다.

## 크론 작업
모든 동기화 작업은 `cron/main_cron.php`를 통해 실행합니다.
```
php main_cron.php orders
php main_cron.php cancelled_orders
php main_cron.php order_status
php main_cron.php products
php main_cron.php product_status
php main_cron.php stock
```

## 디렉터리 구조
```
plugin/gnuwiz_coupang/
├── _common.php
├── setup.php
├── uninstall.php
├── lib/
│   ├── coupang_config.php
│   └── coupang_api_class.php
├── cron/
│   ├── main_cron.php
│   └── *.php (orders, products, 등)
├── admin/
│   ├── api_test.php
│   ├── manual_sync.php
│   └── settings.php
├── assets/
│   ├── css/
│   └── js/
├── logs/
├── sql/
│   ├── install.sql
│   └── uninstall.sql
└── version.txt
```

## 로그
동기화 로그는 `logs/` 디렉터리에 저장됩니다.
- `orders.log`
- `cancelled.log`
- `status.log`
- `products.log`
- `product_status.log`
- `stock.log`
- `general.log`
