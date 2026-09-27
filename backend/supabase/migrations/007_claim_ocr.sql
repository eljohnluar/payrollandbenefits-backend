-- 007_claim_ocr.sql — Google Cloud Vision receipt verification for claims
-- OCR results live on the claim row so HR reviewers and the audit trail see
-- exactly what was extracted and why a claim was flagged.

ALTER TABLE public.claims ADD COLUMN IF NOT EXISTS ocr_status TEXT;
  -- 'No receipt' | 'Skipped' (Vision not configured) | 'Passed' | 'Flagged' | 'Error'
ALTER TABLE public.claims ADD COLUMN IF NOT EXISTS ocr_merchant TEXT;
ALTER TABLE public.claims ADD COLUMN IF NOT EXISTS ocr_receipt_date DATE;
ALTER TABLE public.claims ADD COLUMN IF NOT EXISTS ocr_amount NUMERIC(12,2);
ALTER TABLE public.claims ADD COLUMN IF NOT EXISTS ocr_or_number TEXT;
ALTER TABLE public.claims ADD COLUMN IF NOT EXISTS ocr_tin TEXT;
ALTER TABLE public.claims ADD COLUMN IF NOT EXISTS ocr_confidence NUMERIC(5,2);
ALTER TABLE public.claims ADD COLUMN IF NOT EXISTS ocr_flags TEXT;
ALTER TABLE public.claims ADD COLUMN IF NOT EXISTS ocr_checked_at TIMESTAMPTZ;
-- Which OCR engine ran ('tabscanner' | 'google-vision') and its request token.
ALTER TABLE public.claims ADD COLUMN IF NOT EXISTS ocr_provider TEXT;
ALTER TABLE public.claims ADD COLUMN IF NOT EXISTS ocr_token TEXT;
