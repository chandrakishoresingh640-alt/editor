<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/storage.php';
require_once $root . '/includes/PdfUnlock.php';
require_once $root . '/includes/PdfFile.php';
require_once $root . '/includes/PdfBuilder.php';
require_once $root . '/includes/UnicodePdf.php';
require_once $root . '/includes/DocxBuilder.php';
require_once $root . '/includes/Summarizer.php';
require_once $root . '/includes/YouTubeClient.php';
require_once $root . '/includes/ImageWorkshop.php';
require_once $root . '/includes/jobs.php';

$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        fwrite(STDOUT, "PASS  {$name}\n");
        return;
    }
    $failures++;
    fwrite(STDOUT, "FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n");
}

function expect_code(string $name, callable $fn, string $code): void
{
    try {
        $fn();
        check($name, false, 'no exception');
    } catch (AppException $e) {
        check($name, $e->errorCode() === $code, $e->errorCode() . ': ' . $e->getMessage());
    } catch (Throwable $e) {
        check($name, false, $e::class . ': ' . $e->getMessage());
    }
}

function pdf_text(string $path): string
{
    $bin = binary_path('pdftotext');
    if ($bin === null) {
        return '';
    }
    $result = run_process([$bin, '-q', $path, '-'], null, 20);
    return $result['stdout'];
}

function metadata_clear(string $bytes): bool
{
    foreach (['/Producer', '/Creator', '/CreationDate', '/ModDate', '/Author', '/Info', '/Metadata', '/PieceInfo', 'xpacket', 'gd-jpeg', "Exif\x00"] as $needle) {
        if (str_contains($bytes, $needle)) {
            return false;
        }
    }
    return true;
}

function pdf_ok(string $path): bool
{
    $bin = binary_path('qpdf');
    if ($bin === null) {
        return str_starts_with((string) file_get_contents($path), '%PDF-');
    }
    $result = run_process([$bin, '--check', $path], null, 20);
    $text = $result['stdout'] . $result['stderr'];
    return $result['code'] === 0 && !str_contains(strtolower($text), 'error:');
}

fwrite(STDOUT, "PHP " . PHP_VERSION . "\n");

$phpFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($phpFiles as $file) {
    $path = $file->getPathname();
    if (!str_ends_with($path, '.php') || str_contains($path, '/vendor/')) {
        continue;
    }
    $lint = run_process([PHP_BINARY, '-l', $path], null, 20);
    check('lint ' . substr($path, strlen($root) + 1), $lint['code'] === 0, trim($lint['stdout'] . $lint['stderr']));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['owner'] = bin2hex(random_bytes(16));
$_SESSION['csrf'] = bin2hex(random_bytes(16));

$tmp = sys_get_temp_dir() . '/quire-tests-' . bin2hex(random_bytes(4));
mkdir($tmp);

$plain = UnicodePdf::fromText("Hello Quire\nThe café opens at nine.\nPlease email the notes tomorrow.");
file_put_contents($tmp . '/plain.pdf', $plain);
check('unicode pdf opens', pdf_ok($tmp . '/plain.pdf'));
$plainText = pdf_text($tmp . '/plain.pdf');
check('unicode pdf text', str_contains($plainText, 'Hello Quire') && str_contains($plainText, 'café'), trim($plainText));

$qpdf = binary_path('qpdf');
check('qpdf present for this environment', $qpdf !== null);
if ($qpdf !== null) {
    $encrypt = static function (string $out, array $extra) use ($qpdf, $tmp): array {
        $command = array_merge([$qpdf], $extra, [$tmp . '/plain.pdf', $out]);
        return run_process($command, null, 30);
    };
    $enc = $encrypt($tmp . '/enc256.pdf', ['--encrypt', '--user-password=secret', '--owner-password=owner', '--bits=256', '--']);
    check('create aes-256 pdf', $enc['code'] === 0, trim($enc['stderr']));
    $enc128 = $encrypt($tmp . '/enc128.pdf', ['--allow-weak-crypto', '--encrypt', '--user-password=secret', '--owner-password=owner', '--bits=128', '--']);
    check('create rc4-128 pdf', $enc128['code'] === 0, trim($enc128['stderr']));
    $aes128 = $encrypt($tmp . '/aes128.pdf', ['--allow-weak-crypto', '--encrypt', '--user-password=secret', '--owner-password=owner', '--bits=128', '--use-aes=y', '--']);
    check('create aes-128 pdf', $aes128['code'] === 0, trim($aes128['stderr']));
    $restrict = $encrypt($tmp . '/restrict.pdf', ['--encrypt', '--user-password=', '--owner-password=ownerpw', '--bits=256', '--print=none', '--modify=none', '--extract=n', '--']);
    check('create restriction pdf', $restrict['code'] === 0, trim($restrict['stderr']));
    $streams = $encrypt($tmp . '/osenc.pdf', ['--object-streams=generate', '--stream-data=compress', '--encrypt', '--user-password=secret', '--owner-password=owner', '--bits=256', '--']);
    check('create object-stream pdf', $streams['code'] === 0, trim($streams['stderr']));

    $cases = [
        'enc256.pdf' => 'secret',
        'enc128.pdf' => 'secret',
        'aes128.pdf' => 'secret',
        'osenc.pdf' => 'secret',
    ];
    foreach ($cases as $name => $password) {
        $bytes = (string) file_get_contents($tmp . '/' . $name);
        $info = PdfUnlock::inspect($bytes);
        check("inspect {$name}", !empty($info['encrypted']) && !empty($info['user_password_required']), json_encode($info));
        expect_code("bad password {$name}", static fn () => PdfUnlock::unlock($bytes, 'nope'), 'bad_password');
        $opened = PdfUnlock::unlock($bytes, $password);
        file_put_contents($tmp . '/out-' . $name, $opened['bytes']);
        $text = pdf_text($tmp . '/out-' . $name);
        check("unlock {$name}", pdf_ok($tmp . '/out-' . $name) && str_contains($text, 'Hello Quire') && str_contains($text, 'café') && metadata_clear($opened['bytes']), trim($text));
        $php = PdfFile::decrypt($bytes, $password);
        file_put_contents($tmp . '/php-' . $name, $php['bytes']);
        $phpText = pdf_text($tmp . '/php-' . $name);
        check("php unlock {$name}", pdf_ok($tmp . '/php-' . $name) && str_contains($phpText, 'Hello Quire') && str_contains($phpText, 'café') && metadata_clear($php['bytes']), trim($phpText));
    }

    $restrictBytes = (string) file_get_contents($tmp . '/restrict.pdf');
    $restrictInfo = PdfUnlock::inspect($restrictBytes);
    check(
        'restriction inspect',
        !empty($restrictInfo['encrypted']) && empty($restrictInfo['user_password_required']) && empty($restrictInfo['permissions']['copy']),
        json_encode($restrictInfo)
    );
    $cleared = PdfUnlock::unlock($restrictBytes, '');
    file_put_contents($tmp . '/restrict-out.pdf', $cleared['bytes']);
    $clearedText = pdf_text($tmp . '/restrict-out.pdf');
    check('restriction unlock', pdf_ok($tmp . '/restrict-out.pdf') && str_contains($clearedText, 'Hello Quire') && metadata_clear($cleared['bytes']), trim($clearedText));

    $owner = PdfFile::decrypt((string) file_get_contents($tmp . '/enc256.pdf'), 'owner');
    check('owner password kind', ($owner['password_type'] ?? '') === 'owner', json_encode(array_diff_key($owner, ['bytes' => true])));
}

$plainInfo = PdfUnlock::inspect($plain);
check('plain inspect', empty($plainInfo['encrypted']), json_encode($plainInfo));
$unchanged = PdfUnlock::unlock($plain, '');
check('plain unlock unchanged', !empty($unchanged['unchanged']) && $unchanged['bytes'] === $plain);
expect_code('corrupt pdf', static fn () => PdfUnlock::inspect('this is not a pdf'), 'invalid_pdf');
file_put_contents($tmp . '/damaged.pdf', "%PDF-1.7\ntrailer\n<<>>\n%%EOF\n");
expect_code('damaged pdf', static fn () => PdfUnlock::inspect((string) file_get_contents($tmp . '/damaged.pdf')), 'damaged_pdf');

$image = imagecreatetruecolor(900, 220);
$white = imagecolorallocate($image, 255, 255, 255);
$black = imagecolorallocate($image, 10, 10, 10);
imagefilledrectangle($image, 0, 0, 900, 220, $white);
$font = $root . '/public/assets/fonts/DejaVuSans.ttf';
imagettftext($image, 42, 0, 40, 130, $black, $font, 'Hello Quire');
ob_start();
imagejpeg($image, null, 92);
$jpeg = (string) ob_get_clean();
imagedestroy($image);
$filtered = ImageWorkshop::apply($jpeg, ['grayscale' => true, 'contrast' => 12, 'sharpen' => 20, 'brightness' => 4]);
check('image filters', str_starts_with($filtered, "\xFF\xD8\xFF"));
$rotated = ImageWorkshop::apply($jpeg, ['rotate' => 90]);
$rotInfo = getimagesizefromstring($rotated);
check('image rotate', is_array($rotInfo) && $rotInfo[0] === 220 && $rotInfo[1] === 900, json_encode($rotInfo));

$scan = Storage::put($filtered, 'scan.jpg', 'image');
$ocr = ImageWorkshop::ocr(Storage::path($scan['id']), 'eng');
check('tesseract reads image', str_contains(strtolower($ocr['text']), 'hello'), $ocr['text']);
expect_code('ocr language', static fn () => ImageWorkshop::ocr(Storage::path($scan['id']), 'zzz'), 'language');

$builder = new PdfBuilder();
[$x, $y, $w, $h, $pw, $ph] = ImageWorkshop::placement(900, 220, 'a4', 'portrait', 36);
$builder->addJpegPage($filtered, $pw, $ph, $x, $y, $w, $h, $ocr['words']);
file_put_contents($tmp . '/built.pdf', $builder->bytes());
$builtText = pdf_text($tmp . '/built.pdf');
check('searchable image pdf', pdf_ok($tmp . '/built.pdf') && str_contains(strtolower($builtText), 'hello'), trim($builtText));

$storedPdf = Storage::put($plain, " ../../weird\nname.pdf", 'pdf');
check('safe stored name', !str_contains($storedPdf['name'], '..') && !str_contains($storedPdf['name'], "\n"));
$enhanced = enhance_stored_pdf($storedPdf['id'], [
    'level' => 'standard',
    'grayscale' => true,
    'brightness' => 6,
    'contrast' => 18,
    'sharpen' => 30,
    'denoise' => true,
    'deskew' => true,
    'searchable' => true,
    'language' => 'eng',
]);
file_put_contents($tmp . '/enhanced.pdf', Storage::bytes($enhanced['id']));
$enhancedText = pdf_text($tmp . '/enhanced.pdf');
check('enhance pipeline', pdf_ok($tmp . '/enhanced.pdf') && !empty($enhanced['searchable']) && str_contains(strtolower($enhancedText), 'hello'), trim($enhancedText));
check('original kept', Storage::bytes($storedPdf['id']) === $plain);

$imagesPdf = images_to_stored_pdf([$scan['id']], [
    'page_size' => 'letter',
    'orientation' => 'landscape',
    'margin' => 24,
    'searchable' => true,
    'language' => 'eng',
    'rotations' => [0],
]);
file_put_contents($tmp . '/images.pdf', Storage::bytes($imagesPdf['id']));
check('images to pdf', pdf_ok($tmp . '/images.pdf') && str_contains(strtolower(pdf_text($tmp . '/images.pdf')), 'hello'));
foreach (['built.pdf', 'enhanced.pdf', 'images.pdf', 'plain.pdf'] as $cleanName) {
    $cleanBytes = (string) file_get_contents($tmp . '/' . $cleanName);
    check('no metadata in ' . $cleanName, metadata_clear($cleanBytes), $cleanName);
}

$marked = "\xFF\xD8\xFF\xE1" . pack('n', 2 + strlen("Exif\x00\x00SECRET_AUTHOR_GPS")) . "Exif\x00\x00SECRET_AUTHOR_GPS" . substr($jpeg, 2);
$keptImage = Storage::put($marked, 'camera.jpg', 'image');
check('original image keeps metadata', str_contains(Storage::bytes($keptImage['id']), 'SECRET_AUTHOR_GPS'));
$processedImage = ImageWorkshop::apply($marked, []);
check(
    'processed image drops metadata',
    !str_contains($processedImage, 'SECRET_AUTHOR_GPS') && !str_contains($processedImage, 'gd-jpeg') && getimagesizefromstring($processedImage) !== false
);
$pngImage = imagecreatetruecolor(4, 4);
ob_start();
imagepng($pngImage);
$pngBytes = (string) ob_get_clean();
$pngText = "Author\x00SECRET_PNG_AUTHOR";
$pngChunk = pack('N', strlen($pngText)) . 'tEXt' . $pngText . pack('N', crc32('tEXt' . $pngText));
$pngBytes = substr($pngBytes, 0, -12) . $pngChunk . substr($pngBytes, -12);
$pngClean = OutputClean::png($pngBytes);
ob_start();
imagegif($pngImage);
$gifBytes = (string) ob_get_clean();
imagedestroy($pngImage);
if (str_ends_with($gifBytes, "\x3B")) {
    $gifBytes = substr($gifBytes, 0, -1) . "\x21\xFE" . chr(10) . "SECRET_GIF\x00\x3B";
}
$gifClean = OutputClean::gif($gifBytes);
check(
    'png and gif drop metadata',
    !str_contains($pngClean, 'SECRET_PNG_AUTHOR') && @imagecreatefromstring($pngClean) !== false
        && !str_contains($gifClean, 'SECRET_GIF') && @imagecreatefromstring($gifClean) !== false
);

$tinyImage = imagecreatetruecolor(8, 8);
ob_start();
imagejpeg($tinyImage, null, 90);
$tinyJpeg = (string) ob_get_clean();
imagedestroy($tinyImage);
$exif = "Exif\x00\x00SECRET_AUTHOR_GPS";
$tinyMarked = "\xFF\xD8\xFF\xE1" . pack('n', 2 + strlen($exif)) . $exif . substr($tinyJpeg, 2);
$xmp = '<?xpacket begin="SECRET_XMP_AUTHOR"?>';
$pageContent = "BT /F1 24 Tf 40 100 Td (Hello Quire) Tj ET\n";
$metaObjects = [
    4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    5 => '<< /Type /Metadata /Subtype /XML /Length ' . strlen($xmp) . " >>\nstream\n{$xmp}\nendstream",
    7 => '<< /Type /XObject /Subtype /Image /Width 8 /Height 8 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($tinyMarked) . " >>\nstream\n{$tinyMarked}\nendstream",
    8 => '<< /Length ' . strlen($pageContent) . " >>\nstream\n{$pageContent}\nendstream",
    3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << /Font << /F1 4 0 R >> /XObject << /Im1 7 0 R >> >> /Contents 8 0 R /LastModified (D:20200101120000Z) /PieceInfo << /Adobe << /Private (SECRET_PIECE) >> >> /Metadata 5 0 R >>',
    2 => '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
    1 => '<< /Type /Catalog /Pages 2 0 R /Metadata 5 0 R >>',
    6 => '<< /Title (Secret Title) /Author (Secret Author) /Producer (Secret Producer) /CreationDate (D:20200101120000Z) >>',
];
$metaPdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
$metaOffsets = [];
for ($metaNum = 1; $metaNum <= 8; $metaNum++) {
    if (!isset($metaObjects[$metaNum])) {
        continue;
    }
    $metaOffsets[$metaNum] = strlen($metaPdf);
    $metaPdf .= $metaNum . " 0 obj\n" . $metaObjects[$metaNum] . "\nendobj\n";
}
$metaXref = strlen($metaPdf);
$metaPdf .= "xref\n0 9\n0000000000 65535 f \n";
for ($metaNum = 1; $metaNum <= 8; $metaNum++) {
    $metaPdf .= isset($metaOffsets[$metaNum]) ? sprintf("%010d 00000 n \n", $metaOffsets[$metaNum]) : "0000000000 65535 f \n";
}
$metaPdf .= "trailer\n<< /Size 9 /Root 1 0 R /Info 6 0 R /ID [<0123456789abcdef0123456789abcdef> <0123456789abcdef0123456789abcdef>] >>\nstartxref\n{$metaXref}\n%%EOF\n";
file_put_contents($tmp . '/meta-dirty.pdf', $metaPdf);
$metaClean = OutputClean::pdf($metaPdf);
file_put_contents($tmp . '/meta-clean.pdf', $metaClean);
check(
    'metadata pdf stripped',
    pdf_ok($tmp . '/meta-clean.pdf')
        && metadata_clear($metaClean)
        && !str_contains($metaClean, 'SECRET_')
        && !str_contains($metaClean, 'Secret ')
        && str_contains(pdf_text($tmp . '/meta-clean.pdf'), 'Hello Quire')
);
if ($qpdf !== null) {
    $metaEnc = run_process([$qpdf, '--encrypt', '--user-password=secret', '--owner-password=owner', '--bits=256', '--', $tmp . '/meta-dirty.pdf', $tmp . '/meta-enc.pdf'], null, 30);
    check('encrypt cleaned pdf', $metaEnc['code'] === 0, trim($metaEnc['stderr']));
    if ($metaEnc['code'] === 0) {
        $metaOpened = PdfUnlock::unlock((string) file_get_contents($tmp . '/meta-enc.pdf'), 'secret');
        file_put_contents($tmp . '/meta-unlocked.pdf', $metaOpened['bytes']);
        check(
            'unlocked pdf stays clean',
            empty($metaOpened['unchanged']) && pdf_ok($tmp . '/meta-unlocked.pdf') && metadata_clear($metaOpened['bytes']) && str_contains(pdf_text($tmp . '/meta-unlocked.pdf'), 'Hello Quire')
        );
    }
}

$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    $nodeScript = $tmp . '/clean-browser.js';
    file_put_contents($nodeScript, <<<'JS'
const fs = require("fs");
const vm = require("vm");
const code = fs.readFileSync(process.argv[3], "utf8");
const sandbox = { Uint8Array, ArrayBuffer, Blob, Response, DecompressionStream, setTimeout };
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(code, sandbox);
sandbox.QuireClean.pdf(new sandbox.Uint8Array(fs.readFileSync(process.argv[2]))).then((clean) => {
  fs.writeFileSync(process.argv[4], Buffer.from(clean));
  process.stdout.write("clean");
}).catch((error) => {
  console.error(error && error.stack ? error.stack : error);
  process.exit(1);
});
JS);
    $nodeResult = run_process([$node, $nodeScript, $tmp . '/meta-dirty.pdf', $root . '/public/assets/js/clean.js', $tmp . '/meta-browser.pdf'], null, 30);
    $browserBytes = is_file($tmp . '/meta-browser.pdf') ? (string) file_get_contents($tmp . '/meta-browser.pdf') : '';
    check(
        'browser cleaner',
        $nodeResult['code'] === 0
            && pdf_ok($tmp . '/meta-browser.pdf')
            && metadata_clear($browserBytes)
            && !str_contains($browserBytes, 'SECRET_')
            && str_contains(pdf_text($tmp . '/meta-browser.pdf'), 'Hello Quire'),
        trim($nodeResult['stdout'] . $nodeResult['stderr'])
    );
}

