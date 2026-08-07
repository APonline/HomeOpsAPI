<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class HomeOpsReceiptExtractor
{
    public function extract(string $absolutePath, string $mimeType): array
    {
        $warnings = [];
        $openAiKey = trim((string) config('services.openai.api_key'));

        if ($openAiKey !== '') {
            try {
                return $this->extractWithOpenAi($absolutePath, $mimeType, $openAiKey);
            } catch (\Throwable $exception) {
                report($exception);
                $warnings[] = 'AI extraction was unavailable, so HomeOps tried local OCR instead.';
            }
        }

        try {
            $local = $this->extractWithTesseract($absolutePath);
            $local['warnings'] = array_values(array_unique(array_merge($warnings, $local['warnings'] ?? [])));
            return $local;
        } catch (\Throwable $exception) {
            report($exception);
            $warnings[] = 'Automatic extraction is not configured on this server. Review the receipt manually before saving.';
        }

        return [
            'provider' => 'manual',
            'confidence' => 0,
            'data' => $this->emptyData(),
            'raw_text' => null,
            'warnings' => $warnings,
        ];
    }

    private function extractWithOpenAi(string $absolutePath, string $mimeType, string $apiKey): array
    {
        $model = (string) config('services.openai.receipt_model', 'gpt-4.1-mini');
        $base64 = base64_encode((string) file_get_contents($absolutePath));
        $today = now()->toDateString();

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'vendor' => ['type' => ['string', 'null']],
                'receipt_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
                'subtotal' => ['type' => ['number', 'null']],
                'tax' => ['type' => ['number', 'null']],
                'tip' => ['type' => ['number', 'null']],
                'total' => ['type' => ['number', 'null']],
                'currency' => ['type' => ['string', 'null']],
                'payment_method' => ['type' => ['string', 'null']],
                'category' => ['type' => ['string', 'null']],
                'receipt_number' => ['type' => ['string', 'null']],
                'notes' => ['type' => ['string', 'null']],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'line_items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'description' => ['type' => 'string'],
                            'quantity' => ['type' => ['number', 'null']],
                            'unit_price' => ['type' => ['number', 'null']],
                            'line_total' => ['type' => ['number', 'null']],
                            'category_hint' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['description', 'quantity', 'unit_price', 'line_total', 'category_hint'],
                    ],
                ],
            ],
            'required' => [
                'vendor', 'receipt_date', 'subtotal', 'tax', 'tip', 'total', 'currency',
                'payment_method', 'category', 'receipt_number', 'notes', 'confidence', 'line_items',
            ],
        ];

        $prompt = <<<PROMPT
