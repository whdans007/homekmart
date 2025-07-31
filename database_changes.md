## 2025-07-26 10:00:00 (추정)

`inventory` 테이블에 `selling_price` 컬럼 추가.

```sql
ALTER TABLE `inventory` ADD `selling_price` DECIMAL(10, 2) NULL DEFAULT NULL AFTER `quantity`;
```## 2025-07-26 09:59:51

테이블 `inventory`에 `selling_price` 컬럼 추가.

```sql
ALTER TABLE `inventory` ADD `selling_price` DECIMAL(10, 2) NULL DEFAULT NULL AFTER `quantity`;
```

