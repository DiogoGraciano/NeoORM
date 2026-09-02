<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen;

/**
 * A única classe do pacote que toca disco.
 *
 * Escreve, remove órfãos e confere drift. A separação em relação ao `Generator` é o que
 * permite testar toda a geração sem sistema de arquivos, e reduz o `--check` a comparar
 * strings com o que está gravado.
 */
final class FileSystemWriter
{
    public function __construct(private readonly string $baseDirectory)
    {
    }

    /**
     * @param list<GeneratedFile> $files
     * @return WriteReport
     */
    public function write(array $files, bool $dryRun = false): WriteReport
    {
        $written = [];
        $unchanged = [];

        foreach ($files as $file) {
            $path = $this->path($file->relativePath);

            if (is_file($path) && file_get_contents($path) === $file->contents) {
                $unchanged[] = $file->relativePath;

                continue;
            }

            if (!$dryRun) {
                $this->ensureDirectory(dirname($path));

                if (file_put_contents($path, $file->contents) === false) {
                    throw new CodegenException("Não foi possível escrever {$path}.");
                }
            }

            $written[] = $file->relativePath;
        }

        [$removed, $foreign] = $this->collectOrphans($files, $dryRun);

        return new WriteReport($written, $unchanged, $removed, $foreign);
    }

    /**
     * Confere sem escrever. É o gate de CI.
     *
     * @param list<GeneratedFile> $files
     */
    public function check(array $files): DriftReport
    {
        $missing = [];
        $changed = [];

        foreach ($files as $file) {
            $path = $this->path($file->relativePath);

            if (!is_file($path)) {
                $missing[] = $file->relativePath;

                continue;
            }

            if (file_get_contents($path) !== $file->contents) {
                $changed[] = $file->relativePath;
            }
        }

        [$orphans] = $this->collectOrphans($files, dryRun: true);

        return new DriftReport($missing, $changed, $orphans);
    }

    /**
     * Arquivos gerados que não estão mais na lista.
     *
     * Apaga **apenas** o que carrega o marcador. Um arquivo escrito à mão dentro do
     * diretório é reportado e preservado: o gerador é dono do que ele produziu, não do
     * diretório.
     *
     * @param list<GeneratedFile> $files
     * @return array{0:list<string>,1:list<string>} removidos, e alheios encontrados
     */
    private function collectOrphans(array $files, bool $dryRun): array
    {
        if (!is_dir($this->baseDirectory)) {
            return [[], []];
        }

        $expected = array_map(
            fn (GeneratedFile $file): string => $this->path($file->relativePath),
            $files,
        );

        $removed = [];
        $foreign = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->baseDirectory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }

            $path = $entry->getPathname();

            if (in_array($path, $expected, true)) {
                continue;
            }

            $relative = substr($path, strlen($this->baseDirectory) + 1);

            if (!str_contains((string) file_get_contents($path), GeneratedFile::MARKER)) {
                $foreign[] = $relative;

                continue;
            }

            if (!$dryRun) {
                unlink($path);
            }

            $removed[] = $relative;
        }

        sort($removed, SORT_STRING);
        sort($foreign, SORT_STRING);

        return [$removed, $foreign];
    }

    private function path(string $relative): string
    {
        return rtrim($this->baseDirectory, '/\\') . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new CodegenException("Não foi possível criar {$directory}.");
        }
    }
}
