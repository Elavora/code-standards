<?php

declare(strict_types=1);

namespace Elavora\CodeStandards\Tests;

use Elavora\CodeStandards\Composer;
use Elavora\CodeStandards\Exception\MalformedMarkersException;
use Elavora\CodeStandards\Exception\UnmanagedFileException;
use Elavora\CodeStandards\PhpCsFixer;
use Elavora\CodeStandards\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusAndExceptionsTest extends TestCase
{
    public function testStatusIsAnEnumWithTheFourCases(): void
    {
        self::assertTrue((new \ReflectionClass(Status::class))->isEnum());
        self::assertSame(
            ['Created', 'Updated', 'Unchanged', 'Skipped'],
            array_map(static fn(Status $case): string => $case->name, Status::cases()),
        );
    }

    /** @return iterable<string, array{0: class-string}> */
    public static function exceptions(): iterable
    {
        yield 'UnmanagedFileException' => [UnmanagedFileException::class];
        yield 'MalformedMarkersException' => [MalformedMarkersException::class];
    }

    /** @param class-string $class */
    #[DataProvider('exceptions')]
    public function testExceptionIsFinalRuntimeException(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isSubclassOf(\RuntimeException::class));
        self::assertSame('Elavora\\CodeStandards\\Exception', $reflection->getNamespaceName());
    }

    public function testComposerCallbackSignature(): void
    {
        $class = new \ReflectionClass(Composer::class);
        self::assertTrue($class->isFinal());

        $method = $class->getMethod('sync');
        self::assertTrue($method->isPublic());
        self::assertTrue($method->isStatic());
        self::assertSame('void', (string) $method->getReturnType());
        self::assertCount(1, $method->getParameters());
        self::assertSame('object', (string) $method->getParameters()[0]->getType());
    }

    public function testPhpCsFixerClassShape(): void
    {
        $class = new \ReflectionClass(PhpCsFixer::class);
        self::assertTrue($class->isFinal());

        $constructor = $class->getConstructor();
        self::assertNotNull($constructor, 'A classe deveria declarar um construtor privado');
        self::assertTrue($constructor->isPrivate());

        $public = array_values(array_filter(
            $class->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn(\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === PhpCsFixer::class,
        ));
        self::assertSame(['config'], array_map(static fn(\ReflectionMethod $m): string => $m->getName(), $public));

        $config = $class->getMethod('config');
        self::assertTrue($config->isStatic());
        self::assertSame('PhpCsFixer\\ConfigInterface', (string) $config->getReturnType());
        self::assertCount(1, $config->getParameters());
        self::assertSame('string', (string) $config->getParameters()[0]->getType());
    }
}
