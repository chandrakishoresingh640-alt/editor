<?php
declare(strict_types=1);

function render_header(string $title, string $active = ''): void
{
    $name = (string) config('app_name');
    $full = $title === $name ? $name : ($title . ' · ' . $name);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="description" content="Enhance scanned documents, extract text, edit PDFs, and summarize transcripts you are allowed to use.">';
    echo '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
    echo '<meta name="app-base" content="' . e(app_base_path()) . '">';
    echo '<title>' . e($full) . '</title>';
    echo '<link rel="icon" href="' . e(url_path('/assets/favicon.svg')) . '" type="image/svg+xml">';
    echo '<link rel="stylesheet" href="' . e(url_path('/assets/css/style.css')) . '">';
    echo '</head><body>';
    echo '<a class="skip" href="#content">Skip to content</a>';
    echo '<header class="site-header"><div class="nav-inner">';
    echo '<a class="brand" href="' . e(url_path('/')) . '"><span class="mark" aria-hidden="true"></span>' . e($name) . '</a>';
    echo '<button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav">Menu</button>';
    echo '<nav id="site-nav" class="site-nav" aria-label="Primary">';
    $links = [
        'tools' => ['Tools', '/#tools'],
        'features' => ['Features', '/#features'],
        'faq' => ['FAQ', '/#faq'],
        'about' => ['About', '/about.php'],
    ];
    foreach ($links as $key => [$label, $href]) {
        $class = $active === $key ? ' class="active"' : '';
        echo '<a' . $class . ' href="' . e(url_path($href)) . '">' . e($label) . '</a>';
    }
    echo '</nav></div></header><main id="content">';
}

function render_footer(array $scripts = []): void
{
    $year = gmdate('Y');
    $name = (string) config('app_name');
    echo '</main><footer class="site-footer"><div class="footer-inner">';
    echo '<div><strong>' . e($name) . '</strong><p>Document tools for files you are allowed to process. Temporary files stay on this server and are deleted on a schedule.</p></div>';
    echo '<nav aria-label="Footer">';
    foreach ([
        '/legal/privacy.php' => 'Privacy Policy',
        '/legal/terms.php' => 'Terms of Service',
        '/legal/cookies.php' => 'Cookie Policy',
        '/contact.php' => 'Contact',
    ] as $href => $label) {
        echo '<a href="' . e(url_path($href)) . '">' . e($label) . '</a>';
    }
    echo '</nav>';
    echo '<p class="copyright">© ' . e($year) . ' ' . e($name) . '. No compliance certification is claimed.</p>';
    echo '</div></footer>';
    echo '<div id="toast" class="toast" role="status" aria-live="polite"></div>';
    echo '<script src="' . e(url_path('/assets/js/app.js')) . '" defer></script>';
    foreach ($scripts as $script) {
        echo '<script src="' . e(url_path($script)) . '" defer></script>';
    }
    echo '</body></html>';
}

function tool_intro(string $kicker, string $title, string $lede): void
{
    echo '<section class="tool-hero"><div class="wrap">';
    echo '<p class="kicker">' . e($kicker) . '</p>';
    echo '<h1>' . e($title) . '</h1>';
    echo '<p class="lede">' . e($lede) . '</p>';
    echo '</div></section>';
}

function capability_note(string $id): void
{
    echo '<div id="' . e($id) . '" class="note" hidden></div>';
}
