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
 *                         `none` skips the commit check, DIR holds <Name>.json (page 1), <Name>.2.json, ... per file.
 *   --reviewed=FILE       The last-reviewed marker. Default: .upstream-reviewed of this repository.
 *   --mark-reviewed=NAME  Record the newest upstream commit per file as reviewed by NAME. Refuses while drift exists.
 *   --ack=ID[,ID...]      With --mark-reviewed: SA-CORE commit ids (12+ hex characters) that you reviewed.
 *   --reset-boundary      With --mark-reviewed: accept a lost review boundary. Prints a warning, records it.
 *   --allow=URL_PREFIX    Test hook. Replaces the allowed download prefix. Only http://127.0.0.1:PORT/ or http://localhost:PORT/.
 *
 * Exit codes: 0 no functional drift, 1 drift or a new SA-CORE commit, 2 download or parse error.
 * The Markdown report goes to stdout. Errors go to stderr.
 *
 * Only PHP built-ins are used (tokenizer, json). No Composer dependency.
 */

final class UpstreamCheckError extends RuntimeException {}

/**
 * @phpstan-type Commit array{id: string, short_id: string, title: string, committed_date: string, web_url: string, security: bool}
 * @phpstan-type CommitWithFiles array{id: string, short_id: string, title: string, committed_date: string, web_url: string, security: bool, files: list<string>}
 */
