-- F2 fixture (numeric-order) — second numerically, second lexicographically
-- only if 1_a already ran; under lexicographic ordering 10_c would run first
-- and die on the missing table.
INSERT INTO f2_order_log (tag) VALUES ('b');
