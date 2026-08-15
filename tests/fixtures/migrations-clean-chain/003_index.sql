-- F2 fixture (clean-chain 003) — one executable statement.
-- Depends only on 001, never on 002, so it still applies cleanly in the
-- pre-existing-ledger-row case where 002 is skipped entirely.
CREATE INDEX f2_clean_a_note_idx ON f2_clean_a (note);