$docx = DocxBuilder::fromBlocks([
    ['text' => 'Hello Quire', 'size' => 18],
    ['text' => 'Second line', 'size' => 12],
]);
$docxPath = $tmp . '/notes.docx';
file_put_contents($docxPath, $docx);
$zip = new ZipArchive();
$openedZip = $zip->open($docxPath) === true;
$xml = $openedZip ? (string) $zip->getFromName('word/document.xml') : '';
if ($openedZip) {
    $zip->close();
}
check('docx export', $openedZip && str_contains($xml, 'Hello Quire') && str_contains($xml, 'w:sz w:val="36"'), substr($xml, 0, 180));

$summary = Summarizer::extractive("Hello Quire is a document tool. It keeps files on the server for a short time. Please email the notes tomorrow. The café opens at nine.", 'short');
check('extractive summary', ($summary['method'] ?? '') === 'extractive' && ($summary['key_points'] ?? []) !== [] && ($summary['action_items'] ?? []) !== []);
expect_code('empty summary', static fn () => Summarizer::extractive('   ', 'medium'), 'empty');
$long = implode(' ', array_map(static fn (int $i): string => "Sentence {$i} describes a separate point about documents.", range(1, 40)));
$detailed = Summarizer::extractive($long, 'detailed');
check('long summary', ($detailed['sentence_count'] ?? 0) >= 20 && count($detailed['sections'] ?? []) >= 1);

