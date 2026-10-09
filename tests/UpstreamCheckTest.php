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

    /** Id of the single commit that the other four files have. */
    private function baseId(string $name): string
    {
        return str_repeat((string) array_search($name, self::NAMES, true), 40);
    }

    /**
     * Writes the commit lists. `Attribute` gets $list as page 1 and $morePages as pages 2, 3, ... (newest first).
     * The other files each have one commit that `reviewed()` records as their boundary.
     *
     * @param list<array{id: string, title: string, date: string}> $list
     * @param list<list<array{id: string, title: string, date: string}>> $morePages
     */
    private function commits(array $list, array $morePages = []): string
    {
        $dir = $this->tmp . '/commits';
        @mkdir($dir);
        $rows = static fn(array $page): string => json_encode(array_map(static fn(array $c): array => [
            'id' => $c['id'],
            'short_id' => substr($c['id'], 0, 10),
            'title' => $c['title'],
            'committed_date' => $c['date'],
            'web_url' => 'https://example.invalid/' . $c['id'],
        ], $page), JSON_THROW_ON_ERROR);
        foreach (self::NAMES as $name) {
            if ($name === 'Attribute') {
                file_put_contents("{$dir}/Attribute.json", $rows($list));
                foreach ($morePages as $i => $page) {
                    file_put_contents($dir . '/Attribute.' . ($i + 2) . '.json', $rows($page));
                }

                continue;
            }
            file_put_contents("{$dir}/{$name}.json", $rows([['id' => $this->baseId($name), 'title' => 'base', 'date' => '2000-01-01T00:00:00.000+00:00']]));
        }

        return $dir;
    }

    private function commit(string $char, string $title = 'docs: change', string $date = '2099-01-01T10:00:00.000+00:00'): array
    {
        return ['id' => str_repeat($char, 40), 'title' => $title, 'date' => $date];
    }

    /** Records `$attributeCommit` as the reviewed boundary of Attribute.php. */
    private function reviewed(string $attributeCommit): string
    {
        $files = [];
        foreach (self::NAMES as $name) {
            $files[$name] = $name === 'Attribute' ? $attributeCommit : $this->baseId($name);
        }
        $file = $this->tmp . '/reviewed.json';
        file_put_contents($file, json_encode(['commit' => $attributeCommit, 'date' => '2098-01-01T10:00:00.000+00:00', 'reviewed_by' => 'test', 'files' => $files], JSON_THROW_ON_ERROR));

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

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $this->reviewed(str_repeat('a', 40))], true);

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

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $this->reviewed(str_repeat('a', 40))], true);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('docs: Typo', $out);
    }

    public function testReviewedCommitSilencesOlderSecurityCommit(): void
    {
        $dir = $this->commits([
            ['id' => str_repeat('c', 40), 'title' => 'SA-CORE-2099-001 Fix', 'date' => '2099-02-01T10:00:00.000+00:00'],
        ]);

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $this->reviewed(str_repeat('c', 40))], true);

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

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $file, '--mark-reviewed=Test Person', '--ack=' . str_repeat('c', 12)], true);

        self::assertSame(0, $code, $out);
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(str_repeat('c', 40), $data['commit']);
        self::assertSame('2099-02-01T10:00:00.000+00:00', $data['date']);
        self::assertSame('Test Person', $data['reviewed_by']);
        self::assertSame(str_repeat('c', 40), $data['files']['Attribute']);
        self::assertSame($this->baseId('AttributeArray'), $data['files']['AttributeArray']);
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
        self::assertSame(array_keys($data['files']), self::NAMES);
        foreach ($data['files'] as $id) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $id);
        }
    }

    public function testCurrentSourcesMatchTheSnapshot(): void
    {
        // The snapshot is the upstream version `src/` was last refreshed from.
        // This pins the real normalizer to the real files: a refresh of `src/`
        // without a new snapshot (or the reverse) turns this red.
        [$code, $out] = $this->check(['--src=' . dirname(__DIR__) . '/src']);

        self::assertSame(0, $code, $out);
    }

    public function testBoundaryOnALaterPageIsFound(): void
    {
        $dir = $this->commits(
            [$this->commit('f', 'SA-CORE-2099-009 Fix')],
            [[$this->commit('e')], [$this->commit('a')]],
        );

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $this->reviewed(str_repeat('a', 40))], true);

        self::assertSame(1, $code);
        self::assertStringContainsString('SA-CORE-2099-009', $out);
        self::assertStringContainsString('docs: change', $out);
    }

    public function testBoundaryNotFoundWithinThePageCapIsReported(): void
    {
        $pages = [];
        for ($i = 0; $i < 25; $i++) {
            $pages[] = [$this->commit('d', 'docs: page ' . $i)];
        }
        $dir = $this->commits([$this->commit('f')], $pages);

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $this->reviewed(str_repeat('a', 40))], true);

        self::assertSame(1, $code);
        self::assertStringContainsString('Review boundary lost', $out);
        self::assertStringContainsString('Attribute.php', $out);
    }

    public function testBoundaryMissingFromTheListIsReported(): void
    {
        $dir = $this->commits([$this->commit('f')]);

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $this->reviewed(str_repeat('a', 40))], true);

        self::assertSame(1, $code);
        self::assertStringContainsString('Review boundary lost', $out);
    }

    public function testSecurityCommitWithAnOldTimestampIsStillReported(): void
    {
        // A cherry-pick keeps an old author date. The boundary is the commit id, not a date.
        $dir = $this->commits([
            $this->commit('c', 'SA-CORE-2099-002 Backport', '1999-01-01T00:00:00.000+00:00'),
            $this->commit('a', 'Reviewed', '2098-01-01T10:00:00.000+00:00'),
        ]);

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $this->reviewed(str_repeat('a', 40))], true);

        self::assertSame(1, $code);
        self::assertStringContainsString('SA-CORE-2099-002', $out);
    }

    public function testHostileAndOversizedTitlesAreContained(): void
    {
        $hostile = "x</details>\n# Injected\n@someone <script>alert(1)</script>" . str_repeat('A', 5000) . "\x01";
        $list = [$this->commit('b', $hostile)];
        for ($i = 0; $i < 120; $i++) {
            $list[] = ['id' => sprintf('%040x', 0x1000 + $i), 'title' => 'docs: ' . $i, 'date' => '2099-01-01T10:00:00.000+00:00'];
        }
        $list[] = $this->commit('a');
        $dir = $this->commits($list);

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $this->reviewed(str_repeat('a', 40))], true);

        self::assertSame(0, $code);
        self::assertStringNotContainsString('<script>', $out);
        self::assertStringNotContainsString("\n# Injected", $out);
        self::assertStringNotContainsString("\x01", $out);
        self::assertStringNotContainsString(str_repeat('A', 300), $out);
        self::assertStringContainsString('more commit', $out);
        self::assertLessThan(60000, strlen($out));
    }

    public function testTheReportStaysBelowTheSizeLimit(): void
    {
        // Every file differs in many lines: the diffs alone would be far above the limit.
        foreach (self::NAMES as $name) {
            $file = $this->tmp . "/upstream/{$name}.php";
            $code = (string) file_get_contents($file);
            $extra = '';
            for ($i = 0; $i < 3000; $i++) {
                $extra .= "\$x{$i} = {$i};\n";
            }
            file_put_contents($file, preg_replace('/(function [^\n]*\{\n)/', '$1' . $extra, $code, 1));
        }

        [$code, $out] = $this->check();

        self::assertSame(1, $code);
        self::assertLessThanOrEqual(60000, strlen($out));
        self::assertStringContainsString('cut', $out);
    }

    public function testUpstreamUrlOutsideDrupalCodeIsRefused(): void
    {
        foreach (['https://example.com/x', 'http://git.drupalcode.org/x', 'https://git.drupalcode.org.evil.example/x', 'https://git.drupalcode.org@evil.example/x'] as $url) {
            [$code, , $err] = $this->check(['--upstream=' . $url]);

            self::assertSame(2, $code, $url);
            self::assertStringContainsString('not allowed', $err, $url);
        }
    }

    /**
     * @return array{resource, int}|null
     */
    private function server(): ?array
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            return null;
        }
        $port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
        fclose($probe);
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $this->tmp . '/upstream', __DIR__ . '/fixtures/upstream-watch/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return null;
        }
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return [$process, $port];
            }
            usleep(100000);
        }
        proc_terminate($process);

        return null;
    }

    public function testRedirectsAndOversizedAnswersAreRefused(): void
    {
        $server = $this->server();
        if ($server === null) {
            self::markTestSkipped('Cannot start the PHP built-in server.');
        }
        [$process, $port] = $server;
        try {
            $base = "http://127.0.0.1:{$port}";

            [$code, $out] = $this->check(['--allow=' . $base . '/', '--upstream=' . $base . '/ok']);
            self::assertSame(0, $code, 'Control: the fake host works.' . $out);

            [$code, , $err] = $this->check(['--allow=' . $base . '/', '--upstream=' . $base . '/redirect']);
            self::assertSame(2, $code, 'A redirect must fail.');
            self::assertStringContainsString('HTTP status 302', $err);

            [$code, , $err] = $this->check(['--allow=' . $base . '/', '--upstream=' . $base . '/big']);
            self::assertSame(2, $code, 'An oversized answer must fail.');
            self::assertStringContainsString('too large', $err);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testMarkReviewedRefusesWithAPendingSecurityCommit(): void
    {
        $dir = $this->commits([$this->commit('c', 'SA-CORE-2099-003 Fix'), $this->commit('b'), $this->commit('a')]);
        $file = $this->reviewed(str_repeat('a', 40));
        $before = (string) file_get_contents($file);

        [$code, , $err] = $this->check(['--commits=' . $dir, '--reviewed=' . $file, '--mark-reviewed=Test Person'], true);

        self::assertSame(1, $code);
        self::assertStringContainsString('SA-CORE', $err);
        self::assertSame($before, (string) file_get_contents($file), 'The file must stay unchanged.');
    }

    public function testMarkReviewedAcceptsAnAcknowledgedSecurityCommit(): void
    {
        $dir = $this->commits([$this->commit('c', 'SA-CORE-2099-003 Fix'), $this->commit('b'), $this->commit('a')]);
        $file = $this->reviewed(str_repeat('a', 40));

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $file, '--mark-reviewed=Test Person', '--ack=' . str_repeat('c', 12)], true);

        self::assertSame(0, $code, $out);
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(str_repeat('c', 40), $data['commit']);
        self::assertSame([str_repeat('c', 40)], $data['acknowledged']);
        self::assertSame('Test Person', $data['reviewed_by']);
    }

    public function testMarkReviewedRefusesAWrongOrShortAck(): void
    {
        $dir = $this->commits([$this->commit('c', 'SA-CORE-2099-003 Fix'), $this->commit('a')]);
        $file = $this->reviewed(str_repeat('a', 40));
        $before = (string) file_get_contents($file);

        [$code] = $this->check(['--commits=' . $dir, '--reviewed=' . $file, '--mark-reviewed=T', '--ack=' . str_repeat('b', 12)], true);
        self::assertSame(1, $code);

        [$code] = $this->check(['--commits=' . $dir, '--reviewed=' . $file, '--mark-reviewed=T', '--ack=ccc'], true);
        self::assertSame(2, $code, 'A short prefix is an error.');
        self::assertSame($before, (string) file_get_contents($file));
    }

    public function testMarkReviewedRefusesALostBoundaryUnlessReset(): void
    {
        $dir = $this->commits([$this->commit('f')]);
        $file = $this->reviewed(str_repeat('a', 40));
        $before = (string) file_get_contents($file);

        [$code, , $err] = $this->check(['--commits=' . $dir, '--reviewed=' . $file, '--mark-reviewed=T'], true);
        self::assertSame(1, $code);
        self::assertStringContainsString('boundary', $err);
        self::assertSame($before, (string) file_get_contents($file));

        [$code, $out, $err] = $this->check(['--commits=' . $dir, '--reviewed=' . $file, '--mark-reviewed=T', '--reset-boundary'], true);
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('WARNING', $err);
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['boundary_reset']);
        self::assertSame(str_repeat('f', 40), $data['files']['Attribute']);
    }

    public function testHardTruncationClosesOpenBlocks(): void
    {
        $this->mutate('AttributeValueBase', '$this->value = $value;', '$this->value = $value . \'' . str_repeat('A', 80000) . '\';');

        [$code, $out] = $this->check();

        self::assertSame(1, $code);
        self::assertLessThanOrEqual(60000, strlen($out));
        self::assertSame(1, preg_match('//u', $out));
        self::assertStringContainsString('Report truncated', $out);
        self::assertSame(substr_count($out, '<details>'), substr_count($out, '</details>'));
        self::assertSame(0, preg_match_all('/^```/m', $out) % 2, 'Every code fence must be closed.');
    }

    public function testAllowIsLimitedToLoopback(): void
    {
        foreach (['https://evil.example/', 'http://evil.example/', 'http://127.0.0.1.evil.example:80/', 'file:///etc/'] as $prefix) {
            [$code, , $err] = $this->check(['--allow=' . $prefix]);

            self::assertSame(2, $code, $prefix);
            self::assertStringContainsString('--allow', $err);
        }
    }

    public function testTheWorkflowNeverPassesTheTestHook(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/upstream-watch.yml');

        self::assertStringNotContainsString('--allow', $workflow);
        self::assertStringNotContainsString('--reset-boundary', $workflow);
        self::assertStringContainsString('--app github-actions', $workflow);
        self::assertStringContainsString('--arg title', $workflow);
    }

    public function testMarkReviewedWithoutAMarkerStillNeedsTheSecurityAck(): void
    {
        $dir = $this->commits([$this->commit('c', 'SA-CORE-2099-004 Fix'), $this->commit('b')]);
        $file = $this->tmp . '/new-marker.json';

        [$code, , $err] = $this->check(['--commits=' . $dir, '--reviewed=' . $file, '--mark-reviewed=T'], true);
        self::assertSame(1, $code);
        self::assertStringContainsString('SA-CORE', $err);
        self::assertFileDoesNotExist($file, 'Deleting the marker must not bypass the check.');

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $file, '--mark-reviewed=T', '--ack=' . strtoupper(str_repeat('c', 12))], true);
        self::assertSame(0, $code, $out);
        self::assertFileExists($file);
    }

    public function testFirstBaselineOnACleanTreeNeedsNoAck(): void
    {
        $dir = $this->commits([$this->commit('b'), $this->commit('a')]);
        $file = $this->tmp . '/new-marker.json';

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $file, '--mark-reviewed=T'], true);

        self::assertSame(0, $code, $out);
        self::assertFileExists($file);
    }

    public function testAnAckThatMatchesNothingIsWarnedAbout(): void
    {
        $dir = $this->commits([$this->commit('b'), $this->commit('a')]);

        [$code, , $err] = $this->check(['--commits=' . $dir, '--reviewed=' . $this->reviewed(str_repeat('a', 40)), '--mark-reviewed=T', '--ack=' . str_repeat('e', 12)], true);

        self::assertSame(0, $code);
        self::assertStringContainsString('matches no SA-CORE commit', $err);
    }

    public function testAnAmbiguousAckIsRefused(): void
    {
        $one = 'abcdef012345' . str_repeat('1', 28);
        $two = 'abcdef012345' . str_repeat('2', 28);
        $dir = $this->commits([
            ['id' => $one, 'title' => 'SA-CORE-2099-005 One', 'date' => '2099-01-02T00:00:00.000+00:00'],
            ['id' => $two, 'title' => 'SA-CORE-2099-006 Two', 'date' => '2099-01-01T00:00:00.000+00:00'],
            $this->commit('a'),
        ]);
        $file = $this->reviewed(str_repeat('a', 40));
        $before = (string) file_get_contents($file);

        [$code, , $err] = $this->check(['--commits=' . $dir, '--reviewed=' . $file, '--mark-reviewed=T', '--ack=abcdef012345'], true);

        self::assertSame(1, $code);
        self::assertStringContainsString('ambiguous', $err);
        self::assertSame($before, (string) file_get_contents($file));
    }

    public function testAFailingWriteLeavesThePreviousMarkerIntact(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Root ignores directory permissions.');
        }
        $dir = $this->commits([$this->commit('b'), $this->commit('a')]);
        mkdir($this->tmp . '/locked');
        $file = $this->tmp . '/locked/reviewed.json';
        copy($this->reviewed(str_repeat('a', 40)), $file);
        $before = (string) file_get_contents($file);
        chmod($this->tmp . '/locked', 0555);
        try {
            [$code, , $err] = $this->check(['--commits=' . $dir, '--reviewed=' . $file, '--mark-reviewed=T'], true);
        } finally {
            chmod($this->tmp . '/locked', 0755);
        }

        self::assertSame(2, $code);
        self::assertNotSame('', $err);
        self::assertSame($before, (string) file_get_contents($file));
        self::assertSame(['reviewed.json'], array_values(array_diff(scandir($this->tmp . '/locked') ?: [], ['.', '..'])), 'No temp file may stay behind.');
    }

    public function testNoTempFileStaysAfterASuccessfulWrite(): void
    {
        $dir = $this->commits([$this->commit('b'), $this->commit('a')]);
        mkdir($this->tmp . '/out');
        $file = $this->tmp . '/out/reviewed.json';

        [$code, $out] = $this->check(['--commits=' . $dir, '--reviewed=' . $file, '--mark-reviewed=T'], true);

        self::assertSame(0, $code, $out);
        self::assertSame(['reviewed.json'], array_values(array_diff(scandir($this->tmp . '/out') ?: [], ['.', '..'])));
        self::assertSame(0644, fileperms($file) & 0777);
    }

    public function testTruncationHoldsTheLimitForRandomDocuments(): void
    {
        require_once dirname(__DIR__) . '/scripts/check-upstream.php';

        $words = ['abc', 'žluťoučký', '日本語のテキスト', "emoji \u{1F600}", str_repeat('x', 900)];
        for ($seed = 0; $seed < 400; $seed++) {
            mt_srand($seed);
            $eol = $seed % 2 === 0 ? "\r\n" : "\n";
            $lines = [];
            $open = 0;
            $fence = null;
            $size = 0;
            while ($size < 65000 + mt_rand(0, 3000)) {
                $roll = mt_rand(0, 11);
                $text = static function () use ($words): string {
                    $line = '';
                    for ($w = mt_rand(1, 12); $w > 0; $w--) {
                        $line .= $words[mt_rand(0, 4)] . ' ';
                    }

                    return $line;
                };
                if ($fence === null) {
                    if ($roll === 0 && $open < 20) {
                        $line = '<details>';
                        $open++;
                    } elseif ($roll === 1 && $open > 0) {
                        $line = '</details>';
                        $open--;
                    } elseif ($roll <= 4) {
                        $fence = [mt_rand(0, 1) === 0 ? '`' : '~', mt_rand(3, 6)];
                        $line = str_repeat(' ', mt_rand(0, 3)) . str_repeat($fence[0], $fence[1]) . 'diff';
                    } else {
                        $line = $text();
                    }
                } elseif ($roll <= 2) {
                    $line = str_repeat(' ', mt_rand(0, 3)) . str_repeat($fence[0], $fence[1] + mt_rand(0, 2));
                    $fence = null;
                } elseif ($roll === 3) {
                    $line = '<details>';
                } elseif ($roll === 4) {
                    $line = '</details>';
                } elseif ($roll === 5) {
                    $line = str_repeat($fence[0], $fence[1] - 1) . ' short run';
                } elseif ($roll === 6) {
                    $line = str_repeat($fence[0] === '`' ? '~' : '`', 3 + mt_rand(0, 3));
                } else {
                    $line = $text();
                }
                $lines[] = $line;
                $size += strlen($line) + strlen($eol);
            }
            $report = implode($eol, $lines) . $eol;
            self::assertGreaterThan(60000, strlen($report));

            $result = \UpstreamCheck::truncate($report);

            self::assertLessThanOrEqual(60000, strlen($result), "seed {$seed}");
            self::assertSame(1, preg_match('//u', $result), "seed {$seed}");
            self::assertStringContainsString('Report truncated', $result);
            [$endFence, $endDetails] = $this->markdownState($result);
            self::assertNull($endFence, "seed {$seed}: open fence");
            self::assertSame(0, $endDetails, "seed {$seed}: open details");
        }
    }

    /**
     * An independent reader of the Markdown subset that the report uses.
     *
     * @return array{array{string, int}|null, int} Open fence (char, length) and open <details> count at the end.
     */
    private function markdownState(string $text): array
    {
        $fence = null;
        $details = 0;
        foreach (explode("\n", $text) as $line) {
            $line = rtrim($line, "\r");
            if ($fence === null) {
                if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                    $fence = [$m[1][0], strlen($m[1])];
                } elseif ($line === '<details>') {
                    $details++;
                } elseif ($line === '</details>') {
                    $details--;
                }
            } elseif (preg_match('/^ {0,3}(' . ($fence[0] === '`' ? '`' : '~') . '{' . $fence[1] . ',})\s*$/', $line) === 1) {
                $fence = null;
            }
        }

        return [$fence, $details];
    }

    public function testTruncationClosesATildeFenceWithTheSameMarker(): void
    {
        require_once dirname(__DIR__) . '/scripts/check-upstream.php';
        $report = "<details>\n~~~~diff\n" . str_repeat("line <details>\n", 8000);

        $result = \UpstreamCheck::truncate($report);

        self::assertLessThanOrEqual(60000, strlen($result));
        self::assertStringContainsString("\n~~~~\n</details>\n", $result);
        self::assertSame([null, 0], $this->markdownState($result));
    }

    /**
     * @return array{string, string} The check step body and a runner for it.
     */
    private function checkStep(): array
    {
        $workflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/upstream-watch.yml');
        self::assertSame(1, preg_match('/- name: Check upstream\n(?:.*\n)*?        run: \|\n((?:          .*\n|\n)+)/', $workflow, $m));
        $body = (string) preg_replace('/^ {10}/m', '', $m[1]);
        $script = $this->tmp . '/step.sh';
        file_put_contents($script, $body);
        is_dir($this->tmp . '/fakebin') || mkdir($this->tmp . '/fakebin');
        file_put_contents($this->tmp . '/fakebin/php', "#!/bin/sh\n[ -n \"\$FAKE_REPORT\" ] && printf '%s\\n' \"\$FAKE_REPORT\"\nexit \"\$FAKE_CODE\"\n");
        chmod($this->tmp . '/fakebin/php', 0755);

        return [$script, $this->tmp . '/fakebin'];
    }

    /**
     * @return array{int, string}
     */
    private function runStep(string $code, string $report, ?string $output = null): array
    {
        [$script, $bin] = $this->checkStep();
        $output ??= $this->tmp . '/github-output';
        @unlink($output);
        $process = proc_open(['bash', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), [
            'PATH' => $bin . ':' . getenv('PATH'),
            'RUNNER_TEMP' => $this->tmp,
            'GITHUB_OUTPUT' => $output,
            'FAKE_CODE' => $code,
            'FAKE_REPORT' => $report,
        ]);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return [proc_close($process), is_file($output) ? (string) file_get_contents($output) : ''];
    }

    public function testTheCheckStepPassesOnlyExitCodesZeroAndOne(): void
    {
        [$status, $out] = $this->runStep('0', 'No functional difference.');
        self::assertSame(0, $status);
        self::assertStringContainsString("code=0\n", $out);

        [$status, $out] = $this->runStep('1', 'Report body line');
        self::assertSame(0, $status);
        self::assertStringContainsString("code=1\n", $out);
        self::assertStringContainsString('Report body line', $out);

        foreach (['2', '255', '137'] as $code) {
            [$status] = $this->runStep($code, 'x');
            self::assertNotSame(0, $status, "A script exit code of {$code} must fail the step.");
        }

        [$status] = $this->runStep('1', '');
        self::assertNotSame(0, $status, 'Code 1 with an empty report must fail the step.');
    }

    public function testTheCheckStepFailsWhenItCannotWriteItsOutput(): void
    {
        [$status] = $this->runStep('0', 'ok', $this->tmp . '/missing-dir/output');

        self::assertNotSame(0, $status);
    }

    public function testEveryMultiLineWorkflowStepFailsFast(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/upstream-watch.yml');
        $lines = explode("\n", $workflow);
        $blocks = 0;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s+run: \|\s*$/', $line) === 1) {
                $blocks++;
                self::assertSame('set -euo pipefail', trim($lines[$i + 1]), 'Every run block starts with set -euo pipefail (line ' . ($i + 1) . ').');
            }
        }
        self::assertGreaterThanOrEqual(2, $blocks);
        self::assertStringContainsString('Issue lookup failed', $workflow);
        self::assertMatchesRegularExpression('/if ! issues=\$\(gh issue list/', $workflow);
    }
}
