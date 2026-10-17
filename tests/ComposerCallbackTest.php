<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests;

use Elavora\CodeStandards\Composer;
use Elavora\CodeStandards\Tests\Support\Expected;
use Elavora\CodeStandards\Tests\Support\FakeEvent;
use Elavora\CodeStandards\Tests\Support\TempDirectories;
use PHPUnit\Framework\TestCase;

final class ComposerCallbackTest extends TestCase
{
    use TempDirectories;

    private string $originalCwd;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
    }

    private function runIn(string $dir, bool $devMode = true): FakeEvent
    {
        $event = new FakeEvent($devMode);
        chdir($dir);
        try {
            Composer::sync($event);
        } finally {
            chdir($this->originalCwd);
        }

        return $event;
    }

    public function testNoDevModeDoesNothing(): void
    {
        $dir = $this->makeTempDir();

        $event = $this->runIn($dir, false);

        self::assertSame([], $this->snapshot($dir));
        self::assertSame([], $event->io->written);
        self::assertSame([], $event->io->errors);
    }

    public function testNoDevModeDoesNothingEvenWithBrokenMarkers(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', Expected::malformed());
        $before = $this->snapshot($dir);

        $event = $this->runIn($dir, false);

        self::assertSame($before, $this->snapshot($dir));
        self::assertSame([], $event->io->written);
        self::assertSame([], $event->io->errors);
    }

    public function testDevModeSynchronizesTheCurrentDirectory(): void
    {
        $dir = $this->makeTempDir();

        $event = $this->runIn($dir);

        self::assertSame([Expected::MSG_EDITORCONFIG_CREATED, Expected::MSG_STUB_CREATED], $event->io->written);
        self::assertSame([], $event->io->errors);
        self::assertSame(Expected::block(), file_get_contents($dir . '/.editorconfig'));
        self::assertSame(Expected::STUB, file_get_contents($dir . '/.php-cs-fixer.dist.php'));
    }

    public function testDoesNotTouchTheOriginalDirectory(): void
    {
        $other = $this->makeTempDir();
        $target = $this->makeTempDir();
        chdir($other);

        $event = new FakeEvent(true);
        chdir($target);
        Composer::sync($event);
        chdir($this->originalCwd);

        self::assertSame([], $this->snapshot($other));
        self::assertFileExists($target . '/.editorconfig');
    }

    public function testUnchangedFiles(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', Expected::block());
        $this->writeFile($dir . '/.php-cs-fixer.dist.php', "<?php\n// ajustado\n");

        $event = $this->runIn($dir);

        self::assertSame([Expected::MSG_EDITORCONFIG_UNCHANGED, Expected::MSG_STUB_KEPT], $event->io->written);
        self::assertSame([], $event->io->errors);
        self::assertSame("<?php\n// ajustado\n", file_get_contents($dir . '/.php-cs-fixer.dist.php'));
    }

    public function testUpdatedEditorconfig(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', "\n");

        $event = $this->runIn($dir);

        self::assertSame([Expected::MSG_EDITORCONFIG_UPDATED, Expected::MSG_STUB_CREATED], $event->io->written);
        self::assertSame([], $event->io->errors);
    }

    public function testUnmanagedEditorconfigWarnsAndKeepsTheFile(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', Expected::UNMANAGED);

        $event = $this->runIn($dir);

        self::assertSame([Expected::MSG_EDITORCONFIG_KEPT], $event->io->errors);
        self::assertSame([Expected::MSG_STUB_CREATED], $event->io->written);
        self::assertSame(Expected::UNMANAGED, file_get_contents($dir . '/.editorconfig'));
    }

    public function testNeverForces(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', Expected::malformed());

        $this->runIn($dir);

        self::assertSame(Expected::malformed(), file_get_contents($dir . '/.editorconfig'));
    }

    public function testBrokenMarkersBecomeAnErrorLineWithoutException(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', Expected::malformed());
        $before = $this->snapshot($dir);

        $event = $this->runIn($dir);

        self::assertSame($before, $this->snapshot($dir), 'Nada deveria ser escrito, nem o stub');
        self::assertSame([], $event->io->written);
        self::assertCount(1, $event->io->errors);
        self::assertStringStartsWith(Expected::MSG_ERROR_PREFIX, $event->io->errors[0]);
        self::assertGreaterThan(\strlen(Expected::MSG_ERROR_PREFIX), \strlen($event->io->errors[0]));
    }

    public function testWriteFailureBecomesAnErrorLineWithoutException(): void
    {
        if (self::isRoot()) {
            self::markTestSkipped('Como root, a falta de permissão de escrita não tem efeito.');
        }
        $dir = $this->makeTempDir();
        chmod($dir, 0o555);

        try {
            $event = $this->runIn($dir);
        } finally {
            chmod($dir, 0o777);
        }

        self::assertCount(1, $event->io->errors);
        self::assertStringStartsWith(Expected::MSG_ERROR_PREFIX, $event->io->errors[0]);
    }

    public function testMessagesHaveNoTrailingNewline(): void
    {
        $dir = $this->makeTempDir();
        $this->writeFile($dir . '/.editorconfig', Expected::UNMANAGED);

        $event = $this->runIn($dir);

        foreach ([...$event->io->written, ...$event->io->errors] as $message) {
            self::assertStringStartsWith(Expected::PREFIX, $message);
            self::assertStringEndsNotWith("\n", $message);
        }
    }
}
