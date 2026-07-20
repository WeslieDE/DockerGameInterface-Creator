<?php
declare(strict_types=1);

namespace tk\weslie\SgiCreator\Template;

use tk\weslie\SgiCreator\Http\HttpException;

/**
 * The stateless catalogue: everything comes from a directory listing of the
 * templates folder at request time — no database, no cache.
 *
 * Each *.yml / *.yaml file is one template. Its catalogue metadata lives in the
 * leading comment block as "#Key = Value" lines, e.g.:
 *
 *     #SGI = true
 *     #Name = Minecraft Java — Paper
 *     #Game = Minecraft
 *     #Version = 1.21.4
 *     #Description = High-performance Spigot fork
 *     #Icon = 📄
 *     #Tags = plugins, spigot
 *     #Image = itzg/minecraft-server        # optional; else read from compose
 *
 * A file WITHOUT "#SGI = true" is not an SGI template: it is never listed by
 * GET /api/templates and never creatable. This lets the folder also hold plain
 * compose files that SGI-Creator must ignore.
 */
final class TemplateRepository
{
    private const EXTENSIONS = ['yml', 'yaml'];

    public function __construct(
        private readonly string $dir,
    ) {}

    /**
     * The catalogue for GET /api/templates: enabled templates only, sorted by
     * game then name so the frontend can group consecutive entries by game.
     *
     * @return list<Template>
     */
    public function catalogue(): array
    {
        $all = array_filter($this->all(), static fn(Template $t) => $t->enabled);
        $all = array_values($all);
        usort($all, static function (Template $a, Template $b): int {
            return strcasecmp($a->game, $b->game) ?: strcasecmp($a->name, $b->name);
        });
        return $all;
    }

    /**
     * Find an enabled template by id (its filename without extension). A hidden
     * or unknown id is reported as gone — the API only ever acts on listed ones.
     *
     * @throws HttpException 404
     */
    public function require(string $id): Template
    {
        foreach ($this->all() as $t) {
            if ($t->id === $id && $t->enabled) {
                return $t;
            }
        }
        throw new HttpException(404, 'The selected template no longer exists.', 'TEMPLATE_GONE');
    }

    /* ---------------------------------------------------------------- */

    /**
     * Read and parse every template file in the folder.
     *
     * @return list<Template>
     */
    private function all(): array
    {
        if (!is_dir($this->dir)) {
            throw new HttpException(
                500,
                'The templates folder is missing on the server.',
                'NO_TEMPLATE_DIR'
            );
        }
        $out = [];
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($ext, self::EXTENSIONS, true)) {
                continue;
            }
            $path = $this->dir . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $out[] = $this->build($entry, $raw);
        }
        return $out;
    }

    private function build(string $file, string $raw): Template
    {
        $id   = pathinfo($file, PATHINFO_FILENAME);
        $meta = $this->parseHeader($raw);

        $image = $meta['image'] ?? '';
        if ($image === '') {
            $image = $this->imageFromCompose($raw);
        }

        return new Template(
            id:          $id,
            file:        $file,
            enabled:     $this->truthy($meta['sgi'] ?? ''),
            name:        $meta['name'] !== '' ? $meta['name'] : $id,
            game:        $meta['game'] !== '' ? $meta['game'] : 'Other',
            version:     $meta['version'] ?? '',
            description: $meta['description'] ?? '',
            image:       $image,
            icon:        $meta['icon'] ?? '',
            tags:        $this->splitTags($meta['tags'] ?? ''),
            raw:         $raw,
        );
    }

    /**
     * Parse the leading contiguous comment block into a lower-cased key map.
     * Scanning stops at the first line that is neither blank nor a "#..." comment
     * (i.e. the start of the YAML body).
     *
     * @return array<string,string>
     */
    private function parseHeader(string $raw): array
    {
        $meta = [
            'sgi' => '', 'name' => '', 'game' => '', 'version' => '',
            'description' => '', 'image' => '', 'icon' => '', 'tags' => '',
        ];
        // Normalise line endings, then walk the header.
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;                       // blank lines inside the header are fine
            }
            if ($trim[0] !== '#') {
                break;                          // reached the YAML body
            }
            // "#Key = Value" / "#Key: Value" / "# Key=Value"
            if (preg_match('/^#\s*([A-Za-z0-9._-]+)\s*[:=]\s*(.*)$/', $trim, $m)) {
                $key = strtolower($m[1]);
                // Only keep the keys we understand; ignore other comments.
                if (array_key_exists($key, $meta)) {
                    $meta[$key] = trim($m[2]);
                }
            }
        }
        return $meta;
    }

    /** First `image:` value in the compose body — a lightweight fallback for #Image. */
    private function imageFromCompose(string $raw): string
    {
        if (preg_match('/^\s*image:\s*["\']?([^"\'\r\n#]+?)["\']?\s*(?:#.*)?$/m', $raw, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /** @return list<string> */
    private function splitTags(string $tags): array
    {
        if (trim($tags) === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $tags));
        return array_values(array_filter($parts, static fn($t) => $t !== ''));
    }

    private function truthy(string $v): bool
    {
        return in_array(strtolower(trim($v)), ['true', '1', 'yes', 'on'], true);
    }
}
