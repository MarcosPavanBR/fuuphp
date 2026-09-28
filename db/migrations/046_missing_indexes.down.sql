-- 046_missing_indexes.down.sql
BEGIN;
DROP INDEX ledger_origin_idx, menu_items_store_idx, payments_order_idx, dispatch_attempts_order_idx,
  card_transactions_order_idx, orders_address_idx, orders_courier_idx, orders_restaurant_status_idx,
  otp_codes_rate_idx, disputes_order_idx, tickets_user_idx, delivery_proofs_sha256_idx,
  partner_accounts_user_idx, pos_custody_device_idx, cash_intents_store_code_idx;
COMMIT;
