<?php

namespace Drupal\ayb_mevzuat_calisma\Service;

use Drupal\Component\Diff\Diff;
use Drupal\Component\Utility\Html;

/** Provides bounded, Unicode-safe comparison primitives for draft text. */
final class DraftDiffService {

  /** Returns line edits after converting formatted text to visible lines. */
  public function compare(string $from, string $to): array {
    if (strlen($from) + strlen($to) > 200000) {
      return ['too_large' => TRUE, 'edits' => []];
    }
    return ['too_large' => FALSE, 'edits' => (new Diff($this->plainLines($from), $this->plainLines($to)))->getEdits()];
  }

  /** Creates a safe Unicode character-level diff for two changed lines. */
  public function inlineDiff(string $from, string $to): string {
    $tokenize = static fn(string $text): array => preg_split('/(\X)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    $old = $tokenize($from);
    $new = $tokenize($to);
    $prefix = 0;
    $old_count = count($old);
    $new_count = count($new);
    while ($prefix < $old_count && $prefix < $new_count && $old[$prefix] === $new[$prefix]) {
      $prefix++;
    }
    $suffix = 0;
    while ($suffix < $old_count - $prefix && $suffix < $new_count - $prefix && $old[$old_count - $suffix - 1] === $new[$new_count - $suffix - 1]) {
      $suffix++;
    }
    $old_middle = array_slice($old, $prefix, $old_count - $prefix - $suffix);
    $new_middle = array_slice($new, $prefix, $new_count - $prefix - $suffix);
    $prefix_text = Html::escape(implode('', array_slice($old, 0, $prefix)));
    $suffix_text = Html::escape($suffix ? implode('', array_slice($old, -$suffix)) : '');
    if (count($old_middle) > 700 || count($new_middle) > 700) {
      return $prefix_text . '<del class="ayb-diff-delete">' . Html::escape(implode('', $old_middle)) . '</del><ins class="ayb-diff-insert">' . Html::escape(implode('', $new_middle)) . '</ins>' . $suffix_text;
    }
    $n = count($old_middle);
    $m = count($new_middle);
    $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) {
      for ($j = $m - 1; $j >= 0; $j--) {
        $table[$i][$j] = $old_middle[$i] === $new_middle[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
      }
    }
    $html = $prefix_text;
    $i = $j = 0;
    $deleted = $inserted = '';
    $flush = static function () use (&$html, &$deleted, &$inserted): void {
      if ($deleted !== '') {
        $html .= '<del class="ayb-diff-delete">' . Html::escape($deleted) . '</del>';
        $deleted = '';
      }
      if ($inserted !== '') {
        $html .= '<ins class="ayb-diff-insert">' . Html::escape($inserted) . '</ins>';
        $inserted = '';
      }
    };
    while ($i < $n && $j < $m) {
      if ($old_middle[$i] === $new_middle[$j]) {
        $flush();
        $html .= Html::escape($old_middle[$i++]);
        $j++;
      }
      elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
        $deleted .= $old_middle[$i++];
      }
      else {
        $inserted .= $new_middle[$j++];
      }
    }
    while ($i < $n) { $deleted .= $old_middle[$i++]; }
    while ($j < $m) { $inserted .= $new_middle[$j++]; }
    $flush();
    return $html . $suffix_text;
  }

  /** Converts formatted editor output to a stable line array. */
  private function plainLines(string $html): array {
    $html = preg_replace('~</(?:p|div|li|h[1-6])>|<br\s*/?>~i', "\n", $html);
    return explode("\n", trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
  }

}
