<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * T-19: Pruebas de arquitectura (Artículos I, II y III de constitution.md).
 * Comprueba que:
 * - Art. I: El dominio (app/Domain) no depende de nada externo (ni Application, ni Adapters, ni Bootstrap, ni Laravel/Eloquent).
 * - Art. II: La capa de aplicación (app/Application y tests/Unit/Application) solo conoce el dominio y sus propios puertos; nunca importa Adapters ni el framework.
 * - Art. III: Un único punto de composición (app/Bootstrap/Composition/PortBindings.php) es donde se instancian o importan los adaptadores de salida.
 * - Además comprueba que el verificador detecta y falla si se introduce una importación o instanciación prohibida.
 */
final class HexagonalArchitectureTest extends TestCase
{
    private const array FORBIDDEN_IN_DOMAIN = [
        'App\\Application',
        'App\\Adapters',
        'App\\Bootstrap',
        'App\\Http',
        'App\\Models',
        'App\\Services',
        'App\\Console',
        'Illuminate\\',
        'Laravel\\',
        'Symfony\\',
        'Firebase\\',
        'Carbon\\',
        'PDO',
    ];

    private const array FORBIDDEN_IN_APPLICATION = [
        'App\\Adapters',
        'App\\Bootstrap',
        'App\\Http',
        'App\\Models',
        'App\\Services',
        'App\\Console',
        'Illuminate\\',
        'Laravel\\',
        'Symfony\\',
        'Firebase\\',
        'Carbon\\',
        'PDO',
    ];

    public function test_article_i_domain_depends_on_nothing_external(): void
    {
        $domainDir = dirname(__DIR__, 2) . '/app/Domain';
        $files = $this->phpFilesIn($domainDir);

        $this->assertNotEmpty($files, 'Domain directory must contain PHP files.');

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            $violations = self::findForbiddenReferences($code, self::FORBIDDEN_IN_DOMAIN);
            $this->assertSame(
                [],
                $violations,
                sprintf("Constitution Art. I violation in %s: %s", basename($file), implode(', ', $violations))
            );
        }
    }

    public function test_article_ii_application_and_its_unit_tests_do_not_import_adapters_or_framework(): void
    {
        $appDir = dirname(__DIR__, 2) . '/app/Application';
        $appTestDir = dirname(__DIR__, 2) . '/tests/Unit/Application';

        $files = array_merge($this->phpFilesIn($appDir), $this->phpFilesIn($appTestDir));
        $this->assertNotEmpty($files, 'Application directory must contain PHP files.');

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            $violations = self::findForbiddenReferences($code, self::FORBIDDEN_IN_APPLICATION);
            $this->assertSame(
                [],
                $violations,
                sprintf("Constitution Art. II violation in %s: %s", basename($file), implode(', ', $violations))
            );
        }
    }

    public function test_article_iii_single_point_of_composition_for_outbound_adapters(): void
    {
        $appRoot = dirname(__DIR__, 2) . '/app';
        $allAppFiles = $this->phpFilesIn($appRoot);

        $compositionFile = realpath($appRoot . '/Bootstrap/Composition/PortBindings.php');
        $adaptersOutboundDir = realpath($appRoot . '/Adapters/Outbound');

        $this->assertNotFalse($compositionFile, 'PortBindings.php must exist.');
        $this->assertNotFalse($adaptersOutboundDir, 'app/Adapters/Outbound must exist.');

        foreach ($allAppFiles as $file) {
            $real = realpath($file);
            if ($real === false) {
                continue;
            }

            if ($real === $compositionFile || str_starts_with($real, $adaptersOutboundDir)) {
                continue;
            }

            $code = (string) file_get_contents($real);
            $violations = self::findOutboundAdapterUsage($code);
            $this->assertSame(
                [],
                $violations,
                sprintf(
                    "Constitution Art. III violation in %s (outbound adapters may only be referenced in PortBindings.php): %s",
                    basename($real),
                    implode(', ', $violations)
                )
            );
        }
    }

    public function test_architecture_verifier_detects_forbidden_imports_when_introduced(): void
    {
        $badDomainCode = <<<'PHP'
<?php
namespace App\Domain\Entities;
use Illuminate\Support\Facades\DB;
final class BadEntity {}
PHP;
        $this->assertNotEmpty(
            self::findForbiddenReferences($badDomainCode, self::FORBIDDEN_IN_DOMAIN),
            'Verifier must fail when Domain imports Illuminate.'
        );

        $badApplicationCode = <<<'PHP'
<?php
namespace App\Application\Services;
use App\Adapters\Outbound\Persistence\PostgresBookRepository;
final class BadService {}
PHP;
        $this->assertNotEmpty(
            self::findForbiddenReferences($badApplicationCode, self::FORBIDDEN_IN_APPLICATION),
            'Verifier must fail when Application imports App\Adapters.'
        );

        $badControllerCode = <<<'PHP'
<?php
namespace App\Http\Controllers;
use App\Adapters\Outbound\Persistence\PostgresBookRepository;
final class BadController {
    public function index() {
        $repo = new PostgresBookRepository();
    }
}
PHP;
        $this->assertNotEmpty(
            self::findOutboundAdapterUsage($badControllerCode),
            'Verifier must fail when a class outside PortBindings instantiates or imports an Outbound Adapter.'
        );
    }

    /**
     * @param array<int, string> $forbiddenPrefixes
     * @return array<int, string>
     */
    public static function findForbiddenReferences(string $phpCode, array $forbiddenPrefixes): array
    {
        $stripped = self::stripComments($phpCode);
        $violations = [];

        foreach ($forbiddenPrefixes as $prefix) {
            $escaped = preg_quote($prefix, '/');
            if (preg_match('/\buse\s+' . $escaped . '/i', $stripped) === 1
                || preg_match('/\\\\?' . $escaped . '/i', $stripped) === 1
            ) {
                $violations[] = $prefix;
            }
        }

        return $violations;
    }

    /**
     * @return array<int, string>
     */
    public static function findOutboundAdapterUsage(string $phpCode): array
    {
        $stripped = self::stripComments($phpCode);
        $violations = [];

        if (preg_match('/\bApp\\\\Adapters\\\\Outbound\\\\[A-Za-z0-9_\\\\]+/', $stripped, $matches) === 1) {
            $violations[] = $matches[0];
        }

        if (preg_match('/\bnew\s+(Postgres\w+|LocalCoverStorage|JwtTokenIssuer|NativePasswordHasher)\b/', $stripped, $matches) === 1) {
            $violations[] = 'new ' . $matches[1];
        }

        return $violations;
    }

    private static function stripComments(string $phpCode): string
    {
        $tokens = token_get_all($phpCode);
        $output = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $output .= $token[1];
            } else {
                $output .= $token;
            }
        }

        return $output;
    }

    /**
     * @return array<int, string>
     */
    private function phpFilesIn(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