final class UpstreamCheck
{
    public const UPSTREAM_URL = 'https://git.drupalcode.org/project/drupal/-/raw/11.x/core/lib/Drupal/Core/Template';
    public const COMMITS_URL = 'https://git.drupalcode.org/api/v4/projects/project%2Fdrupal/repository/commits?ref_name=11.x&per_page=100&path=';
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
        // Deliberate deviation: reject unsafe attribute names (issue #32, option B).
        '$this->name = $name;' => '$this->name = \\Parisek\\Twig\\Internal\\AttributeName::assertValid($name);',
    ];

    /** Attributes that the port removes on purpose. */
    public const DROPPED_ATTRIBUTES = ['JsonSchema'];

    private const MAX_REPORT_BYTES = 60000;
    private const MAX_COMMITS = 50;
    private const MAX_TITLE_BYTES = 200;
    private const MAX_PAGES = 20;
    private const PER_PAGE = 100;
    private const MAX_DOWNLOAD_BYTES = 2_097_152;
    private const TOTAL_TIMEOUT = 60;

    private static ?string $allowOverride = null;

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
            'ack' => null,
            'reset-boundary' => null,
            'allow' => null,
        ];
        foreach (array_slice($argv, 1) as $argument) {
            if ($argument === '--reset-boundary') {
                $m = [$argument, 'reset-boundary', '1'];
            } elseif (preg_match('/^--([a-z-]+)=(.*)$/s', $argument, $m) !== 1 || !array_key_exists($m[1], $options) || $m[1] === 'reset-boundary') {
                fwrite(STDERR, "Unknown argument: {$argument}\n");

                return 2;
            }
            $options[$m[1]] = $m[2];
        }
        if ($options['allow'] !== null && preg_match('#^http://(127\.0\.0\.1|localhost):\d+/#', $options['allow']) !== 1) {
            fwrite(STDERR, "check-upstream: --allow is a test hook. It accepts only http://127.0.0.1:PORT/ or http://localhost:PORT/ prefixes.\n");

            return 2;
        }

        self::$allowOverride = $options['allow'];

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

        if ($options['mark-reviewed'] !== null) {
            return self::markReviewed(
                $diffs,
                $commitsMode,
                $reviewedFile,
                (string) $options['mark-reviewed'],
                $options['ack'] === null ? [] : array_values(array_filter(array_map(static fn(string $a): string => strtolower(trim($a)), explode(',', $options['ack'])), static fn(string $a): bool => $a !== '')),
                $options['reset-boundary'] !== null,
            );
        }

        $reviewed = null;
        $commits = [];
        $lost = [];
        if ($commitsMode !== 'none') {
            $reviewed = self::readReviewed($reviewedFile);
            [$commits, $lost] = self::scan($commitsMode, $reviewed);
        }
        fwrite(STDOUT, self::report($diffs, $commits, $lost, $reviewed));

        $security = array_filter($commits, static fn(array $c): bool => $c['security']);

        return ($diffs !== [] || $security !== [] || $lost !== []) ? 1 : 0;
    }

    /**
     * The commit scan that a normal check and --mark-reviewed share.
     *
     * @param array{commit: string, date: string, reviewed_by: string, files: array<string, string>} $reviewed
     *
     * @return array{list<CommitWithFiles>, array<string, int>} New commits (newest first) and the files with a lost boundary.
     */
    private static function scan(string $commitsMode, array $reviewed): array
    {
        $merged = [];
        $lost = [];
        foreach (array_keys(self::FILES) as $name) {
            $history = self::readHistory($commitsMode, $name, $reviewed['files'][$name]);
            if (!$history['found']) {
                $lost[$name] = $history['listed'];
            }
            // Merge by commit id: a commit that touches several files appears once.
            foreach ($history['new'] as $commit) {
                $merged[$commit['id']] ??= $commit + ['files' => []];
                $merged[$commit['id']]['files'][] = "{$name}.php";
            }
        }
        $commits = array_values($merged);
        // Display order only. The boundary never uses dates.
        usort($commits, static fn(array $x, array $y): int => strtotime($y['committed_date']) <=> strtotime($x['committed_date']));

        return [$commits, $lost];
    }

    /**
     * Moves the review boundary. It runs the same scan as a check and refuses to skip anything:
     * drift, a lost boundary and unacknowledged SA-CORE commits all stop it without a write.
     *
     * @param array<string, string> $diffs
     * @param list<string> $ack SA-CORE commit ids (or prefixes of 12+ characters) that the maintainer reviewed.
     */
    private static function markReviewed(array $diffs, string $commitsMode, string $reviewedFile, string $who, array $ack, bool $reset): int
    {
        if ($commitsMode === 'none') {
            throw new UpstreamCheckError('--mark-reviewed needs the commit lists. Do not use --commits=none.');
        }
        foreach ($ack as $prefix) {
            if (preg_match('/^[0-9a-f]{12,40}$/', $prefix) !== 1) {
                throw new UpstreamCheckError("--ack needs commit ids of 12 to 40 hex characters. Got: {$prefix}");
            }
        }
        if ($diffs !== []) {
            fwrite(STDOUT, self::report($diffs, [], [], null));
            fwrite(STDERR, "check-upstream: functional drift exists. Port it, or add an intended rule to the script, before you mark the state as reviewed.\n");

            return 1;
        }

        // Without a marker there is no boundary: read up to the page cap and gate every SA-CORE commit found.
        // Deleting the marker must not skip the security check.
        $previous = null;
        $gate = true;
        if (is_file($reviewedFile)) {
            try {
                $previous = self::readReviewed($reviewedFile);
            } catch (UpstreamCheckError $e) {
                if (!$reset) {
                    throw $e;
                }
                $gate = false; // An unreadable marker plus --reset-boundary: the maintainer accepts the gap.
            }
        }
        $acknowledged = [];
        if ($gate) {
            if ($previous !== null) {
                [$commits, $lost] = self::scan($commitsMode, $previous);
                if ($lost !== [] && !$reset) {
                    fwrite(STDOUT, self::report([], $commits, $lost, $previous));
                    fwrite(STDERR, 'check-upstream: the review boundary is lost for ' . implode(', ', array_keys($lost)) . ". Read the recent upstream history by hand, then run again with --reset-boundary.\n");

                    return 1;
                }
            } else {
                [$commits] = self::scan($commitsMode, ['commit' => '', 'date' => '', 'reviewed_by' => '', 'files' => array_fill_keys(array_keys(self::FILES), '')]);
            }
            $security = array_values(array_filter($commits, static fn(array $c): bool => $c['security']));
            $unmatched = [];
            foreach ($ack as $prefix) {
                $hits = array_values(array_filter($security, static fn(array $c): bool => str_starts_with($c['id'], $prefix)));
                if ($hits === []) {
                    $unmatched[] = $prefix;
                } elseif (count($hits) > 1) {
                    fwrite(STDERR, "check-upstream: --ack={$prefix} is ambiguous. It matches " . count($hits) . " SA-CORE commits. Use a longer prefix.\n");

                    return 1;
                }
            }
            foreach ($unmatched as $prefix) {
                fwrite(STDERR, "check-upstream: warning: --ack={$prefix} matches no SA-CORE commit since the boundary.\n");
            }
            $pending = [];
            foreach ($security as $commit) {
                $matched = false;
                foreach ($ack as $prefix) {
                    $matched = $matched || str_starts_with($commit['id'], $prefix);
                }
                if ($matched) {
                    $acknowledged[] = $commit['id'];
                } else {
                    $pending[] = $commit;
                }
            }
            if ($pending !== []) {
                foreach ($pending as $commit) {
                    fwrite(STDERR, "check-upstream: SA-CORE commit {$commit['id']} is not acknowledged: " . self::clean($commit['title']) . "\n");
                }
                fwrite(STDERR, "check-upstream: review each SA-CORE commit, then pass its id with --ack=ID[,ID...].\n");

                return 1;
            }
        }
        if ($reset) {
            fwrite(STDERR, "WARNING: --reset-boundary skips the commits between the old boundary and now. The file records this.\n");
        }

        $files = [];
        $newest = null;
        foreach (array_keys(self::FILES) as $name) {
            $commit = self::readHistory($commitsMode, $name, null)['newest'];
            if ($commit === null) {
                throw new UpstreamCheckError("The commit list for {$name}.php is empty. Nothing to mark.");
            }
            $files[$name] = $commit['id'];
            if ($newest === null || strtotime($commit['committed_date']) > strtotime($newest['committed_date'])) {
                $newest = $commit;
            }
        }
        $payload = [
            'commit' => $newest['id'],
            'date' => $newest['committed_date'],
            'reviewed_by' => $who,
            'title' => $newest['title'],
            'files' => $files,
        ];
        if ($acknowledged !== []) {
            $payload['acknowledged'] = $acknowledged;
        }
        if ($reset) {
            $payload['boundary_reset'] = true;
        }
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new UpstreamCheckError('Cannot encode the marker.');
        }
        self::writeAtomically($reviewedFile, $json . "\n", $payload);
        fwrite(STDOUT, "Marked {$newest['id']} ({$newest['committed_date']}) as reviewed by {$who}.\n");

        return 0;
    }

    /**
     * Writes the marker through a temp file in the same directory, verifies it, then renames it over the old one.
     * A failure leaves the previous marker as it was and removes the temp file.
     *
     * @param array<string, mixed> $payload
     */
    private static function writeAtomically(string $file, string $content, array $payload): void
    {
        $dir = dirname($file);
        $temp = @tempnam($dir, '.upstream-reviewed.');
        if ($temp === false) {
            throw new UpstreamCheckError("Cannot create a temp file in {$dir}. The marker is unchanged.");
        }
        try {
            if (@file_put_contents($temp, $content) !== strlen($content)) {
                throw new UpstreamCheckError("Cannot write {$temp}. The marker is unchanged.");
            }
            $back = json_decode((string) @file_get_contents($temp), true);
            if ($back !== json_decode((string) json_encode($payload), true)) {
                throw new UpstreamCheckError('The temp file does not match the marker. The marker is unchanged.');
            }
            $mode = is_file($file) ? (fileperms($file) & 0777) : 0644;
            if (!@chmod($temp, $mode) || !@rename($temp, $file)) {
                throw new UpstreamCheckError("Cannot replace {$file}. The marker is unchanged.");
            }
        } catch (UpstreamCheckError $e) {
            @unlink($temp);

            throw $e;
        }
    }

    // ---------------------------------------------------------------- comparison

    /**
     * @return array<string, string> Local file label => unified diff. Empty when there is no functional drift.
     */
    public static function compare(string $upstream, string $src): array
    {
        $diffs = [];
        foreach (self::FILES as $name => $local) {
            $upstreamCode = self::fetch(rtrim($upstream, '/') . "/{$name}.php", self::allowed('raw'));
            $localCode = self::fetch(rtrim($src, '/') . "/{$local}.php", '');
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
     * Reads the history of one file, newest first, page by page, until the reviewed commit.
     * The boundary is the commit id (reachability), never a date: a cherry-pick keeps an old date.
     * Without a boundary, only the newest commit matters, so only page 1 is read.
     *
     * @return array{newest: ?Commit, new: list<Commit>, found: bool, listed: int}
     */
    private static function readHistory(string $mode, string $name, ?string $boundary): array
    {
        $new = [];
        $newest = null;
        $listed = 0;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            [$commits, $hasNext] = self::commitPage($mode, $name, $page);
            foreach ($commits as $commit) {
                $newest ??= $commit;
                if ($boundary === null) {
                    return ['newest' => $newest, 'new' => [], 'found' => true, 'listed' => 1];
                }
                if ($commit['id'] === $boundary) {
                    return ['newest' => $newest, 'new' => $new, 'found' => true, 'listed' => $listed];
                }
                $new[] = $commit;
                $listed++;
            }
            if (!$hasNext) {
                break;
            }
        }

        return ['newest' => $newest, 'new' => $new, 'found' => false, 'listed' => $listed];
    }

    /**
     * @return array{list<Commit>, bool} One page of commits and whether another page follows.
     */
    private static function commitPage(string $mode, string $name, int $page): array
    {
        if ($mode === 'api') {
            [$body, $headers] = self::httpGet(self::COMMITS_URL . rawurlencode(self::UPSTREAM_PATH . $name . '.php') . '&page=' . $page, self::allowed('api'));
            $hasNext = false;
            foreach ($headers as $header) {
                if (stripos($header, 'x-next-page:') === 0 && trim(substr($header, 12)) !== '') {
                    $hasNext = true;
                }
            }
        } else {
            $dir = rtrim($mode, '/');
            $body = self::fetch($dir . '/' . $name . ($page === 1 ? '' : ".{$page}") . '.json', '');
            $hasNext = is_file($dir . '/' . $name . '.' . ($page + 1) . '.json');
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
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
            if (preg_match('/^[0-9a-f]{40}$/', $commit['id']) !== 1) {
                throw new UpstreamCheckError("A commit of {$name}.php has a bad id.");
            }
            $url = is_string($commit['web_url'] ?? null) ? $commit['web_url'] : '';
            $list[] = [
                'id' => $commit['id'],
                'short_id' => substr($commit['id'], 0, 10),
                'title' => $commit['title'],
                'committed_date' => $commit['committed_date'],
                'web_url' => str_starts_with($url, 'https://git.drupalcode.org/') ? $url : '',
                'security' => stripos($commit['title'], 'SA-CORE') !== false,
            ];
        }

        return [$list, $hasNext];
    }

    /**
     * @return array{commit: string, date: string, reviewed_by: string, files: array<string, string>}
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
        $files = [];
        foreach (array_keys(self::FILES) as $name) {
            $id = is_array($data['files'] ?? null) ? ($data['files'][$name] ?? null) : null;
            if (!is_string($id) || preg_match('/^[0-9a-f]{40}$/', $id) !== 1) {
                throw new UpstreamCheckError("{$file} has no `files.{$name}` commit id. Re-create the file with --mark-reviewed=NAME.");
            }
            $files[$name] = $id;
        }

        return ['commit' => $data['commit'], 'date' => $data['date'], 'reviewed_by' => $data['reviewed_by'], 'files' => $files];
    }

    // ------------------------------------------------------------------- report

    /**
     * Builds the report and keeps it under the size limit: fewer diff lines first, then fewer commits, then a hard cut.
     *
     * @param array<string, string> $diffs
     * @param list<CommitWithFiles> $commits
     * @param array<string, int> $lost File name => commits listed before the reader gave up.
     * @param array{commit: string, date: string, reviewed_by: string, files: array<string, string>}|null $reviewed
     */
    private static function report(array $diffs, array $commits, array $lost, ?array $reviewed): string
    {
        $report = '';
        foreach ([[null, self::MAX_COMMITS], [200, self::MAX_COMMITS], [40, self::MAX_COMMITS], [10, 20]] as [$maxDiffLines, $maxCommits]) {
            $report = self::renderReport($diffs, $commits, $lost, $reviewed, $maxDiffLines, $maxCommits);
            if (strlen($report) <= self::MAX_REPORT_BYTES) {
                return $report;
            }
        }

        return self::truncate($report);
    }

    /**
     * Cuts at a line boundary, closes any open code fence and <details>, and adds a visible notice.
     * A line is kept only if the text up to it, plus the closers that its open state needs, plus the notice, fits.
     * Line ends may be LF or CRLF.
     */
    public static function truncate(string $report): string
    {
        $notice = "\n> **Report truncated.** Run `php scripts/check-upstream.php` to see all of it.\n";
        $closeDetails = strlen("</details>\n");
        $kept = [];
        $size = 0;
        $fence = null; // [marker character, opening run length]
        $details = 0;
        foreach (explode("\n", $report) as $line) {
            $marker = rtrim($line, "\r");
            $nextFence = $fence;
            $nextDetails = $details;
            if ($fence === null) {
                if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $marker, $m) === 1) {
                    $nextFence = [$m[1][0], strlen($m[1])];
                } elseif (preg_match('/^\s{0,3}<details(\s[^>]*)?>\s*$/i', $marker) === 1) {
                    $nextDetails++;
                } elseif (preg_match('/^\s{0,3}<\/details>\s*$/i', $marker) === 1) {
                    $nextDetails = max(0, $nextDetails - 1);
                }
            } elseif (preg_match('/^ {0,3}(' . $fence[0] . '+)\s*$/', $marker, $m) === 1 && strlen($m[1]) >= $fence[1]) {
                // A closing fence has the same character and a run at least as long as the opening one.
                $nextFence = null;
            }
            $closers = ($nextFence !== null ? $nextFence[1] + 1 : 0) + $nextDetails * $closeDetails;
            if ($size + strlen($line) + 1 + $closers + strlen($notice) > self::MAX_REPORT_BYTES) {
                break;
            }
            $size += strlen($line) + 1;
            $kept[] = $line;
            $fence = $nextFence;
            $details = $nextDetails;
        }
        $out = implode("\n", $kept) . "\n";
        if ($fence !== null) {
            $out .= str_repeat($fence[0], $fence[1]) . "\n";
        }
        $out .= str_repeat("</details>\n", $details);

        return $out . $notice;
    }

    /** Neutral text for the report: no control characters, a length limit, valid UTF-8, HTML-escaped inside <code>. */
    private static function clean(string $text, int $max = self::MAX_TITLE_BYTES): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
        $cut = strlen($text) > $max;
        $text = substr($text, 0, $max);
        while ($text !== '' && preg_match('//u', $text) !== 1) {
            $text = substr($text, 0, -1);
        }

        return '<code>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ($cut ? '…' : '') . '</code>';
    }

    /**
     * @param array<string, string> $diffs
     * @param list<CommitWithFiles> $commits
     * @param array<string, int> $lost
     * @param array{commit: string, date: string, reviewed_by: string, files: array<string, string>}|null $reviewed
     */
    private static function renderReport(array $diffs, array $commits, array $lost, ?array $reviewed, ?int $maxDiffLines, int $maxCommits): string
    {
        $security = array_filter($commits, static fn(array $c): bool => $c['security']);
        $out = [];
        $out[] = 'Weekly check of Drupal 11.x `Core/Template/Attribute*.php` against `src/`, by `scripts/check-upstream.php`.';
        $out[] = '';
        if ($security !== []) {
            $out[] = '> **High priority.** ' . count($security) . ' upstream commit(s) mention SA-CORE. Review them first.';
            $out[] = '';
        }
        if ($diffs === [] && $commits === [] && $lost === []) {
            $out[] = 'No functional difference. No upstream commit since the last review.';

            return implode("\n", $out) . "\n";
        }

        if ($lost !== []) {
            $out[] = '### Review boundary lost';
            $out[] = '';
            $out[] = 'The reviewed commit was not found in the upstream history of these files, within ' . self::MAX_PAGES . ' pages of ' . self::PER_PAGE . ' commits. The script cannot tell what changed since the last review, so it fails instead of passing.';
            $out[] = '';
            foreach ($lost as $name => $listed) {
                $out[] = "- {$name}.php: {$listed} commits read";
            }
            $out[] = '';
            $out[] = 'Read the recent history of these files by hand. Then run `--mark-reviewed` to set a new boundary.';
            $out[] = '';
        }

        if ($commits !== []) {
            $out[] = '### Upstream commits since the last review';
            $out[] = '';
            if ($reviewed !== null) {
                $out[] = "Last reviewed: `{$reviewed['commit']}` ({$reviewed['date']}), by " . self::clean($reviewed['reviewed_by'], 100) . '.';
                $out[] = '';
            }
            foreach (array_slice($commits, 0, $maxCommits) as $commit) {
                $link = $commit['web_url'] !== '' ? "[`{$commit['short_id']}`]({$commit['web_url']})" : "`{$commit['short_id']}`";
                $out[] = "- {$link} " . self::clean($commit['committed_date'], 40) . ($commit['security'] ? ' **SA-CORE**' : '') . ': ' . self::clean($commit['title']) . ' (' . implode(', ', $commit['files']) . ')';
            }
            if (count($commits) > $maxCommits) {
                $out[] = '- … and ' . (count($commits) - $maxCommits) . ' more commit(s) not listed. Run the script to see them.';
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

    /** The URL prefix that a download must start with. `--allow` replaces it (tests only). */
    private static function allowed(string $kind): string
    {
        return self::$allowOverride ?? ($kind === 'api' ? 'https://git.drupalcode.org/api/v4/' : 'https://git.drupalcode.org/');
    }

    /**
     * Reads a local file, or downloads a URL that starts with $allowedPrefix.
     * Any other scheme is refused, so a bad option cannot reach `php://`, `ftp://` or another host.
     */
    private static function fetch(string $location, string $allowedPrefix): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return self::httpGet($location, $allowedPrefix)[0];
        }
        if (preg_match('#^[a-z][a-z0-9+.-]+:#i', $location) === 1) {
            throw new UpstreamCheckError("{$location} is not allowed. Use a local directory or {$allowedPrefix}...");
        }
        $body = @file_get_contents($location);
        if ($body === false) {
            throw new UpstreamCheckError("Cannot read {$location}.");
        }

        return $body;
    }

    /**
     * @return array{string, list<string>} Body and response headers.
     */
    private static function httpGet(string $url, string $allowedPrefix): array
    {
        if ($allowedPrefix === '' || !str_starts_with($url, $allowedPrefix)) {
            throw new UpstreamCheckError("{$url} is not allowed. Use {$allowedPrefix}...");
        }

        ini_set('default_socket_timeout', '15');
        $context = stream_context_create(['http' => [
            'timeout' => 15,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'header' => "User-Agent: parisek-twig-attribute-upstream-watch\r\nAccept: */*\r\n",
        ]]);
        $status = 0;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $handle = @fopen($url, 'rb', false, $context);
            $status = 0;
            if ($handle !== false) {
                $headers = [];
                $meta = stream_get_meta_data($handle)['wrapper_data'] ?? [];
                foreach (is_array($meta) ? $meta : [] as $line) {
                    $headers[] = (string) $line;
                }
                if (isset($headers[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $m) === 1) {
                    $status = (int) $m[1];
                }
                $body = '';
                $deadline = time() + self::TOTAL_TIMEOUT;
                while (!feof($handle)) {
                    $chunk = fread($handle, 65536);
                    if ($chunk === false) {
                        break;
                    }
                    $body .= $chunk;
                    if (strlen($body) > self::MAX_DOWNLOAD_BYTES) {
                        fclose($handle);
                        throw new UpstreamCheckError("Download of {$url} is too large (limit " . self::MAX_DOWNLOAD_BYTES . ' bytes).');
                    }
                    if (time() > $deadline) {
                        fclose($handle);
                        throw new UpstreamCheckError("Download of {$url} took more than " . self::TOTAL_TIMEOUT . ' seconds.');
                    }
                }
                fclose($handle);
                if ($status === 200) {
                    return [$body, $headers];
                }
            }
            // A redirect or a client error does not heal by retry.
            if (($status >= 300 && $status < 500) && $status !== 429) {
                break;
            }
            if ($attempt < 2) {
                sleep(1);
            }
        }

        throw new UpstreamCheckError("Cannot download {$url} (HTTP status {$status}).");
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(UpstreamCheck::main($argv));
}
