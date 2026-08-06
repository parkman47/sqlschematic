-- Normalize widget status values.
UPDATE widgets SET status = LOWER(TRIM(status)) WHERE status IS NOT NULL;
