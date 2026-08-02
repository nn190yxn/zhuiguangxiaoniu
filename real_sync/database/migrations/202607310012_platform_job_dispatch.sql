-- Add payload integrity to the shared platform job identity.

ALTER TABLE platform_jobs
    ADD COLUMN payload_hash CHAR(64) NULL AFTER payload_json;

UPDATE platform_jobs
SET payload_hash = SHA2(payload_json, 256)
WHERE payload_hash IS NULL;
