-- STEP 1: Add ferries to the modules.type ENUM
ALTER TABLE modules MODIFY COLUMN type ENUM(
  'flights','stays','tours','cars','bus','rail','cruises',
  'visa','insurance','umrah','hajj','events','esim','ferries'
);

-- STEP 2: Insert Kikoto ferries supplier row
-- c1 = Bearer token, c2 = base URL, dev_mode=1 (test env)
INSERT INTO modules
  (id, name, active, type, status,
   c1, c2, c3, c4, c5, c6,
   dev_mode, payment_mode, currency, module_color, `order`, prn_type,
   markup_type_b2c, markup_b2c, icon,
   markup_type_b2b, markup_b2b,
   check_balance, content_import, import_database, logging_enabled,
   host, `database`, username, password, tax, tax_type,
   ancillaries_enabled, emd_enabled)
VALUES
  (41, 'kikoto', '1', 'ferries', '1',
   'zrOPZWOlp_ysifmbagp6jwD12gL10wal',
   'https://test.api.b2b.kikoto.com/v1',
   '', '', '', '',
   '1', '1', 'EUR', '#06b6d4', 12, '0',
   'percentage', 0, 'directions_boat',
   'percentage', '0',
   '1', '0', 0, 1,
   NULL, NULL, NULL, NULL, NULL, 'percentage',
   0, 0);
