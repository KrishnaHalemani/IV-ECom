-- BattleRock migration: order emails + saved addresses

CREATE TABLE IF NOT EXISTS user_saved_addresses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  full_name VARCHAR(160) NOT NULL DEFAULT '',
  phone VARCHAR(50) NOT NULL DEFAULT '',
  address_line1 VARCHAR(255) NOT NULL DEFAULT '',
  address_line2 VARCHAR(255) NOT NULL DEFAULT '',
  city VARCHAR(120) NOT NULL DEFAULT '',
  state VARCHAR(120) NOT NULL DEFAULT '',
  postal_code VARCHAR(30) NOT NULL DEFAULT '',
  country VARCHAR(120) NOT NULL DEFAULT '',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_saved_address_user (user_id)
) ENGINE=InnoDB;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS payment_method VARCHAR(30) NOT NULL DEFAULT 'cod' AFTER status;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_name VARCHAR(160) DEFAULT '' AFTER customer_email;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_phone VARCHAR(50) DEFAULT '' AFTER shipping_name;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_address_line1 VARCHAR(255) DEFAULT '' AFTER shipping_phone;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_address_line2 VARCHAR(255) DEFAULT '' AFTER shipping_address_line1;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_city VARCHAR(120) DEFAULT '' AFTER shipping_address_line2;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_state VARCHAR(120) DEFAULT '' AFTER shipping_city;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_country VARCHAR(120) DEFAULT '' AFTER shipping_state;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipping_postal_code VARCHAR(30) DEFAULT '' AFTER shipping_country;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS estimated_delivery_date DATE NULL AFTER shipping_postal_code;

CREATE TABLE IF NOT EXISTS order_notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  notification_type VARCHAR(40) NOT NULL,
  status_key VARCHAR(40) NOT NULL DEFAULT '',
  sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_order_notification (order_id, notification_type, status_key)
) ENGINE=InnoDB;

