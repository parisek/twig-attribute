<?php

declare(strict_types=1);

/**
 * Compares the vendored classes in src/ with Drupal 11.x upstream.
 *
 * Usage:
 *   php scripts/check-upstream.php [options]
 *
 * Options:
 *   --upstream=DIR|URL    Where the five upstream files live. Default: the Drupal 11.x raw URL.
 *   --src=DIR             The vendored sources. Default: src/ of this repository.
 *   --commits=api|none|DIR  Where to read the commit lists. `api` (default) is the Drupal GitLab API,
 *                         `none` skips the commit check, DIR holds one <Name>.json per file.
 *   --reviewed=FILE       The last-reviewed marker. Default: .upstream-reviewed of this repository.
 *   --mark-reviewed=NAME  Record the newest upstream commit as reviewed by NAME. Refuses while drift exists.
 *
 * Exit codes: 0 no functional drift, 1 drift or a new SA-CORE commit, 2 download or parse error.
 * The Markdown report goes to stdout. Errors go to stderr.
 *
 * Only PHP built-ins are used (tokenizer, json). No Composer dependency.
 */

final class UpstreamCheckError extends RuntimeException {}

final class UpstreamCheck
{
    public const UPSTREAM_URL = 'https://git.drupalcode.org/project/drupal/-/raw/11.x/core/lib/Drupal/Core/Template';
    public const COMMITS_URL = 'https://git.drupalcode.org/api/v4/projects/project%2Fdrupal/repository/commits?ref_name=11.x&per_page=20&path=';
    public const UPSTREAM_PATH = 'core/lib/Drupal/Core/Template/';

    /** Upstream file name (without .php) => local file name in src/. */
    public const FILES = [
        'Attribute' => 'AttributeCollection',
        'AttributeArray' => 'AttributeArray',
        'AttributeBoolean' => 'AttributeBoolean',
        'AttributeString' => 'AttributeString',
        'AttributeValueBase' => 'AttributeValueBase',
    ];

    /**
     * Intended `use` differences. Key: upstream statement. Value: local statement, or null if it is dropped.
     * An upstream `use` that is in neither list is NOT normalized and shows up as drift.
     */
    public const USE_MAP = [
        'use Drupal\Component\Utility\Html;' => 'use Parisek\Twig\Internal\Escape;',
        'use Drupal\Component\Render\PlainTextOutput;' => 'use Parisek\Twig\Internal\PlainTextOutput;',
        'use Drupal\Component\Utility\NestedArray;' => 'use Parisek\Twig\Internal\NestedArray;',
        // MarkupInterface lives in the same namespace here, so no import is needed.
        'use Drupal\Component\Render\MarkupInterface;' => null,
        // The #[JsonSchema] attribute is dropped below, so its import goes too.
        'use Drupal\Core\Serialization\Attribute\JsonSchema;' => null,
    ];

    /**
     * Intended edits to one executable line. Key: upstream line. Value: local line.
     * Both sides are written as source and normalized like the files.
     */
    public const LINE_MAP = [
        'public function offsetSet($name, $value): void {' => 'public function offsetSet($name, mixed $value): void {',
    ];

    /** Attributes that the port removes on purpose. */
    public const DROPPED_ATTRIBUTES = ['JsonSchema'];

    private const MAX_REPORT_BYTES = 60000;

    /**
     * @param list<string> $argv
     */
    public static function main(array $argv): int
    {
        $root = dirname(__DIR__);
        $options = [
            'upstream' => self::UPSTREAM_URL,
            'src' => $root . '/src',
            'commits' => 'api',
            'reviewed' => $root . '/.upstream-reviewed',
            'mark-reviewed' => null,
        ];
        foreach (array_slice($argv, 1) as $argument) {
            if (!preg_match('/^--([a-z-]+)=(.*)$/s', $argument, $m) || !array_key_exists($m[1], $options)) {
                fwrite(STDERR, "Unknown argument: {$argument}\n");

                return 2;
            }
            $options[$m[1]] = $m[2];
        }

        try {
            return self::run($options);
        } catch (UpstreamCheckError $e) {
            fwrite(STDERR, 'check-upstream: ' . $e->getMessage() . "\n");

            return 2;
        }
    }

