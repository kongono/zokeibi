<?php

declare(strict_types=1);

namespace Zokeibi\Audit;

use FilesystemIterator;
use Zokeibi\Registry\ComponentRegistry;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Static UI Schema, Strict-CSP, and Accessibility Auditor for Zokeibi templates.
 *
 * Emits plain-English What / Why / Where / How-to-Fix guidance with Levenshtein "Did you mean?"
 * suggestions for both human developers and AI coding agents.
 */
final readonly class UiAuditor
{
    /** @var list<string> */
    private const array IGNORED_DIRS = [
        'vendor',
        'node_modules',
        '.git',
        'var',
        'storage',
        'dist',
        '.cache',
    ];

    /** @var array<string, list<string>> */
    private const array COLOR_ALIAS_HINTS = [
        'green' => ['emerald', 'success'],
        'red' => ['danger', 'crimson'],
        'yellow' => ['amber', 'warning'],
        'orange' => ['amber', 'warning'],
        'cyan' => ['blue', 'info'],
        'brand' => ['primary', 'accent'],
    ];

    public function __construct(
        private ComponentRegistry $registry,
    ) {}

    public function auditDirectory(string $viewsDir): AuditReport
    {
        $findings = [];
        $usage = [];
        $scannedFiles = 0;

        if (is_dir($viewsDir)) {
            $dirIterator = new RecursiveDirectoryIterator($viewsDir, FilesystemIterator::SKIP_DOTS);
            $filterIterator = new RecursiveCallbackFilterIterator(
                $dirIterator,
                static function (SplFileInfo $current): bool {
                    if ($current->isLink()) {
                        return false;
                    }
                    if ($current->isDir()) {
                        return !in_array(strtolower($current->getFilename()), self::IGNORED_DIRS, true);
                    }

                    return true;
                },
            );
            $iterator = new RecursiveIteratorIterator($filterIterator);

            $baseNorm = rtrim(str_replace('\\', '/', $viewsDir), '/') . '/';

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                if (!str_ends_with($path, '.kgn.html') && !str_ends_with($path, '.html')) {
                    continue;
                }

                $contents = file_get_contents($path);
                if (false === $contents) {
                    continue;
                }

                $scannedFiles++;
                $normPath = str_replace('\\', '/', $path);
                $label = str_starts_with($normPath, $baseNorm)
                    ? substr($normPath, strlen($baseNorm))
                    : basename($path);

                $this->scanSource($contents, $label, $findings, $usage);
            }
        }

        return $this->finalizeReport($scannedFiles, $findings, $usage);
    }

    public function auditSource(string $source, string $fileLabel = 'template.kgn.html'): AuditReport
    {
        $findings = [];
        $usage = [];
        $this->scanSource($source, $fileLabel, $findings, $usage);

        return $this->finalizeReport(1, $findings, $usage);
    }

    /**
     * @param list<AuditFinding> $findings
     * @param array<string, int> $usage
     */
    private function scanSource(string $source, string $fileLabel, array &$findings, array &$usage): void
    {
        // Strip template comments {{-- ... --}} preserving line numbers
        $clean = preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $source,
        ) ?? $source;

        // 1. Validate <x-ui.*> component tags & static enum props
        if (preg_match_all('/<x-ui\.([a-zA-Z0-9-]+)((?:\s+(?:"[^"]*"|\'[^\']*\'|[^<>\/"\'])*)?)\s*\/?>/s', $clean, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $match) {
                $compId = strtolower($match[1][0]);
                $offset = $match[0][1];
                $line = $this->lineNumber($clean, $offset);

                if (!$this->registry->has($compId)) {
                    $similar = $this->suggestClosest($compId, $this->registry->ids());
                    $didYouMeanTags = array_map(static fn (string $id): string => '<x-ui.' . $id . '>', $similar);
                    $hint = [] !== $didYouMeanTags
                        ? sprintf('Replace `<x-ui.%s>` with `%s`, or run `php vendor/bin/zokeibi list` to see all %d components.', $compId, $didYouMeanTags[0], $this->registry->count())
                        : 'Run `php vendor/bin/zokeibi list` to view valid registered components, or install custom components under `views/components/`.';

                    $findings[] = new AuditFinding(
                        rule: 'unknown-component',
                        severity: 'error',
                        file: $fileLabel,
                        line: $line,
                        message: sprintf('Unknown Zokeibi component `<x-ui.%s>`.', $compId),
                        hint: $hint,
                        why: sprintf('Zokeibi has no registered primitive named "%s" in `registry.json`, so rendering this page will fail with Template Not Found.', $compId),
                        didYouMean: $didYouMeanTags,
                    );
                    continue;
                }

                $usage[$compId] = ($usage[$compId] ?? 0) + 1;
                $schema = $this->registry->get($compId);
                $attrString = $match[2][0];

                if (preg_match_all('/(:?)([a-zA-Z_][a-zA-Z0-9_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $attrString, $attrMatches, PREG_SET_ORDER) > 0) {
                    foreach ($attrMatches as $attr) {
                        $isDynamic = ':' === $attr[1];
                        $propName = strtolower($attr[2]);
                        $doubleVal = $attr[3] ?? '';
                        $propVal = '' !== $doubleVal ? $doubleVal : ($attr[4] ?? '');

                        if ($isDynamic || str_contains($propVal, '{{')) {
                            continue;
                        }

                        $allowed = $schema->enumValues($propName);
                        if (null !== $allowed && !in_array($propVal, $allowed, true)) {
                            $similarVals = $this->suggestClosestEnum($propVal, $allowed);
                            $hint = [] !== $similarVals
                                ? sprintf('Change `%s="%s"` to `%s="%s"`. Allowed values: %s.', $propName, $propVal, $propName, $similarVals[0], implode(', ', $allowed))
                                : sprintf('Set `%s` to one of: %s.', $propName, implode(', ', $allowed));

                            $findings[] = new AuditFinding(
                                rule: 'invalid-prop-enum',
                                severity: 'error',
                                file: $fileLabel,
                                line: $line,
                                message: sprintf(
                                    'Invalid value "%s" for prop "%s" on `<x-ui.%s>`.',
                                    $propVal,
                                    $propName,
                                    $compId,
                                ),
                                hint: $hint,
                                why: sprintf('`<x-ui.%s>` only styles the registered `%s` values (%s), so "%s" would silently fall back to default styling.', $compId, $propName, implode(', ', $allowed), $propVal),
                                didYouMean: $similarVals,
                            );
                        }
                    }
                }
            }
        }

        // 2. Strict CSP: forbid inline <script> blocks (without real src="...", ignoring data-src=)
        if (preg_match_all('/<script\b([^>]*)>/i', $clean, $scriptMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($scriptMatches as $match) {
                $attrs = $match[1][0];
                if (1 !== preg_match('/(?<![a-zA-Z0-9:-])src\s*=/i', $attrs)) {
                    $findings[] = new AuditFinding(
                        rule: 'csp-inline-script',
                        severity: 'error',
                        file: $fileLabel,
                        line: $this->lineNumber($clean, $match[0][1]),
                        message: 'Inline `<script>` block violates strict Content-Security-Policy.',
                        hint: 'Move this code to `public/assets/app.js` and load it with `<script src="/assets/app.js" defer></script>`, or use Kongono Live 2.5 declarative directives.',
                        why: 'Kongono enforces `script-src \'self\'` to block XSS injection; browsers will refuse to execute inline `<script>` tags.',
                    );
                }
            }
        }

        // 3. Strict CSP & URL safety inside HTML opening tags (forbid any on*= handler, inline style=, and javascript:/vbscript:/data:text/html URLs)
        if (preg_match_all('/<[a-zA-Z][a-zA-Z0-9.:_-]*\b((?:\s+(?:"[^"]*"|\'[^\']*\'|[^<>\/"\'])*)?)\s*\/?>/s', $clean, $tagMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($tagMatches as $tagMatch) {
                $attrs = $tagMatch[1][0];
                $attrsOffset = $tagMatch[1][1];

                if (preg_match_all('/(?<![a-zA-Z0-9:-])(on[a-z]{3,24})\s*=/i', $attrs, $handlerMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                    foreach ($handlerMatches as $match) {
                        $handler = strtolower($match[1][0]);
                        $event = substr($handler, 2);
                        $findings[] = new AuditFinding(
                            rule: 'csp-inline-handler',
                            severity: 'error',
                            file: $fileLabel,
                            line: $this->lineNumber($clean, $attrsOffset + $match[0][1]),
                            message: sprintf('Inline event handler `%s=` violates strict Content-Security-Policy.', $handler),
                            hint: sprintf('Replace `%s="..."` with Kongono Live 2.5 `k-on:%s="set(open, true)"` (or `data-z-open="#id"` with `zokeibi-runtime.js`).', $handler, $event),
                            why: 'Browsers block inline HTML `on*=` attributes whenever `script-src \'self\'` is active.',
                        );
                    }
                }

                if (preg_match_all('/(?<![a-zA-Z0-9:-])style\s*=\s*(?:["\']|[^\s>]+)/i', $attrs, $styleMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                    foreach ($styleMatches as $match) {
                        $findings[] = new AuditFinding(
                            rule: 'csp-inline-style',
                            severity: 'warning',
                            file: $fileLabel,
                            line: $this->lineNumber($clean, $attrsOffset + $match[0][1]),
                            message: 'Inline `style="..."` attribute breaks strict `style-src \'self\'` CSP.',
                            hint: 'Move this rule into your stylesheet (`public/assets/app.css`) or use a Zokeibi utility class (`.z-row`, `.z-stack`, `.z-grid-2`).',
                            why: 'Zokeibi ships zero inline styles so apps can enable `style-src \'self\'` without `\'unsafe-inline\'`.',
                        );
                    }
                }

                if (preg_match_all('/(?<![a-zA-Z0-9:-])(href|src|action|formaction|kgn-get|kgn-post)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attrs, $urlMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                    foreach ($urlMatches as $match) {
                        $attrName = strtolower($match[1][0]);
                        $doubleRaw = isset($match[2]) ? $match[2][0] : '';
                        $rawUrl = '' !== $doubleRaw ? $doubleRaw : (isset($match[3]) ? $match[3][0] : '');
                        $normalizedUrl = strtolower(trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/', '', $rawUrl)));
                        if (
                            str_starts_with($normalizedUrl, 'javascript:')
                            || str_starts_with($normalizedUrl, 'vbscript:')
                            || str_starts_with($normalizedUrl, 'data:text/html')
                        ) {
                            $findings[] = new AuditFinding(
                                rule: 'unsafe-url-scheme',
                                severity: 'error',
                                file: $fileLabel,
                                line: $this->lineNumber($clean, $attrsOffset + $match[0][1]),
                                message: sprintf('Unsafe URL scheme in `%s="..."` risks XSS execution.', $attrName),
                                hint: sprintf('Use a relative path (`/path`), fragment (`#id`), or `https://` URL in `%s`.', $attrName),
                                why: 'Executable URI schemes (`javascript:`, `vbscript:`, `data:text/html`) bypass HTML entity escaping and execute script on click.',
                            );
                        }
                    }
                }
            }
        }

        // 5. Accessibility: empty <button> or <x-ui.button> without aria-label/aria-labelledby/title
        if (preg_match_all('/<(button|x-ui\.button)\b([^>]*)>\s*<\/\1>/i', $clean, $btnMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($btnMatches as $match) {
                $attrs = $match[2][0];
                if (1 !== preg_match('/\b(?:aria-label|aria-labelledby|title)\s*=/i', $attrs)) {
                    $tag = strtolower($match[1][0]);
                    $findings[] = new AuditFinding(
                        rule: 'a11y-empty-button',
                        severity: 'warning',
                        file: $fileLabel,
                        line: $this->lineNumber($clean, $match[0][1]),
                        message: sprintf('Empty `<%s>` has no visible label or `aria-label`.', $tag),
                        hint: sprintf('Add visible text inside `<%s>Save</%s>` or add `aria-label="Close"` for icon-only buttons.', $tag, $tag),
                        why: 'Screen readers announce unlabeled buttons simply as "button", making the action unusable for assistive technology users.',
                    );
                }
            }
        }
    }

    /**
     * @param list<string> $candidates
     * @return list<string>
     */
    private function suggestClosest(string $needle, array $candidates): array
    {
        $lowerNeedle = strtolower($needle);
        if (strlen($lowerNeedle) > 64) {
            return [];
        }
        $scored = [];
        foreach ($candidates as $cand) {
            $dist = levenshtein($lowerNeedle, strtolower($cand));
            if ($dist <= max(2, (int) floor(strlen($lowerNeedle) / 2))) {
                $scored[$cand] = $dist;
            }
        }
        asort($scored);

        return array_slice(array_keys($scored), 0, 2);
    }

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
    private function suggestClosestEnum(string $value, array $allowed): array
    {
        $lower = strtolower($value);
        if (isset(self::COLOR_ALIAS_HINTS[$lower])) {
            foreach (self::COLOR_ALIAS_HINTS[$lower] as $aliasTarget) {
                if (in_array($aliasTarget, $allowed, true)) {
                    return [$aliasTarget];
                }
            }
        }

        return $this->suggestClosest($value, $allowed);
    }

    /**
     * @param list<AuditFinding> $findings
     * @param array<string, int> $usage
     */
    private function finalizeReport(int $scannedFiles, array $findings, array $usage): AuditReport
    {
        ksort($usage);

        $errors = 0;
        $warnings = 0;
        $penalty = 0;

        foreach ($findings as $finding) {
            if ('error' === $finding->severity) {
                $errors++;
                $penalty += match ($finding->rule) {
                    'csp-inline-script', 'unsafe-url-scheme' => 20,
                    'unknown-component', 'csp-inline-handler' => 15,
                    default => 10,
                };
            } else {
                $warnings++;
                $penalty += 5;
            }
        }

        $score = max(0, 100 - $penalty);

        return new AuditReport(
            score: $score,
            passed: 0 === $errors,
            scannedFiles: $scannedFiles,
            errorCount: $errors,
            warningCount: $warnings,
            componentUsage: $usage,
            findings: $findings,
        );
    }

    private function lineNumber(string $source, int $byteOffset): int
    {
        return substr_count(substr($source, 0, $byteOffset), "\n") + 1;
    }
}
