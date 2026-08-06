<?php

use App\HTTP\RangeFileResponse;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class RangeFileResponseTest extends CIUnitTestCase
{
    public function testPartialResponseStreamsOnlyTheRequestedBytes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cms-range-');
        file_put_contents($file, '0123456789');
        try {
            $response = new RangeFileResponse($file, 2, 5, 'Film.mp4', 'video/mp4', '"etag"', true);
            ob_start();
            $response->sendBody();
            $body = ob_get_clean();
            $this->assertSame('2345', $body);
            $this->assertSame(206, $response->getStatusCode());
            $this->assertSame('bytes 2-5/10', $response->getHeaderLine('Content-Range'));
            $this->assertSame('4', $response->getHeaderLine('Content-Length'));
            $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        } finally {
            if (is_file($file)) unlink($file);
        }
    }
}