check('youtube watch', YouTubeClient::videoId('https://www.youtube.com/watch?v=dQw4w9WgXcQ') === 'dQw4w9WgXcQ');
check('youtube short link', YouTubeClient::videoId('https://youtu.be/dQw4w9WgXcQ') === 'dQw4w9WgXcQ');
check('youtube shorts', YouTubeClient::videoId('https://www.youtube.com/shorts/dQw4w9WgXcQ') === 'dQw4w9WgXcQ');
check('youtube embed', YouTubeClient::videoId('https://www.youtube.com/embed/dQw4w9WgXcQ') === 'dQw4w9WgXcQ');
expect_code('youtube host', static fn () => YouTubeClient::videoId('https://example.com/watch?v=dQw4w9WgXcQ'), 'bad_url');
expect_code('youtube empty', static fn () => YouTubeClient::videoId(''), 'bad_url');
$vtt = YouTubeClient::vttToText("WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nHello Quire\n\n00:00:04.000 --> 00:00:06.000\nHello Quire\n");
check('vtt text', $vtt === 'Hello Quire', $vtt);

expect_code('reject php upload', static fn () => Storage::put("<?php echo 1;\n", 'shell.php', 'any'), 'type');
expect_code('bad id', static fn () => Storage::assertId('../etc/passwd'), 'bad_id');
expect_code('pdf kind mismatch', static fn () => Storage::put($jpeg, 'photo.jpg', 'pdf'), 'type');
$ownerA = $_SESSION['owner'];
$idA = $scan['id'];
$_SESSION['owner'] = bin2hex(random_bytes(16));
expect_code('session isolation', static fn () => Storage::meta($idA), 'not_found');
$_SESSION['owner'] = $ownerA;
check('owner can read own file', Storage::meta($idA)['id'] === $idA);

