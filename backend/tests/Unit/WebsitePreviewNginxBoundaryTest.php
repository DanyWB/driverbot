<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WebsitePreviewNginxBoundaryTest extends TestCase
{
    public function test_cms_media_location_is_quoted_exact_and_dynamic_not_private_storage_alias(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/deploy/preview/nginx-https.conf.template');
        $headers = array_values(array_filter(explode("\n", $source), fn ($line) => str_contains($line, '/website-media/')));
        $this->assertCount(1, $headers);
        $this->assertSame(1, preg_match('/^\s*location ~ "([^"]+)" \{$/D', $headers[0], $matches), 'Quantifier braces must remain inside the quoted Nginx regex.');
        $pattern = '~'.$matches[1].'~D';
        $uri = '/website-media/12345678-1234-1234-1234-123456789abc/card/'.str_repeat('a', 64).'.webp';
        $this->assertSame(1, preg_match($pattern, $uri));
        foreach ([str_replace('/card/', '/original/', $uri), str_replace('website-media', 'website/media', $uri), str_replace('/card/', '/card/../', $uri),
            str_replace('.webp', '.php', $uri), str_replace('/card/', '/card_3x/', $uri), '/website-media/../private/file.webp'] as $invalid) {
            $this->assertSame(0, preg_match($pattern, $invalid));
        }
        $start = strpos($source, $headers[0]);
        $end = strpos($source, "\n    }", $start);
        $body = substr($source, $start, $end - $start);
        $this->assertStringContainsString('try_files __no_static_preview_media__ @preview_php;', $body);
        $this->assertStringNotContainsString('alias', $body);
        $this->assertStringNotContainsString('/storage/app/private', $body);
        $this->assertStringContainsString('location / { return 404; }', $source);
    }
}
