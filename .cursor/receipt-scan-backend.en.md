# Receipt scanning — backend behavior (for frontend integration)

## Overview

Receipt scanning is **not** a separate OCR service. The backend uploads the photo to **Supabase Storage**, sends the image as **base64** to **OpenAI `gpt-4o-mini`** in **JSON mode** with a **system prompt** that includes allowed categories, then **creates an expense transaction** with line items. The user **saves edits and confirms** in one step (`PUT .../update`) before the transaction appears in the main transaction list and summaries.

---

## API surface

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/api/transactions/scan` | Upload image, run AI, create draft transaction |
| `PUT` | `/api/transactions/{transaction}/update` | Save edited receipt (`date`, `items[]`) and confirm (`confirmed_at`) in one request |
| `GET` | `/api/transactions/{transaction}/image` | Signed URL JSON for receipt preview |

Both routes sit behind **`supabase.auth`** (same as the rest of the authenticated API).

### Scan request

- **Content-Type:** `multipart/form-data`
- **Field name:** `image` (required)
- **Validation** (`ScanRequest`): `jpeg`, `jpg`, `png`, `webp`, **max 5 MB** (5120 KB)

---

## End-to-end flow (`ScanController::scan`)

1. **Subscription and quota (before upload / AI)**  
   `FeatureAccessService::canUseAiScan($user)` checks the **`ai_scan`** feature (`config/feature_access.php`):
   - User must have an **active subscription** with a loaded plan (`active` or `trialing` — see `AppUser::activeSubscription()`).
   - If there is no subscription → **403** with `"Subscription required to scan."`
   - If `used_checks_count >= plan.check_limit` → **403** with `"Scan limit reached for this billing period."`

2. **Encode and upload**  
   The file is **base64-encoded** for OpenAI; the **original bytes** are uploaded to Supabase Storage via `SupabaseStorageService::upload`. Path shape: `{userId}/{uuid}.{ext}` in the configured bucket (default `receipts` from env).

3. **Category list for the model**  
   Categories loaded for the prompt:
   - Only rows with **`category_id` not null** (subcategories / leaf categories in your schema).
   - **`with('parent')`** so the prompt can group by parent label.
   - IDs in the user’s **disabled** category settings (`app_user_category_settings`) are **excluded** — same idea as hiding categories elsewhere.

4. **OpenAI call** (`ScanService::scan`)  
   - Model: **`gpt-4o-mini`**
   - **`response_format`: JSON object**
   - **`max_tokens`: 2048**
   - **User message** contains one `image_url` with `data:{mime};base64,...` plus text: *"Analyze this image and return a structured JSON response."*
   - **System message** is built by `ScanPromptBuilder` and embeds the **JSON of categories grouped by parent label** (`id` + `label` per subcategory).

5. **AI contract (what the model is asked to return)**  
   From `ScanPromptBuilder`:
   - If the image is **not** a financial document → `{"is_receipt": false, "message": "reason"}`
   - If it **is** → `{"is_receipt": true, "date": "YYYY-MM-DD", "total_amount": <int minor units>, "items": [...]}`
   - Each item: `product`, `amount` (minor units), `category_id`, `category_label`
   - Rules: amounts in **smallest currency unit**; **sum of item amounts = total_amount**; if date unreadable use today; if line items unclear, single item with total; **JSON only**.

6. **Backend handling of the AI response**

   | Condition | HTTP | Behavior |
   |-----------|------|----------|
   | OpenAI throws / invalid JSON | **503** | Message: AI processing failed; image may be **kept** for ops; **no** `img_path` in body |
   | `is_receipt` falsy / empty | **422** | Image **deleted** from storage; message from AI or default “not a valid financial document” |
   | Receipt but missing `total_amount`, `date`, or `items` (empty fails `empty()` in PHP) | **422** | Image **deleted**; **no** `img_path`; message about incomplete data |

7. **Successful parse → DB (single transaction)**  
   - Creates **`Transaction`**: `type` = **expense** (hardcoded), `created_by` = **`ai`**, **`confirmed_at` = `null`**, `img_path` set, **`raw_ai_data`** = full AI JSON array, `total_amount` and `date` from AI.
   - For each item: **`TransactionItem`** with `amount`, `label` from `product`, `category_id` **validated** against the same category list; if `category_id` is missing or not in the list → **`finance_other_misc`** subcategory id as fallback (if that category exists).
   - **`used_checks_count`** on the active subscription is **incremented** once per successful scan.
   - Response: **201** with **`TransactionResource`** (`{ "data": { ... } }`), **`items.category`** loaded.

### Receipt update (`ScanController::updateReceipt`) — `PUT /api/transactions/{transaction}/update`

- Same authorization as before: **`created_by` = `ai`**, **`confirmed_at` null**, owner only (else **404**).
- Body: **`date`**, **`items[]`** (optional **`id`** per line; full sync semantics; server sums **`total_amount`**).
- In **one DB transaction**: apply line items, then **`confirmed_at = now()`**.
- Returns **`TransactionResource`** with **`items.category`** loaded.

---

## How this affects lists, summaries, and “draft” behavior

- **`GET /api/transactions`** (index) applies **`whereNotNull('confirmed_at')`**.  
  So **unconfirmed AI scans do not appear in the paginated history** until confirmed.

- **Summaries / export** (from your codebase) also filter **`confirmed_at` not null**, so unconfirmed AI transactions **do not** affect aggregates or CSV until confirmed.

- **`GET /api/transactions/{id}`** (`show`) does **not** require `confirmed_at`, so the client can still **fetch a specific draft** by id (e.g. right after scan returns 201) if you store that id.

---

## Response shapes the frontend should handle

**201 (success)** — `{ "data": { ... } }` via `TransactionResource`, including:

- `created_by`: `"ai"`
- `type`: `"expense"`
- `confirmed_at`: `null` until `PUT .../update`
- `raw_ai_data`: full parsed AI payload
- `img_path`: storage object path (string)
- `items[]` with nested `category` where loaded

**403** — `{ "message": "..." }` (subscription or quota)

**503** — AI failure; no `img_path` in body (image may remain server-side for ops)

**422** — Validation / not a receipt / incomplete AI; no `img_path` when incomplete (image deleted)

---

## Scan usage for the UI (`GET /api/me`)

`scan_usage` is included on **`GET /api/me`**:

- `scans_remaining`, `scans_limit`, `period_end` (ISO 8601 from `current_period_end`, or zeros / null if no subscription)

Use this to show remaining scans and to align with 403 messages.

---

## Receipt images and the frontend

- Storage is **private** (service role). **`GET /api/transactions/{transaction}/image`** returns `{ "url", "expires_in" }` (signed URL from `SupabaseStorageService::createSignedUrl`) for preview. Scan/update responses still include **`img_path`** as the opaque storage key.

---

## Key implementation files

- `app/Http/Controllers/ScanController.php` — `scan()`, `updateReceipt()`, `image()`
- `app/Services/ReceiptTransactionService.php` — `saveAndConfirm()` for `PUT .../update`
- `app/Services/ScanService.php` — OpenAI API call
- `app/Services/Scan/ScanPromptBuilder.php` — prompt construction and category grouping
- `app/Http/Requests/ScanRequest.php` — image validation
- `app/Services/FeatureAccessService.php` — `canUseAiScan()`
- `config/feature_access.php` — `ai_scan` messages and plan allowlist

---

## Code references

`ScanController::scan` — feature access, upload, AI, transaction creation, quota increment:

```27:116:app/Http/Controllers/ScanController.php
    public function scan(ScanRequest $request): JsonResponse
    {
        $file = $request->file('image');
        $user = $request->user();

        $result = $this->featureAccess->canUseAiScan($user);
        if (!$result->allowed) {
            return response()->json(['message' => $result->message], 403);
        }
        $subscription = $result->subscription;

        $base64 = base64_encode($file->getContent());
        $mimeType = $file->getMimeType();

        $imgPath = $this->storage->upload($file, $user->id);

        $disabledCategoryIds = $user->disabledCategorySettings()->pluck('category_id')->all();
        $categories = Category::whereNotNull('category_id')
            ->with('parent')
            ->when(!empty($disabledCategoryIds), fn ($q) => $q->whereNotIn('id', $disabledCategoryIds))
            ->get();

        try {
            $aiResult = $this->scanner->scan($base64, $mimeType, $categories);
        } catch (\Throwable $e) {
            Log::error('AI scan failed', [
                'user_id' => $user->id,
                'img_path' => $imgPath,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'AI processing failed. The image has been saved and you can retry later.',
                'img_path' => $imgPath,
            ], 503);
        }

        if (empty($aiResult['is_receipt'])) {
            $this->storage->delete($imgPath);

            return response()->json([
                'message' => $aiResult['message'] ?? 'The image is not a valid financial document.',
            ], 422);
        }

        if (empty($aiResult['total_amount']) || empty($aiResult['date']) || empty($aiResult['items'])) {
            return response()->json([
                'message' => 'AI returned incomplete data. Please try again.',
                'img_path' => $imgPath,
            ], 422);
        }

        $validCategoryIds = $categories->pluck('id')->toArray();
        $fallbackCategoryId = $categories->firstWhere('name', 'finance_other_misc')?->id;

        $transaction = DB::transaction(function () use ($aiResult, $user, $imgPath, $validCategoryIds, $fallbackCategoryId, $subscription) {
            $transaction = Transaction::create([
                'app_user_id' => $user->id,
                'total_amount' => (int) $aiResult['total_amount'],
                'date' => $aiResult['date'],
                'type' => TransactionType::Expense,
                'created_by' => TransactionCreatedBy::Ai,
                'confirmed_at' => null,
                'img_path' => $imgPath,
                'raw_ai_data' => $aiResult,
            ]);

            // TODO: Может явно выдавать ошибку, если нет категории, а не фаллбек делать
            foreach ($aiResult['items'] as $item) {
                $categoryId = in_array((int) ($item['category_id'] ?? 0), $validCategoryIds)
                    ? (int) $item['category_id']
                    : $fallbackCategoryId;

                TransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'category_id' => $categoryId,
                    'amount' => (int) ($item['amount'] ?? 0),
                    'label' => $item['product'] ?? null,
                ]);
            }

            $subscription->increment('used_checks_count');

            return $transaction;
        });

        $transaction->load('items.category');

        return response()->json($transaction, 201);
    }
