-- F2 fixture (delimiter, 002) — an ORDINARY statement first, then a
-- DELIMITER-guarded stored-program block. Without explicit DELIMITER
-- detection, a naive `;`-splitter would slice the routine body on its own
-- internal semicolons and either apply f2_delim_should_never_exist and THEN
-- fail on the mangled routine fragments (partial application), or worse,
-- silently execute nonsense. The runner must instead reject the WHOLE FILE
-- during parsing, before f2_delim_should_never_exist is ever created.
CREATE TABLE f2_delim_should_never_exist (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;

DELIMITER //
CREATE PROCEDURE f2_delim_proc()
BEGIN
  SELECT 1;
END//
DELIMITER ;
