<?php

declare(strict_types=1);

namespace Parisek\Twig\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Runs scripts/check-upstream.php against a copy of the real upstream files
 * (tests/fixtures/upstream-watch/upstream, a snapshot of Drupal 11.x) and
 * against mutated copies. No test touches the network.
 */
final class UpstreamCheckTest extends TestCase
{
    private const NAMES = ['Attribute', 'AttributeArray', 'AttributeBoolean', 'AttributeString', 'AttributeValueBase'];

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/upstream-check-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/upstream', 0777, true);
        foreach (self::NAMES as $name) {
            copy(__DIR__ . "/fixtures/upstream-watch/upstream/{$name}.php.txt", $this->tmp . "/upstream/{$name}.php");
        }
    }

    protected function tearDown(): void
    {
        $this->remove($this->tmp);
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);

            return;
        }
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function check(array $args = [], bool $commits = false): array
    {
        $command = [PHP_BINARY, dirname(__DIR__) . '/scripts/check-upstream.php', '--upstream=' . $this->tmp . '/upstream'];
        if (!$commits) {
            $command[] = '--commits=none';
        }
        $process = proc_open(array_merge($command, $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function mutate(string $name, string $search, string $replace): void
    {
        $file = $this->tmp . "/upstream/{$name}.php";
        $code = (string) file_get_contents($file);
        self::assertStringContainsString($search, $code, 'The mutation anchor must exist in the upstream snapshot.');
        file_put_contents($file, str_replace($search, $replace, $code));
    }

    /**
     * @param list<array{id: string, title: string, date: string}> $list
     */
    private function commits(array $list): string
    {
        $dir = $this->tmp . '/commits';
        @mkdir($dir);
        $rows = array_map(static fn(array $c): array => [
            'id' => $c['id'],
            'short_id' => substr($c['id'], 0, 10),
            'title' => $c['title'],
            'committed_date' => $c['date'],
            'web_url' => 'https://example.invalid/' . $c['id'],
        ], $list);
        foreach (self::NAMES as $name) {
            file_put_contents("{$dir}/{$name}.json", json_encode($name === 'Attribute' ? $rows : [], JSON_THROW_ON_ERROR));
        }

        return $dir;
    }

    private function reviewed(string $id, string $date): string
    {
        $file = $this->tmp . '/reviewed.json';
        file_put_contents($file, json_encode(['commit' => $id, 'date' => $date, 'reviewed_by' => 'test'], JSON_THROW_ON_ERROR));

        return $file;
    }

    public function testIdenticalUpstreamHasNoDrift(): void
    {
        [$code, $out] = $this->check();

        self::assertSame(0, $code, $out);
        self::assertStringNotContainsString('```diff', $out);
    }

    public function testCommentAndWhitespaceChangesAreIgnored(): void
    {
        $this->mutate('AttributeString', 'return Html::escape((string) $this->value);', "// A new comment.\n    return   Html::escape( (string)\$this->value );   /* and another */");
        $this->mutate('Attribute', ' * Collects, sanitizes, and renders HTML attributes.', ' * Collects and renders HTML attributes, reworded.');

        [$code, $out] = $this->check();

        self::assertSame(0, $code, $out);
    }

    public function testChangedConditionIsDrift(): void
    {
        $this->mutate('AttributeBoolean', '$this->value === FALSE', '$this->value == FALSE');

        [$code, $out] = $this->check();

        self::assertSame(1, $code);
        self::assertStringContainsString('```diff', $out);
        self::assertStringContainsString('-return $this->value == FALSE', $out);
        self::assertStringContainsString('+return $this->value === FALSE', $out);
    }

    public function testNewStatementIsDrift(): void
    {
        $this->mutate('AttributeValueBase', '$this->value = $value;', '$this->value = $value; error_log("x");');

        [$code, $out] = $this->check();

        self::assertSame(1, $code);
        self::assertStringContainsString('error_log', $out);
    }

    public function testSecurityStyleGuardIsDrift(): void
    {
        $this->mutate('AttributeValueBase', '$value = (string) $this;', 'if (!$this instanceof AttributeValueBase) { throw new \RuntimeException("x"); } $value = (string) $this;');

        [$code, $out] = $this->check();

        self::assertSame(1, $code);
        self::assertStringContainsString('instanceof AttributeValueBase', $out);
        self::assertStringContainsString('AttributeValueBase.php', $out);
    }

    public function testMissingUpstreamFileIsAnError(): void
    {
        unlink($this->tmp . '/upstream/AttributeArray.php');

        [$code, , $err] = $this->check();

        self::assertSame(2, $code);
        self::assertStringContainsString('AttributeArray', $err);
    }

    public function testUnreachableUrlIsAnError(): void
    {
        [$code, , $err] = $this->check(['--upstream=http://127.0.0.1:9/nothing']);

        self::assertSame(2, $code);
        self::assertNotSame('', $err);
    }

    public function testUnparsableUpstreamIsAnError(): void
    {
        file_put_contents($this->tmp . '/upstream/AttributeString.php', '<?php class {');

        [$code] = $this->check();

        self::assertSame(2, $code);
    }

    public function testSecurityCommitSinceLastReviewIsFlagged(): void
    {
        $dir = $this->commits([
            ['id' => str_repeat('c', 40), 'title' => 'SA-CORE-2099-001 by someone: Fix', 'date' => '2099-02-01T10:00:00.000+00:00'],
            ['id' => str_repeat('b', 40), 'title' => 'docs: Typo', 'date' => '2099-01-01T10:00:00.000+00:00'],
            ['id' => str_repeat('a', 40), 'title' => 'Old', 'date' => '2098-01-01T10:00:00.000+00:00'],
        ]);

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $this->reviewed(str_repeat('a', 40), '2098-01-01T10:00:00.000+00:00')], true);

        self::assertSame(1, $code);
        self::assertStringContainsString('SA-CORE-2099-001', $out);
        self::assertStringContainsString('docs: Typo', $out);
        self::assertStringNotContainsString('Old', $out);
    }

    public function testPlainNewCommitIsReportedWithoutFailing(): void
    {
        $dir = $this->commits([
            ['id' => str_repeat('b', 40), 'title' => 'docs: Typo', 'date' => '2099-01-01T10:00:00.000+00:00'],
            ['id' => str_repeat('a', 40), 'title' => 'Old', 'date' => '2098-01-01T10:00:00.000+00:00'],
        ]);

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $this->reviewed(str_repeat('a', 40), '2098-01-01T10:00:00.000+00:00')], true);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('docs: Typo', $out);
    }

    public function testReviewedCommitSilencesOlderSecurityCommit(): void
    {
        $dir = $this->commits([
            ['id' => str_repeat('c', 40), 'title' => 'SA-CORE-2099-001 Fix', 'date' => '2099-02-01T10:00:00.000+00:00'],
        ]);

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $this->reviewed(str_repeat('c', 40), '2099-02-01T10:00:00.000+00:00')], true);

        self::assertSame(0, $code, $out);
        self::assertStringNotContainsString('SA-CORE', $out);
    }

    public function testMarkReviewedWritesNewestCommit(): void
    {
        $dir = $this->commits([
            ['id' => str_repeat('c', 40), 'title' => 'SA-CORE-2099-001 Fix', 'date' => '2099-02-01T10:00:00.000+00:00'],
            ['id' => str_repeat('b', 40), 'title' => 'docs: Typo', 'date' => '2099-01-01T10:00:00.000+00:00'],
        ]);
        $file = $this->tmp . '/reviewed.json';

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $file, '--mark-reviewed=Test Person'], true);

        self::assertSame(0, $code, $out);
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(str_repeat('c', 40), $data['commit']);
        self::assertSame('2099-02-01T10:00:00.000+00:00', $data['date']);
        self::assertSame('Test Person', $data['reviewed_by']);
    }

    public function testMarkReviewedRefusesWhileDriftExists(): void
    {
        $this->mutate('AttributeBoolean', '$this->value === FALSE', '$this->value == FALSE');
        $file = $this->tmp . '/reviewed.json';

        [$code] = $this->check(['--commits=' . $this->commits([]), '--reviewed=' . $file, '--mark-reviewed=Test Person'], true);

        self::assertSame(1, $code);
        self::assertFileDoesNotExist($file);
    }

    public function testTheTrackedReviewedFileIsValid(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__) . '/.upstream-reviewed'), true, 512, JSON_THROW_ON_ERROR);

        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $data['commit']);
        self::assertNotFalse(strtotime($data['date']));
        self::assertNotSame('', $data['reviewed_by']);
    }

    public function testCurrentSourcesMatchTheSnapshot(): void
    {
        // The snapshot is the upstream version `src/` was last refreshed from.
        // This pins the real normalizer to the real files: a refresh of `src/`
        // without a new snapshot (or the reverse) turns this red.
        [$code, $out] = $this->check(['--src=' . dirname(__DIR__) . '/src']);

        self::assertSame(0, $code, $out);
    }
}