```

`ScanPromptBuilder` — AI JSON contract:

```16:34:app/Services/Scan/ScanPromptBuilder.php
        return <<<PROMPT
Analyze the image. Is it a financial document (receipt, invoice, bill, payment confirmation)?

If NOT: return {"is_receipt": false, "message": "reason"}

If YES: extract date, total, and line items. Match each item to the best category below. If no specific subcategory fits, use the "Other" subcategory of the most relevant group. Use ONLY category_id values from this list:

{$categoriesJson}

Return strict JSON:
{"is_receipt": true, "date": "YYYY-MM-DD", "total_amount": <int cents>, "items": [{"product": "<name>", "amount": <int cents>, "category_id": <int>, "category_label": "<label>"}]}

Rules:
- Amounts as integers in smallest currency unit (e.g. 150.00 = 15000)
- Sum of item amounts must equal total_amount
- If date unreadable, use today
- If items indistinguishable, single item with total
- JSON only, no extra text
PROMPT;
```

`ScanRequest` — image rules:

```14:18:app/Http/Requests/ScanRequest.php
        return [
            'image' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ];
```

`ManualTransactionController::index` — history only includes confirmed transactions:

```22:28:app/Http/Controllers/ManualTransactionController.php
        $query = $request->user()->transactions()
            ->whereNotNull('confirmed_at')
            ->with('items.category')
            ->orderByDesc('date')
            ->orderByDesc('created_at');
```
