-- 008_support.down.sql
BEGIN;

DROP TABLE IF EXISTS coupon_redemptions;
DROP TABLE IF EXISTS coupons;
DROP TABLE IF EXISTS order_messages;
DROP TABLE IF EXISTS tickets;
DROP TABLE IF EXISTS delivery_incidents;
DROP TABLE IF EXISTS fraud_signals;
DROP TABLE IF EXISTS disputes;

COMMIT;
