<?php
declare(strict_types=1);

namespace tk\weslie\SgiCreator\Template;

/**
 * One template = one docker-compose file in the templates folder.
 *
 * Immutable value object holding the catalogue metadata parsed from the file's
 * leading "#Key = Value" comment header, plus the resolved image and the raw
 * compose text used at create time.
 */
final class Template
{
    /** @param list<string> $tags */
    public function __construct(
        public readonly string $id,          // filename without extension (unique)
        public readonly string $file,        // basename on disk
        public readonly bool   $enabled,     // #SGI = true → shown & creatable
        public readonly string $name,
        public readonly string $game,
        public readonly string $version,
        public readonly string $description,
        public readonly string $image,
        public readonly string $icon,
        public readonly array  $tags,
        public readonly string $raw,         // full compose text (with %%...%% placeholders)
    ) {}

    /**
     * The shape GET /api/templates returns per entry. Optional fields (icon,
     * tags) are only included when present, matching the frontend contract.
     *
     * @return array<string,mixed>
     */
    public function toCatalogueEntry(): array
    {
        $entry = [
            'id'          => $this->id,
            'name'        => $this->name,
            'game'        => $this->game,
            'version'     => $this->version,
            'description' => $this->description,
            'image'       => $this->image,
        ];
        if ($this->icon !== '') {
            $entry['icon'] = $this->icon;
        }
        if ($this->tags !== []) {
            $entry['tags'] = $this->tags;
        }
        return $entry;
    }
}
