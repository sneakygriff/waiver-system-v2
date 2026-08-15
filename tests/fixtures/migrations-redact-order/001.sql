-- F2 fixture (redact-order) — one executable statement whose own SQL text
-- embeds a full STAGING_WAIVER_DB_URL-shaped connection string. --verbose's
-- statement-preview echo (out(), which is redact()-filtered) is a REAL log
-- line a migration author could produce by accident (e.g. a seed row whose
-- data happens to look like a URL), so this is a live rehearsal of the
-- runner connecting with exactly this URL. The user ("ux") and database
-- ("rd") in it are 2 characters -- below redact()'s 3-char word-boundary
-- floor -- so the ONLY thing that can mask them is the wholesale `url` entry
-- matched against the exact, unmutated raw connection string (see run.php
-- connect()'s "ORDER MATTERS" comment / P2-5).
--
-- __HOST__/__PORT__ are placeholders the test substitutes with the real
-- MySQL host/port (`config/config.test.php`'s WAIVER_TEST_DB_HOST/_PORT,
-- "db"/3306 by default) so the embedded URL is always the one this test
-- actually connects with.
CREATE TABLE f2_redact_order (id INT NOT NULL PRIMARY KEY, note VARCHAR(255) NOT NULL) ENGINE=InnoDB;
INSERT INTO f2_redact_order (id, note) VALUES (1, 'url-in-sql: mysql://ux:f2-redact-order-secret-pw@__HOST__:__PORT__/rd');
