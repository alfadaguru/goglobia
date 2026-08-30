<?php
/**
 * Wanderbeds Hotel API supplier
 *
 * Setup:
 * 1. Admin → Modules → Add Module (or ensure row exists from install/db.sql sync)
 *    - name: wanderbeds
 *    - type: stays
 *    - content_import: Enabled
 *    - import_database: Enabled
 * 2. Settings → Credentials: Username (c1), Password (c2), Base URL (c3 = https://api.wanderbeds.com)
 * 3. Settings → Database: configure content DB, then Import tab → sync countries/cities/hotels
 * 4. Enable module status — listing will call modules/stays/wanderbeds/search
 *
 * Live flow: Search → Offers → Availability → Book → BookInfo → Cancel
 * Cross-check vs Postman: COLLECTION-CROSSCHECK.md
 */