    /**
     * @param array<string, string|null> $options
     */
    private static function run(array $options): int
    {
        $diffs = self::compare((string) $options['upstream'], (string) $options['src']);

        $commitsMode = (string) $options['commits'];
        $reviewedFile = (string) $options['reviewed'];
        $reviewed = null;
        $perFile = [];
        if ($commitsMode !== 'none') {
            $perFile = self::fetchCommits($commitsMode);
            $reviewed = $options['mark-reviewed'] === null ? self::readReviewed($reviewedFile) : null;
        }

        if ($options['mark-reviewed'] !== null) {
            if ($commitsMode === 'none') {
                throw new UpstreamCheckError('--mark-reviewed needs the commit lists. Do not use --commits=none.');
            }
            if ($diffs !== []) {
                fwrite(STDOUT, self::report($diffs, [], $reviewed));
                fwrite(STDERR, "check-upstream: functional drift exists. Port it, or add an intended rule to the script, before you mark the state as reviewed.\n");

                return 1;
            }
            $newest = self::newest($perFile);
            if ($newest === null) {
                throw new UpstreamCheckError('The commit lists are empty. Nothing to mark.');
            }
            $payload = [
                'commit' => $newest['id'],
                'date' => $newest['committed_date'],
                'reviewed_by' => (string) $options['mark-reviewed'],
                'title' => $newest['title'],
            ];
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false || file_put_contents($reviewedFile, $json . "\n") === false) {
                throw new UpstreamCheckError("Cannot write {$reviewedFile}.");
            }
            fwrite(STDOUT, "Marked {$newest['id']} ({$newest['committed_date']}) as reviewed by {$options['mark-reviewed']}.\n");

            return 0;
        }

        $newCommits = self::newerThan($perFile, $reviewed);
        fwrite(STDOUT, self::report($diffs, $newCommits, $reviewed));

        $security = array_filter($newCommits, static fn(array $c): bool => $c['security']);

