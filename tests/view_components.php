<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Verdant\Http\View;

$checks = 0;
function componentCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filters = ['sentinel' => 'keep page query state'];
$component = [
    'action' => '//evil.example/catalogue',
    'legend' => '<script>bad()</script>',
    'active' => [['label' => 'Low light', 'remove_href' => "\\\\evil.example"]],
    'fields' => [['name' => 'q', 'label' => 'Search', 'type' => 'search', 'value' => '<script>bad()</script>']],
];
ob_start();
require dirname(__DIR__) . '/app/Views/components/filter-panel.php';
$filterHtml = (string) ob_get_clean();
componentCheck(str_contains($filterHtml, '<details class="filter-disclosure">') && !str_contains($filterHtml, '<details class="filter-disclosure" open'), 'Native filters start collapsed.');
componentCheck(!str_contains($filterHtml, '<script>') && str_contains($filterHtml, '&lt;script&gt;'), 'Filter labels and values are escaped.');
componentCheck(str_contains($filterHtml, 'action="/catalogue"') && !str_contains($filterHtml, 'href="\\\\evil.example'), 'Protocol-relative and backslash paths are rejected.');
componentCheck(($filters['sentinel'] ?? null) === 'keep page query state', 'Filter partial preserves the caller query variable.');

$component = ['label' => 'Browse', 'href' => "//evil.example\n", 'variant' => 'unknown'];
ob_start();
require dirname(__DIR__) . '/app/Views/components/button.php';
$buttonHtml = (string) ob_get_clean();
componentCheck(str_contains($buttonHtml, 'href="#"') && !str_contains($buttonHtml, 'evil.example'), 'Unsafe button links fall back to a non-external destination.');

$component = ['items' => array_map(static fn (string $label): array => ['label' => $label, 'text' => 'Service'], ['Shop online', 'Consult', 'Collect', 'Deliver', 'Install'])];
ob_start();
require dirname(__DIR__) . '/app/Views/components/service-band.php';
$serviceHtml = (string) ob_get_clean();
componentCheck(substr_count($serviceHtml, 'class="service-item"') === 5, 'Service band renders all five accepted service labels.');

$layoutRejected = false;
try {
    View::render('error', ['title' => 'Unavailable', 'message' => 'Try again.'], 200, 'arbitrary');
} catch (InvalidArgumentException) {
    $layoutRejected = true;
}
componentCheck($layoutRejected, 'View rejects layouts outside the explicit allowlist.');
ob_start();
View::render('error', ['title' => 'Unavailable', 'message' => 'Try again.'], 200, 'internal');
$internalHtml = (string) ob_get_clean();
componentCheck(str_contains($internalHtml, 'class="layout-internal"') && str_contains($internalHtml, 'aria-label="Administration"'), 'Controller-selected internal layout renders its own navigation.');

$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
componentCheck(str_contains($css, 'min-height:44px') && str_contains($css, 'prefers-reduced-motion:reduce'), 'Shared action styles retain touch targets and reduced-motion support.');
$relativeLuminance = static function (string $hex): float {
    $channels = array_map(static function (string $channel): float {
        $value = hexdec($channel) / 255;
        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }, str_split(ltrim($hex, '#'), 2));
    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
};
$mutedLuminance = $relativeLuminance('66675e');
$creamLuminance = $relativeLuminance('f6f2e9');
$contrast = (max($mutedLuminance, $creamLuminance) + 0.05) / (min($mutedLuminance, $creamLuminance) + 0.05);
componentCheck($contrast >= 4.5, 'Muted text token reaches 4.5:1 contrast on the cream surface.');

echo "PASS: {$checks} shared component assertions\n";