Read this consumer receipt and extract only information visible on it. Today is {$today}.
Return monetary amounts as positive decimal numbers without currency symbols.
Use YYYY-MM-DD for the receipt date. Do not invent missing values; use null.
For category, choose one practical household category such as Groceries, Home Supplies, Maintenance, Furniture, Utilities, Dining, Transportation, Health, Pet Care, Electronics, or Uncategorized Spending.
The total must be the final amount paid, not a subtotal or savings figure.
Confidence should reflect the reliability of the complete extraction.
PROMPT;

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('services.openai.receipt_timeout', 45))
            ->retry(1, 350)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'store' => false,
                'input' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $prompt],
                        [
                            'type' => 'input_image',
                            'image_url' => "data:{$mimeType};base64,{$base64}",
                            'detail' => 'high',
                        ],
                    ],
                ]],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'homeops_receipt_extraction',
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
                'max_output_tokens' => 2200,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('OpenAI receipt extraction failed: '.$response->status().' '.$response->body());
        }

        $payload = $response->json();
        $text = $this->responseOutputText($payload);
        $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        $data = $this->normalizeData($data);

        return [
            'provider' => 'openai:'.$model,
            'confidence' => (float) ($data['confidence'] ?? 0),
            'data' => $data,
            'raw_text' => null,
            'warnings' => [],
        ];
    }

    private function extractWithTesseract(string $absolutePath): array
    {
        $version = new Process(['tesseract', '--version']);
        $version->setTimeout(8);
        $version->run();
        if (!$version->isSuccessful()) {
            throw new \RuntimeException('Tesseract is not installed.');
        }

        $process = new Process(['tesseract', $absolutePath, 'stdout', '--psm', '6']);
        $process->setTimeout(40);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Tesseract could not read the receipt.');
        }

        $raw = trim($process->getOutput());
        if ($raw === '') {
            throw new \RuntimeException('No text was detected.');
        }

        $data = $this->parseOcrText($raw);

        return [
            'provider' => 'tesseract',
            'confidence' => (float) $data['confidence'],
            'data' => $data,
            'raw_text' => $raw,
            'warnings' => ['Local OCR was used. Check the highlighted values carefully before saving.'],
        ];
    }

    private function parseOcrText(string $raw): array
    {
        $lines = collect(preg_split('/\R+/', $raw))
            ->map(fn ($line) => trim(preg_replace('/\s+/', ' ', (string) $line)))
            ->filter(fn ($line) => $line !== '')
            ->values();

        $moneyFromLine = static function (string $line): ?float {
            preg_match_all('/(?:\$\s*)?(-?\d{1,6}(?:[,.]\d{2}))/u', $line, $matches);
            if (empty($matches[1])) return null;
            $value = str_replace(',', '.', end($matches[1]));
            return is_numeric($value) ? abs((float) $value) : null;
        };

        $findAmount = function (array $needles) use ($lines, $moneyFromLine): ?float {
            foreach ($lines->reverse() as $line) {
                $lower = mb_strtolower($line);
                if (collect($needles)->contains(fn ($needle) => str_contains($lower, $needle))) {
                    $value = $moneyFromLine($line);
                    if ($value !== null) return $value;
                }
            }
            return null;
        };

        $total = $findAmount(['grand total', 'amount due', 'balance due', 'total']);
        $subtotal = $findAmount(['subtotal', 'sub total']);
        $tax = $findAmount(['hst', 'gst', 'tax']);
        $tip = $findAmount(['tip', 'gratuity']);

        $date = null;
        foreach ($lines as $line) {
            if (preg_match('/\b(20\d{2})[-\/.](\d{1,2})[-\/.](\d{1,2})\b/', $line, $m)) {
                $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                break;
            }
            if (preg_match('/\b(\d{1,2})[-\/.](\d{1,2})[-\/.](20\d{2})\b/', $line, $m)) {
                try {
                    $date = Carbon::create((int) $m[3], (int) $m[1], (int) $m[2])->toDateString();
                } catch (\Throwable) {
                    try {
                        $date = Carbon::create((int) $m[3], (int) $m[2], (int) $m[1])->toDateString();
                    } catch (\Throwable) {
                        $date = null;
                    }
                }
                break;
            }
        }

        $vendor = $lines->first(function ($line) {
            $lower = mb_strtolower($line);
            return mb_strlen($line) >= 3
                && mb_strlen($line) <= 80
                && !preg_match('/\d{3,}/', $line)
                && !str_contains($lower, 'receipt')
                && !str_contains($lower, 'invoice');
        });

        $paymentMethod = null;
        foreach ($lines as $line) {
            if (preg_match('/\b(visa|mastercard|master card|amex|american express|debit|cash|interac|apple pay|google pay)\b/i', $line, $m)) {
                $paymentMethod = ucwords(mb_strtolower($m[1]));
                break;
            }
        }

        $category = $this->guessCategory((string) $vendor, $raw);
        $confidenceParts = [!empty($vendor), !empty($date), $total !== null];
        $confidence = 0.25 + (collect($confidenceParts)->filter()->count() * 0.18);

        return $this->normalizeData([
            'vendor' => $vendor ?: null,
            'receipt_date' => $date,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'tip' => $tip,
            'total' => $total,
            'currency' => 'CAD',
            'payment_method' => $paymentMethod,
            'category' => $category,
            'receipt_number' => null,
            'notes' => null,
            'confidence' => min($confidence, 0.72),
            'line_items' => [],
        ]);
    }

    private function guessCategory(string $vendor, string $raw): string
    {
        $haystack = mb_strtolower($vendor.' '.$raw);
        $rules = [
            'Groceries' => ['loblaws', 'metro', 'sobeys', 'farm boy', 'food basics', 'no frills', 'walmart', 'grocery'],
            'Home Supplies' => ['home depot', 'rona', 'canadian tire', 'home hardware', 'ikea', 'winners', 'homesense'],
            'Dining' => ['restaurant', 'cafe', 'coffee', 'pizza', 'uber eats', 'doordash', 'skip'],
            'Transportation' => ['esso', 'shell', 'petro-canada', 'parking', 'uber', 'lyft'],
            'Pet Care' => ['pet valu', 'petsmart', 'veterinary', 'vet clinic'],
            'Health' => ['pharmacy', 'shoppers drug mart', 'rexall', 'dental'],
            'Electronics' => ['best buy', 'apple store', 'canada computers'],
        ];

        foreach ($rules as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) return $category;
            }
        }

        return 'Uncategorized Spending';
    }

    private function responseOutputText(array $payload): string
    {
        foreach (($payload['output'] ?? []) as $output) {
            if (($output['type'] ?? null) !== 'message') continue;
            foreach (($output['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text' && isset($content['text'])) {
                    return (string) $content['text'];
                }
            }
        }

        throw new \RuntimeException('The extraction service returned no structured text.');
    }

    private function normalizeData(array $data): array
    {
        $normalized = array_merge($this->emptyData(), $data);
        $normalized['currency'] = strtoupper((string) ($normalized['currency'] ?: 'CAD'));
        $normalized['confidence'] = max(0, min(1, (float) ($normalized['confidence'] ?? 0)));
        $normalized['line_items'] = collect($normalized['line_items'] ?? [])
            ->filter(fn ($item) => is_array($item) && trim((string) ($item['description'] ?? '')) !== '')
            ->take(80)
            ->map(fn ($item) => [
                'description' => trim((string) $item['description']),
                'quantity' => isset($item['quantity']) && is_numeric($item['quantity']) ? (float) $item['quantity'] : null,
                'unit_price' => isset($item['unit_price']) && is_numeric($item['unit_price']) ? (float) $item['unit_price'] : null,
                'line_total' => isset($item['line_total']) && is_numeric($item['line_total']) ? (float) $item['line_total'] : null,
                'category_hint' => !empty($item['category_hint']) ? trim((string) $item['category_hint']) : null,
            ])->values()->all();

        foreach (['subtotal', 'tax', 'tip', 'total'] as $field) {
            $normalized[$field] = isset($normalized[$field]) && is_numeric($normalized[$field])
                ? round(abs((float) $normalized[$field]), 2)
                : null;
        }

        return $normalized;
    }

    private function emptyData(): array
    {
        return [
            'vendor' => null,
            'receipt_date' => null,
            'subtotal' => null,
            'tax' => null,
            'tip' => null,
            'total' => null,
            'currency' => 'CAD',
            'payment_method' => null,
            'category' => 'Uncategorized Spending',
            'receipt_number' => null,
            'notes' => null,
            'confidence' => 0,
            'line_items' => [],
        ];
    }
}
