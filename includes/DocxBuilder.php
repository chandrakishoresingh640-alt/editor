<?php
declare(strict_types=1);

final class DocxBuilder
{
    /**
     * @param array<int,array{text:string,size?:float}> $blocks
     */
    public static function fromBlocks(array $blocks): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new AppException('DOCX export needs the PHP zip extension, which is not enabled on this server.', 500, 'zip');
        }
        if ($blocks === []) {
            $blocks = [['text' => '']];
        }
        $paragraphs = '';
        foreach ($blocks as $block) {
            $size = (int) round(((float) ($block['size'] ?? 12)) * 2);
            $size = max(8, min(144, $size));
            $text = self::xml((string) ($block['text'] ?? ''));
            $paragraphs .= '<w:p><w:pPr><w:spacing w:after="160"/></w:pPr><w:r><w:rPr><w:sz w:val="'
                . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr><w:t xml:space="preserve">'
                . $text . '</w:t></w:r></w:p>';
        }
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . $paragraphs
            . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr>'
            . '</w:body></w:document>';
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';
        $zipPath = tempnam(sys_get_temp_dir(), 'docx');
        if ($zipPath === false) {
            throw new AppException('A DOCX file could not be created.', 500, 'docx');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            throw new AppException('A DOCX file could not be created.', 500, 'docx');
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $document);
        $zip->close();
        $bytes = (string) file_get_contents($zipPath);
        @unlink($zipPath);
        return $bytes;
    }

    private static function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