        return ($diffs !== [] || $security !== []) ? 1 : 0;
    }

    // ---------------------------------------------------------------- comparison

    /**
     * @return array<string, string> Local file label => unified diff. Empty when there is no functional drift.
     */
    public static function compare(string $upstream, string $src): array
    {
        $diffs = [];
        foreach (self::FILES as $name => $local) {
            $upstreamCode = self::fetch(rtrim($upstream, '/') . "/{$name}.php");
            $localCode = self::fetch(rtrim($src, '/') . "/{$local}.php");
            $expected = self::normalizeUpstream($upstreamCode, "upstream {$name}.php");
            $actual = self::canonicalLines($localCode, "src/{$local}.php");
            $diff = self::unifiedDiff($expected, $actual, "upstream/{$name}.php (normalized)", "src/{$local}.php");
            if ($diff !== '') {
                $diffs["src/{$local}.php"] = $diff;
            }
        }

        return $diffs;
    }

    /**
     * Applies the intended differences to upstream code, then returns the canonical lines.
     *
     * @return list<string>
     */
    public static function normalizeUpstream(string $code, string $label = 'upstream'): array
    {
        $lines = self::canonicalLines($code, $label, true);
        $lineMap = [];
        foreach (self::LINE_MAP as $from => $to) {
            $lineMap[implode("\n", self::canonicalLines("<?php {$from}", 'rule', false, false))] = implode("\n", self::canonicalLines("<?php {$to}", 'rule', false, false));
        }
        $result = [];
        foreach ($lines as $line) {
            if (array_key_exists($line, self::normalizedUseMap())) {
                $mapped = self::normalizedUseMap()[$line];
                if ($mapped !== null) {
                    $result[] = $mapped;
                }

                continue;
            }
            $result[] = $lineMap[$line] ?? $line;
        }

        return self::sortUses($result);
    }

    /**
     * @return array<string, string|null>
     */
    private static function normalizedUseMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::USE_MAP as $from => $to) {
                $key = implode("\n", self::canonicalLines("<?php {$from}", 'rule', false, false));
                $map[$key] = $to === null ? null : implode("\n", self::canonicalLines("<?php {$to}", 'rule', false, false));
            }
        }

        return $map;
    }

    /**
     * Turns code into one statement per line. Comments and whitespace do not survive.
     * Any token in executable code does, so a changed condition or a new statement changes the output.
     *
     * @return list<string>
     */
    public static function canonicalLines(string $code, string $label, bool $upstream = false, bool $parse = true): array
    {
        try {
            $tokens = token_get_all($code, $parse ? TOKEN_PARSE : 0);
        } catch (ParseError $e) {
            throw new UpstreamCheckError("Cannot parse {$label}: " . $e->getMessage());
        }

        // Flatten to [id, text] and drop what never runs.
        $flat = [];
        foreach ($tokens as $token) {
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                continue;
            }
            $flat[] = [$id, $text];
        }

        $flat = self::dropAttributes($flat);
        $flat = self::dropStrictTypes($flat);
        if ($upstream) {
            $flat = self::mapNames($flat);
        }

        return self::render($flat);
    }

    /**
     * @param list<array{int|null, string}> $tokens
     *
     * @return list<array{int|null, string}>
     */
    private static function dropAttributes(array $tokens): array
    {
        $out = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] === T_ATTRIBUTE) {
                $depth = 0;
                for ($j = $i; $j < $count; $j++) {
                    if ($tokens[$j][0] === T_ATTRIBUTE || $tokens[$j][1] === '[') {
                        $depth++;
                    } elseif ($tokens[$j][1] === ']') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                }
                $name = ltrim($tokens[$i + 1][1] ?? '', '\\');
                $short = substr($name, (int) strrpos('\\' . $name, '\\'));
                if (in_array($short, self::DROPPED_ATTRIBUTES, true)) {
                    $i = $j;

                    continue;
                }
            }
            $out[] = $tokens[$i];
        }

        return $out;
    }

    /**
     * Removes `declare(strict_types=1);` from both sides. The port adds it; the compare ignores it.
     *
     * @param list<array{int|null, string}> $tokens
     *
     * @return list<array{int|null, string}>
     */
    private static function dropStrictTypes(array $tokens): array
    {
        $out = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] === T_DECLARE
                && ($tokens[$i + 2][1] ?? '') === 'strict_types'
                && ($tokens[$i + 4][1] ?? '') === '1'
                && ($tokens[$i + 5][1] ?? '') === ')'
                && ($tokens[$i + 6][1] ?? '') === ';') {
                $i += 6;

                continue;
            }
            $out[] = $tokens[$i];
        }

        return $out;
    }

    /**
     * Renames in upstream tokens: namespace, class Attribute, Html::escape. Comments are gone already.
     *
     * @param list<array{int|null, string}> $tokens
     *
     * @return list<array{int|null, string}>
     */
    private static function mapNames(array $tokens): array
    {
        $out = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];
            if ($id === T_STRING && $text === 'Html'
                && ($tokens[$i + 1][1] ?? '') === '::' && ($tokens[$i + 2][1] ?? '') === 'escape') {
                array_push($out, [T_STRING, 'Escape'], $tokens[$i + 1], [T_STRING, 'html']);
                $i += 2;

                continue;
            }
            if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $text = self::mapName($text);
            }
            $out[] = [$id, $text];
        }

        return $out;
    }

    private static function mapName(string $name): string
    {
        if ($name === 'Attribute') {
            return 'AttributeCollection';
        }
        if (preg_match('/^(\\\\?)Drupal\\\\Core\\\\Template(?:\\\\(.+))?$/', $name, $m) === 1) {
            $rest = $m[2] ?? '';

            return $m[1] . 'Drupal\Component\Attribute' . ($rest === '' ? '' : '\\' . ($rest === 'Attribute' ? 'AttributeCollection' : $rest));
        }

        return $name;
    }

    /**
     * @param list<array{int|null, string}> $tokens
     *
     * @return list<string>
     */
    private static function render(array $tokens): array
    {
        $noSpaceBefore = [')', ']', ',', ';', '->', '?->', '::'];
        $noSpaceAfter = ['(', '[', '->', '?->', '::', '!', '$', '@'];
        $callable = [T_STRING, T_VARIABLE, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_ARRAY, T_ISSET, T_UNSET, T_EMPTY, T_FN];

        $lines = [];
        $line = '';
        $prev = null;
        $paren = 0;
        foreach ($tokens as [$id, $text]) {
            if ($text === '(') {
                $paren++;
            } elseif ($text === ')') {
                $paren--;
            }
            $glue = $prev !== null
                && !in_array($text, $noSpaceBefore, true)
                && !in_array($prev[1], $noSpaceAfter, true)
                && !(in_array($text, ['(', '['], true) && ($prev[1] === ')' || $prev[1] === ']' || in_array($prev[0], $callable, true)));
            $line .= ($glue && $line !== '' ? ' ' : '') . $text;
            $prev = [$id, $text];
            if ($paren <= 0 && in_array($text, [';', '{', '}'], true)) {
                $lines[] = $line;
                $line = '';
                $prev = null;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Sorts the top-level `use` lines in place, so a different import order is not drift.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function sortUses(array $lines): array
    {
        $slots = [];
        $uses = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^(abstract |final |readonly )*(class|interface|trait|enum) /', $line) === 1) {
                break;
            }
            if (str_starts_with($line, 'use ')) {
                $slots[] = $i;
                $uses[] = $line;
            }
        }
        sort($uses);
        foreach ($slots as $n => $i) {
            $lines[$i] = $uses[$n];
        }

        return array_values($lines);
    }

    // --------------------------------------------------------------------- diff

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    public static function unifiedDiff(array $a, array $b, string $labelA, string $labelB, int $context = 3): string
    {
        if ($a === $b) {
            return '';
        }
        $ops = self::edits($a, $b);

        $n = count($ops);
        $oldNo = [];
        $newNo = [];
        $o = 1;
        $w = 1;
        foreach ($ops as $i => $op) {
            $oldNo[$i] = $o;
            $newNo[$i] = $w;
            if ($op[0] !== '+') {
                $o++;
            }
            if ($op[0] !== '-') {
                $w++;
            }
        }

        $out = ["--- {$labelA}", "+++ {$labelB}"];
        $i = 0;
        while ($i < $n) {
            if ($ops[$i][0] === ' ') {
                $i++;

                continue;
            }
            $start = max(0, $i - $context);
            $last = $i;
            for ($j = $i; $j < $n; $j++) {
                if ($ops[$j][0] !== ' ') {
                    $last = $j;
                } elseif ($j - $last > 2 * $context) {
                    break;
                }
            }
            $end = min($n - 1, $last + $context);
            $oldCount = 0;
            $newCount = 0;
            $body = [];
            for ($k = $start; $k <= $end; $k++) {
                $body[] = $ops[$k][0] . $ops[$k][1];
                $oldCount += $ops[$k][0] !== '+' ? 1 : 0;
                $newCount += $ops[$k][0] !== '-' ? 1 : 0;
            }
            $out[] = sprintf('@@ -%d,%d +%d,%d @@', $oldCount === 0 ? $oldNo[$start] - 1 : $oldNo[$start], $oldCount, $newCount === 0 ? $newNo[$start] - 1 : $newNo[$start], $newCount);
            array_push($out, ...$body);
            $i = $end + 1;
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{string, string}>
     */
    private static function edits(array $a, array $b): array
    {
        $prefix = 0;
        $na = count($a);
        $nb = count($b);
        while ($prefix < $na && $prefix < $nb && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < $na - $prefix && $suffix < $nb - $prefix && $a[$na - 1 - $suffix] === $b[$nb - 1 - $suffix]) {
            $suffix++;
        }
        $midA = array_slice($a, $prefix, $na - $prefix - $suffix);
        $midB = array_slice($b, $prefix, $nb - $prefix - $suffix);

        $ops = [];
        foreach (array_slice($a, 0, $prefix) as $line) {
            $ops[] = [' ', $line];
        }

        $x = count($midA);
        $y = count($midB);
        if ($x * $y > 4_000_000) {
            // Too large for the table: show the middle as one replaced block.
            foreach ($midA as $line) {
                $ops[] = ['-', $line];
            }
            foreach ($midB as $line) {
                $ops[] = ['+', $line];
            }
        } else {
            $lcs = array_fill(0, $x + 1, array_fill(0, $y + 1, 0));
            for ($i = $x - 1; $i >= 0; $i--) {
                for ($j = $y - 1; $j >= 0; $j--) {
                    $lcs[$i][$j] = $midA[$i] === $midB[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
                }
            }
            $i = 0;
            $j = 0;
            while ($i < $x && $j < $y) {
                if ($midA[$i] === $midB[$j]) {
                    $ops[] = [' ', $midA[$i]];
                    $i++;
                    $j++;
                } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                    $ops[] = ['-', $midA[$i++]];
                } else {
                    $ops[] = ['+', $midB[$j++]];
                }
            }
            while ($i < $x) {
                $ops[] = ['-', $midA[$i++]];
            }
            while ($j < $y) {
                $ops[] = ['+', $midB[$j++]];
            }
        }

        foreach (array_slice($a, $na - $suffix) as $line) {
            $ops[] = [' ', $line];
        }

        return $ops;
    }

    // ------------------------------------------------------------------ commits

    /**
     * @return array<string, list<array{id: string, short_id: string, title: string, committed_date: string, web_url: string, security: bool}>>
     */
    private static function fetchCommits(string $mode): array
    {
        $result = [];
        foreach (array_keys(self::FILES) as $name) {
            $source = $mode === 'api'
                ? self::COMMITS_URL . rawurlencode(self::UPSTREAM_PATH . $name . '.php')
                : rtrim($mode, '/') . "/{$name}.json";
            try {
                $data = json_decode(self::fetch($source), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new UpstreamCheckError("Cannot parse the commit list for {$name}.php: " . $e->getMessage());
            }
            if (!is_array($data)) {
                throw new UpstreamCheckError("The commit list for {$name}.php is not a list.");
            }
            $list = [];
            foreach ($data as $commit) {
                foreach (['id', 'title', 'committed_date'] as $key) {
                    if (!is_array($commit) || !isset($commit[$key]) || !is_string($commit[$key])) {
                        throw new UpstreamCheckError("A commit of {$name}.php has no `{$key}`.");
                    }
                }
                if (strtotime($commit['committed_date']) === false) {
                    throw new UpstreamCheckError("A commit of {$name}.php has a bad date: {$commit['committed_date']}");
                }
                $list[] = [
                    'id' => $commit['id'],
                    'short_id' => is_string($commit['short_id'] ?? null) ? $commit['short_id'] : substr($commit['id'], 0, 10),
                    'title' => $commit['title'],
                    'committed_date' => $commit['committed_date'],
                    'web_url' => is_string($commit['web_url'] ?? null) ? $commit['web_url'] : '',
                    'security' => stripos($commit['title'], 'SA-CORE') !== false,
                ];
            }
            $result[$name] = $list;
        }

        return $result;
    }

    /**
     * @param array<string, list<array{id: string, short_id: string, title: string, committed_date: string, web_url: string, security: bool}>> $perFile
     *
     * @return array{id: string, short_id: string, title: string, committed_date: string, web_url: string, security: bool}|null
     */
    private static function newest(array $perFile): ?array
    {
        $newest = null;
        foreach ($perFile as $list) {
            foreach ($list as $commit) {
                if ($newest === null || strtotime($commit['committed_date']) > strtotime($newest['committed_date'])) {
                    $newest = $commit;
                }
            }
        }

        return $newest;
    }

    /**
     * @return array{commit: string, date: string, reviewed_by: string}
     */
    private static function readReviewed(string $file): array
    {
        $json = @file_get_contents($file);
        if ($json === false) {
            throw new UpstreamCheckError("Cannot read {$file}. Create it with --mark-reviewed=NAME.");
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UpstreamCheckError("Cannot parse {$file}: " . $e->getMessage());
        }
        foreach (['commit', 'date', 'reviewed_by'] as $key) {
            if (!is_array($data) || !isset($data[$key]) || !is_string($data[$key])) {
                throw new UpstreamCheckError("{$file} has no `{$key}`.");
            }
        }
        if (strtotime($data['date']) === false) {
            throw new UpstreamCheckError("{$file} has a bad date.");
        }

        return ['commit' => $data['commit'], 'date' => $data['date'], 'reviewed_by' => $data['reviewed_by']];
    }

    /**
     * Commits newer than the reviewed one, newest first. A commit that touches several files appears once.
     *
     * @param array<string, list<array{id: string, short_id: string, title: string, committed_date: string, web_url: string, security: bool}>> $perFile
     * @param array{commit: string, date: string, reviewed_by: string}|null $reviewed
     *
     * @return list<array{id: string, short_id: string, title: string, committed_date: string, web_url: string, security: bool, files: list<string>}>
     */
    private static function newerThan(array $perFile, ?array $reviewed): array
    {
        if ($reviewed === null) {
            return [];
        }
        $limit = strtotime($reviewed['date']);
        $found = [];
        foreach ($perFile as $name => $list) {
            foreach ($list as $commit) {
                if ($commit['id'] === $reviewed['commit'] || strtotime($commit['committed_date']) <= $limit) {
                    continue;
                }
                $found[$commit['id']] ??= $commit + ['files' => []];
                $found[$commit['id']]['files'][] = "{$name}.php";
            }
        }
        $found = array_values($found);
        usort($found, static fn(array $x, array $y): int => strtotime($y['committed_date']) <=> strtotime($x['committed_date']));

        return $found;
    }

    // ------------------------------------------------------------------- report

    /**
     * @param array<string, string> $diffs
     * @param list<array{id: string, short_id: string, title: string, committed_date: string, web_url: string, security: bool, files: list<string>}> $commits
     * @param array{commit: string, date: string, reviewed_by: string}|null $reviewed
     */
    private static function report(array $diffs, array $commits, ?array $reviewed): string
    {
        foreach ([null, 200, 40] as $maxDiffLines) {
            $report = self::renderReport($diffs, $commits, $reviewed, $maxDiffLines);
            if (strlen($report) <= self::MAX_REPORT_BYTES) {
                return $report;
            }
        }

        return $report;
    }

    /**
     * @param array<string, string> $diffs
     * @param list<array{id: string, short_id: string, title: string, committed_date: string, web_url: string, security: bool, files: list<string>}> $commits
     * @param array{commit: string, date: string, reviewed_by: string}|null $reviewed
     */
    private static function renderReport(array $diffs, array $commits, ?array $reviewed, ?int $maxDiffLines): string
    {
        $security = array_filter($commits, static fn(array $c): bool => $c['security']);
        $out = [];
        $out[] = 'Weekly check of Drupal 11.x `Core/Template/Attribute*.php` against `src/`, by `scripts/check-upstream.php`.';
        $out[] = '';
        if ($security !== []) {
            $out[] = '> **High priority.** ' . count($security) . ' upstream commit(s) mention SA-CORE. Review them first.';
            $out[] = '';
        }
        if ($diffs === [] && $commits === []) {
            $out[] = 'No functional difference. No upstream commit since the last review.';

            return implode("\n", $out) . "\n";
        }

        if ($commits !== []) {
            $out[] = '### Upstream commits since the last review';
            $out[] = '';
            if ($reviewed !== null) {
                $out[] = "Last reviewed: `{$reviewed['commit']}` ({$reviewed['date']}, {$reviewed['reviewed_by']}).";
                $out[] = '';
            }
            foreach ($commits as $commit) {
                $title = str_replace(["\r", "\n", '|'], ' ', $commit['title']);
                $link = $commit['web_url'] !== '' ? "[`{$commit['short_id']}`]({$commit['web_url']})" : "`{$commit['short_id']}`";
                $out[] = "- {$link} {$commit['committed_date']}" . ($commit['security'] ? ' **SA-CORE**' : '') . ': ' . $title . ' (' . implode(', ', $commit['files']) . ')';
            }
            $out[] = '';
        }

        if ($diffs !== []) {
            $out[] = '### Functional differences';
            $out[] = '';
            $out[] = 'Statements, one per line. Comments, whitespace, and the intended differences are normalized away. `-` is upstream, `+` is `src/`.';
            $out[] = '';
            foreach ($diffs as $file => $diff) {
                $lines = explode("\n", rtrim($diff, "\n"));
                $note = '';
                if ($maxDiffLines !== null && count($lines) > $maxDiffLines) {
                    $note = "\n(Diff cut after {$maxDiffLines} lines. Run the script to see all of it.)";
                    $lines = array_slice($lines, 0, $maxDiffLines);
                }
                $fence = str_repeat('`', max(3, self::longestBacktickRun($diff) + 1));
                $out[] = '<details>';
                $out[] = "<summary>{$file}</summary>";
                $out[] = '';
                $out[] = $fence . 'diff';
                $out[] = implode("\n", $lines) . $note;
                $out[] = $fence;
                $out[] = '';
                $out[] = '</details>';
                $out[] = '';
            }
        }

        $out[] = '### What to do';
        $out[] = '';
        $out[] = '1. Read the upstream commits. Start with any SA-CORE commit.';
        $out[] = '2. Port the change, as AGENTS.md describes under "Refreshing from Drupal 11.x upstream".';
        $out[] = '3. Run `php scripts/check-upstream.php --mark-reviewed="Your Name"` and commit `.upstream-reviewed`.';
        $out[] = '';
        $out[] = 'This issue closes by hand.';

        return implode("\n", $out) . "\n";
    }

    private static function longestBacktickRun(string $text): int
    {
        $longest = 0;
        if (preg_match_all('/`+/', $text, $m) > 0) {
            foreach ($m[0] as $run) {
                $longest = max($longest, strlen($run));
            }
        }

        return $longest;
    }

    // ------------------------------------------------------------------ network

    private static function fetch(string $location): string
    {
        if (preg_match('#^https?://#i', $location) !== 1) {
            $body = @file_get_contents($location);
            if ($body === false) {
                throw new UpstreamCheckError("Cannot read {$location}.");
            }

            return $body;
        }

        $context = stream_context_create(['http' => [
            'timeout' => 30,
            'ignore_errors' => true,
            'header' => "User-Agent: parisek-twig-attribute-upstream-watch\r\nAccept: */*\r\n",
        ]]);
        $status = 0;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $http_response_header = [];
            $body = @file_get_contents($location, false, $context);
            $status = isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m) === 1 ? (int) $m[1] : 0;
            if ($body !== false && $status === 200) {
                return $body;
            }
            if ($status >= 400 && $status < 500 && $status !== 429) {
                break;
            }
            if ($attempt < 2) {
                sleep(1);
            }
        }

        throw new UpstreamCheckError("Cannot download {$location} (HTTP status {$status}).");
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(UpstreamCheck::main($argv));
}