$_POST = [];
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong';
expect_code('csrf mismatch', static fn () => csrf_check(), 'csrf');
$_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf'];
try {
    csrf_check();
    check('csrf match', true);
} catch (Throwable $e) {
    check('csrf match', false, $e->getMessage());
}

$caps = app_capabilities();
foreach (['gd', 'ghostscript', 'tesseract', 'qpdf', 'poppler', 'zip', 'browser_ocr'] as $key) {
    check('capability ' . $key, !empty($caps[$key]));
}
check('browser language', in_array('eng', $caps['browser_ocr_languages'] ?? [], true));

$old = APP_ROOT . '/private_storage/uploads/test-old-' . bin2hex(random_bytes(3)) . '.bin';
$fresh = APP_ROOT . '/private_storage/uploads/test-fresh-' . bin2hex(random_bytes(3)) . '.bin';
file_put_contents($old, 'old');
file_put_contents($fresh, 'fresh');
touch($old, time() - 7200);
$removed = cleanup_expired();
check('cleanup old file', !is_file($old) && is_file($fresh) && $removed >= 1);
@unlink($fresh);
check('cleanup keeps gitkeep', is_file(APP_ROOT . '/private_storage/uploads/.gitkeep'));

$port = 8097;
$serverCommand = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . '/public'];
$descriptors = [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']];
$server = proc_open($serverCommand, $descriptors, $pipes, $root);
sleep(1);
$base = 'http://127.0.0.1:' . $port;
$jar = $tmp . '/cookies.txt';

function http(string $method, string $url, array $headers = [], ?string $body = null, ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($jar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $raw = is_string($raw) ? $raw : '';
    return ['status' => $status, 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
}

$home = http('GET', $base . '/', [], null, $jar);
check('homepage', $home['status'] === 200 && str_contains($home['body'], 'Everything You Need to Work with PDFs and Documents.'), (string) $home['status']);
check('homepage security header', str_contains($home['headers'], 'X-Content-Type-Options: nosniff'));
preg_match('/name="csrf-token" content="([^"]+)"/', $home['body'], $csrfMatch);
$csrf = $csrfMatch[1] ?? '';
check('csrf meta', $csrf !== '');

$pages = [
    '/tools/enhance-pdf.php' => 'id="browser-process"',
    '/tools/youtube-summarizer.php' => 'id="video-url"',
    '/tools/image-to-editable-text.php' => 'id="run-ocr"',
    '/tools/image-to-pdf-docs.php' => 'id="page-size"',
    '/tools/pdf-editor.php' => 'data-tool="redact"',
    '/tools/unlock-pdf.php' => 'id="unlock"',
    '/tools/todo.php' => 'id="composer"',
    '/legal/privacy.php' => 'Privacy',
    '/legal/terms.php' => 'Terms',
    '/legal/cookies.php' => 'Cookie',
    '/contact.php' => 'id="contact-form"',
    '/about.php' => 'About',
];
foreach ($pages as $path => $needle) {
    $response = http('GET', $base . $path, [], null, $jar);
    check('page ' . $path, $response['status'] === 200 && str_contains($response['body'], $needle), (string) $response['status']);
}

$capsHttp = http('GET', $base . '/api/capabilities.php', ['Accept: application/json'], null, $jar);
$capsJson = json_decode($capsHttp['body'], true);
check('capabilities api', $capsHttp['status'] === 200 && !empty($capsJson['ok']) && !isset($capsJson['ai_api_key']), (string) $capsHttp['status']);

$denied = http('POST', $base . '/api/upload.php', ['X-Requested-With: fetch'], null, $jar);
check('upload without csrf', $denied['status'] === 403, (string) $denied['status'] . ' ' . $denied['body']);

$bad = new CURLFile($tmp . '/not-a-file.txt', 'text/plain', 'notes.txt');
file_put_contents($tmp . '/not-a-file.txt', 'hello plain text');
$ch = curl_init($base . '/api/upload.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $jar,
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_POSTFIELDS => ['csrf' => $csrf, 'kind' => 'any', 'file' => $bad],
]);
$badBody = curl_exec($ch);
$badStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
check('reject text upload', $badStatus === 415, (string) $badStatus . ' ' . $badBody);

$pdfUpload = new CURLFile($tmp . '/plain.pdf', 'application/pdf', 'plain.pdf');
$ch = curl_init($base . '/api/upload.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $jar,
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_POSTFIELDS => ['csrf' => $csrf, 'kind' => 'pdf', 'file' => $pdfUpload],
]);
$upBody = (string) curl_exec($ch);
$upStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
$upJson = json_decode($upBody, true);
check('upload pdf', $upStatus === 200 && !empty($upJson['file']['id']), (string) $upStatus . ' ' . $upBody);

$fileId = (string) ($upJson['file']['id'] ?? '');
$otherJar = $tmp . '/other.txt';
$hidden = http('GET', $base . '/api/download.php?id=' . urlencode($fileId), [], null, $otherJar);
check('download isolation', $hidden['status'] === 404, (string) $hidden['status']);
$traversal = http('GET', $base . '/api/download.php?id=' . urlencode('../etc/passwd'), [], null, $jar);
check('download traversal', $traversal['status'] === 400, (string) $traversal['status']);
$own = http('GET', $base . '/api/download.php?id=' . urlencode($fileId), [], null, $jar);
check('owner download', $own['status'] === 200 && str_starts_with($own['body'], '%PDF-'), (string) $own['status']);

$summaryHttp = http(
    'POST',
    $base . '/api/summarize.php',
    ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf, 'X-Requested-With: fetch'],
    json_encode(['text' => 'Quire stores files briefly. Please email the notes tomorrow.', 'length' => 'short', 'use_ai' => false]),
    $jar
);
$summaryJson = json_decode($summaryHttp['body'], true);
check('summarize api', $summaryHttp['status'] === 200 && ($summaryJson['summary']['method'] ?? '') === 'extractive', (string) $summaryHttp['status'] . ' ' . $summaryHttp['body']);

