<?php

use App\Libraries\CollectionPage;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class CollectionPageTest extends CIUnitTestCase
{
    public function testNormalizesPaginationAndBuildsSharedPayload(): void
    {
        $page = CollectionPage::fromQuery(['page' => '3', 'per_page' => '10'], 26);

        $this->assertSame(3, $page->page());
        $this->assertSame(10, $page->perPage());
        $this->assertSame(20, $page->offset());
        $this->assertSame([
            'items' => [['id' => 21]],
            'pagination' => [
                'page' => 3, 'perPage' => 10, 'total' => 26, 'pages' => 3,
                'hasPrevious' => true, 'hasNext' => false,
            ],
        ], $page->payload([['id' => 21]]));
    }

    public function testClampsInvalidAndOversizedInput(): void
    {
        $invalid = CollectionPage::fromQuery(['page' => '-4', 'per_page' => 'nope'], 0);
        $this->assertSame(1, $invalid->page());
        $this->assertSame(20, $invalid->perPage());
        $this->assertSame(0, $invalid->offset());

        $oversized = CollectionPage::fromQuery(['page' => '999', 'per_page' => '999'], 230, 20, 100);
        $this->assertSame(3, $oversized->page());
        $this->assertSame(100, $oversized->perPage());
        $this->assertSame(200, $oversized->offset());
    }
}
