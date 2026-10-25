<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One quick-idea chip on the Customize page. See the design_ideas migration for what scope and idea_key mean. */
class DesignIdea extends Model
{
    protected $fillable = ['scope', 'idea_key', 'text', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** Every active idea, grouped for the page: ['type' => [key => [text]], 'style' => [key => [text]], 'general' => [text]]. */
    public static function forStudio(): array
    {
        $out = ['type' => [], 'style' => [], 'general' => []];

        foreach (static::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get() as $idea) {
            if ($idea->scope === 'general') {
                $out['general'][] = $idea->text;
            } elseif (isset($out[$idea->scope])) {
                $out[$idea->scope][$idea->idea_key][] = $idea->text;
            }
        }

        return $out;
    }
}
