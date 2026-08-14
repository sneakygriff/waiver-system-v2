-- F2 fixture (string-containing-semicolon) — the payloads below carry `;`,
-- `--`, `/* */` and quote characters INSIDE string literals. A naive
-- explode(';') splitter cuts them mid-literal and the run dies with a syntax
-- error; a quote-aware splitter stores them byte-exact. Both a single-quoted
-- literal (with a doubled '' escape) and a double-quoted literal (with a
-- backslash escape) are covered.
CREATE TABLE f2_semi (id INT NOT NULL PRIMARY KEY, payload VARCHAR(255) NOT NULL) ENGINE=InnoDB;
INSERT INTO f2_semi (id, payload) VALUES (1, 'semi; colon -- not a comment /* nor block */ and a '' quote');
INSERT INTO f2_semi (id, payload) VALUES (2, "double \" quoted; one statement");
