<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomeOpsPublicContentController extends Controller
{
    public function index(Request $request)
    {
        if (!Schema::hasTable('homeops_cms_entries')) {
            return response()->json(['content' => new \stdClass(), 'generated_at' => now()->toIso8601String()]);
        }

        $area = trim((string) $request->query('area', ''));
        $query = DB::table('homeops_cms_entries')
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->orderBy('key');

        if ($area !== '') {
            $query->where('area', $area);
        }

        $content = [];
        $latestPublishedAt = null;
        foreach ($query->get(['key', 'value_json', 'published_at']) as $entry) {
            $decoded = json_decode((string) $entry->value_json, true);
            if (!is_array($decoded)) continue;
            $content[$entry->key] = $decoded;
            if (!$latestPublishedAt || $entry->published_at > $latestPublishedAt) {
                $latestPublishedAt = $entry->published_at;
            }
        }

        return response()->json([
            'content' => empty($content) ? new \stdClass() : $content,
            'published_at' => $latestPublishedAt,
            'generated_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }
}
