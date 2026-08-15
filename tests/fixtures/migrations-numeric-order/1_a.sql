-- F2 fixture (numeric-order) — UNPADDED numbers, so lexicographic order
-- (10_c, 1_a, 2_b) differs from numeric order (1_a, 2_b, 10_c). Zero-padded
-- fixtures cannot tell the two apart, which is exactly how an ordering
-- assertion becomes a green lie.
CREATE TABLE f2_order_log (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, tag VARCHAR(8) NOT NULL) ENGINE=InnoDB;
INSERT INTO f2_order_log (tag) VALUES ('a');