$badUrl = http(
    'POST',
    $base . '/api/extract-transcript.php',
    ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf, 'X-Requested-With: fetch'],
    json_encode(['url' => 'https://example.com/watch?v=dQw4w9WgXcQ', 'action' => 'metadata']),
    $jar
);
check('reject non-youtube url', $badUrl['status'] === 422, (string) $badUrl['status'] . ' ' . $badUrl['body']);

if ($fileId !== '' && is_file($tmp . '/enc256.pdf')) {
    $encUpload = new CURLFile($tmp . '/enc256.pdf', 'application/pdf', 'enc.pdf');
    $ch = curl_init($base . '/api/upload.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_POSTFIELDS => ['csrf' => $csrf, 'kind' => 'pdf', 'file' => $encUpload],
    ]);
    $encJson = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    $encId = (string) ($encJson['file']['id'] ?? '');
    $inspect = http('GET', $base . '/api/unlock-pdf.php?action=inspect&id=' . urlencode($encId), ['X-Requested-With: fetch'], null, $jar);
    $inspectJson = json_decode($inspect['body'], true);
    check('http inspect encrypted', $inspect['status'] === 200 && !empty($inspectJson['info']['user_password_required']), $inspect['body']);
    $wrong = http(
        'POST',
        $base . '/api/unlock-pdf.php',
        ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf, 'X-Requested-With: fetch'],
        json_encode(['id' => $encId, 'password' => 'wrong', 'authorized' => true]),
        $jar
    );
    check('http bad password', $wrong['status'] === 400 && str_contains($wrong['body'], 'bad_password'), $wrong['body']);
    $right = http(
        'POST',
        $base . '/api/unlock-pdf.php',
        ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf, 'X-Requested-With: fetch'],
        json_encode(['id' => $encId, 'password' => 'secret', 'authorized' => true]),
        $jar
    );
    $rightJson = json_decode($right['body'], true);
    check('http unlock', $right['status'] === 200 && !empty($rightJson['file']['id']), $right['body']);
    $unlocked = http('GET', $base . '/api/download.php?id=' . urlencode((string) ($rightJson['file']['id'] ?? '')), [], null, $jar);
    file_put_contents($tmp . '/http-unlocked.pdf', $unlocked['body']);
    check('http unlocked text', $unlocked['status'] === 200 && str_contains(pdf_text($tmp . '/http-unlocked.pdf'), 'Hello Quire'));
}

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}

fwrite(STDOUT, $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n");
exit($failures === 0 ? 0 : 1);
